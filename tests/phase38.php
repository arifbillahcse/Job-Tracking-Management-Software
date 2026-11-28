<?php

declare(strict_types=1);

/**
 * v1.20.0 (old data import): the old application's Export (ZIP / CSV) and its database are read, a dry run writes nothing, and an import
 * brings in companies, drivers and invoices with the same numbers, once. Included by tests/run.php (after phase37.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Services\CompanyService;
use App\Services\DriverService;
use App\Services\InvoiceService;
use App\Services\OldData\OldDataImporter;
use App\Services\OldData\OldDataReader;

section('Old data import: the export (CSV)');
fresh_db();
fresh_session();
Clock::fake('2026-11-28');
$admin = make_user('Admin', 'p38-admin@example.com');
sign_in($admin);

$dir = sys_get_temp_dir() . '/jt-old-' . bin2hex(random_bytes(4));
mkdir($dir, 0775, true);
$files = [
    'companies.csv' => "ID,Name,Address,City,Phone,DOT #,MC #\r\n1,Prime,12 Main St,Atlanta GA,555-0100,2521000,MC-1\r\n2,Next Level,,,,,\r\n3,\"Bad <Name>\",,,,,\r\n4,Bad Phone Co,1 St,City,call me,123,\r\n",
    'drivers.csv' => "ID,First Name,Last Name,Phone,License #\r\n1,Joe,Smith,973-718-0688,GA-1\r\n2,Dan,Miller,,\r\n",
    'driver-invoices.csv' => "Invoice #,Driver,Date,Job #,Company,Customer,From,To,CF,Rate,Job Total,Bal Due,New Bal,Remarks,Subtotal,Carrier Fee (10%),Invoice Total\r\n"
        . "DI-295,Joe Smith,11-13-2026,419714,Prime,Cust One,CO,TX,900,2.5000,\"2,250.00\",\"2,275.74\",0.00,fragile,\"2,775.74\",\"2,550.00\",200.74\r\n"
        . "DI-295,,,419715,Next Level,Cust Two,NC,SC,100,3.0000,300.00,500.00,10.00,,,,\r\n"
        . "DI-300,Dan Miller,11-20-2026,,,,,,,,,,,,10.00,0.00,10.00\r\n",
    'company-invoices.csv' => "Invoice #,Company,Date,Job #,Driver,Customer,From,To,CF,Rate,Job Total,Bal Due,New Bal,Remarks,Subtotal,Carrier Fee (10%),Invoice Total\r\n"
        . "CI-7,Prime,11-13-2026,419714,Joe Smith,Cust One,CO,TX,900,2.5000,\"2,250.00\",\"2,275.74\",0.00,,\"2,275.74\",\"2,250.00\",25.74\r\n"
        . "CI-8,Next Level,13-45-2026,419715,Joe Smith,Cust Two,NC,SC,100,3.1250,312.50,500.00,0.00,,500.00,312.50,187.50\r\n",
];
foreach ($files as $name => $text) {
    file_put_contents($dir . '/' . $name, $text);
}
$job = mk_job(['job_number' => '419714', 'status' => 'in_transit', 'carrier_name' => 'KEN', 'driver_name' => 'Joe Smith', 'cf' => '900', 'carrier_rate' => '2.50', 'balance' => '2275.74', 'loaded_date' => '2026-11-05']);
$counts = static fn (): array => [
    (int) Database::fetchValue('SELECT COUNT(*) FROM companies'), (int) Database::fetchValue('SELECT COUNT(*) FROM drivers'),
    (int) Database::fetchValue('SELECT COUNT(*) FROM invoices'), (int) Database::fetchValue('SELECT COUNT(*) FROM invoice_lines'),
];

$data = OldDataReader::fromCsv($dir);
check(count($data['companies']) === 4 && count($data['drivers']) === 2 && count($data['invoices']) === 4 && $data['exact'] === false, 'the four files are read: 4 companies, 2 drivers, 4 invoices');
$di = $data['invoices'][0];
check($di['type'] === 'DI' && $di['seq'] === 295 && $di['party'] === 'Joe Smith' && $di['date'] === '2026-11-13' && count($di['lines']) === 2, 'an invoice is rebuilt from its rows: number, driver, date (MM-DD-YYYY read right) and its two lines');

// dry run
$r = OldDataImporter::run($data, false);
check($counts() === [0, 0, 0, 0] && $r['applied'] === false, 'a dry run writes nothing');
check($r['companies'] === ['new' => 3, 'existing' => 0, 'skipped' => 1] && $r['drivers'] === ['new' => 2, 'existing' => 0, 'skipped' => 0] && $r['DI']['new'] === 2 && $r['CI']['new'] === 2 && $r['lines'] === 4, 'it reports what would come in (3 companies, 2 drivers, 2 DI, 2 CI, 4 lines)');
check($r['linked'] === 2 && $r['unlinked'] === 2, 'job 419714 (on DI-295 and CI-7) is linked to the job with that number, 419715 is not');
$warn = implode("\n", $r['warnings']);
check(str_contains($warn, 'Bad <Name>') && str_contains($warn, 'Bad Phone Co') && str_contains($warn, 'DI-300 has no jobs') && str_contains($warn, 'CI-8 has no valid date') && str_contains($warn, 'more than 2 decimals'), 'the report warns about an invalid name, an invalid phone, an empty invoice, a bad date and a 4-decimal rate');
check(str_contains($warn, 'Paid / adjustment') === false && $r['mismatch'] === 0, 'with the sums from the jobs matching the old totals there is no mismatch');

// import
$r = OldDataImporter::run($data, true);
check($r['applied'] === true && $counts() === [3, 2, 4, 4], 'the import writes 3 companies, 2 drivers, 4 invoices (DI-295, DI-300, CI-7, CI-8) and 4 lines');
$d295 = Database::fetchOne("SELECT * FROM invoices WHERE type = 'DI' AND seq = 295");
check($d295 !== null && $d295['driver'] === 'Joe Smith' && $d295['invoice_date'] === '2026-11-13' && $d295['driver_license'] === 'GA-1' && $d295['driver_phone'] === '973-718-0688', 'DI-295 keeps its number, its driver and date, and takes the driver\'s licence and phone from the imported driver');
// subtotal 2775.74, fee 2250 + 300 = 2550, old total 200.74 -> adjustment -25.00
check((float) $d295['subtotal'] === 2775.74 && (float) $d295['carrier_fee'] === 2550.0 && (float) $d295['paid_amount'] === -25.0 && (float) $d295['labor'] === 0.0 && (float) $d295['total'] === 200.74 && (float) $d295['total_cf'] === 1000.0, 'the export has no Labor / Pads / Paid, so they come in as one Paid / adjustment (-25.00) and the total stays 200.74');
check(str_contains((string) $d295['remarks'], 'Paid / adjustment -25.00'), 'a note on the invoice says so');
$l = InvoiceService::lines((int) $d295['id']);
check(count($l) === 2 && (int) $l[0]['job_id'] === $job && $l[1]['job_id'] === null && (float) $l[0]['fee'] === 2250.0 && (float) $l[1]['fee'] === 300.0 && (float) $l[1]['rate'] === 3.0 && $l[0]['broker'] === 'Prime' && $l[0]['driver'] === 'Joe Smith', 'the lines keep job, rate, CF Pay, company and driver, and the first is linked to its job');
check(str_contains((string) $l[1]['remarks'], '[Original Bal. 10.00]') && $l[0]['remarks'] === 'fragile', 'an Original Bal. is kept in the line\'s remarks, other remarks stay');
$ci7 = Database::fetchOne("SELECT * FROM invoices WHERE type = 'CI' AND seq = 7");
check($ci7 !== null && $ci7['broker'] === 'Prime' && str_contains((string) $ci7['company_info'], '2521000') && (float) $ci7['total'] === 25.74 && InvoiceService::lines((int) $ci7['id'])[0]['driver'] === 'Joe Smith', 'CI-7 keeps its number, its company (with the imported company\'s details) and the driver of each line');
$d300 = Database::fetchOne("SELECT * FROM invoices WHERE type = 'DI' AND seq = 300");
check($d300 !== null && (float) $d300['subtotal'] === 10.0 && (float) $d300['total'] === 10.0 && InvoiceService::lines((int) $d300['id']) === [], 'an invoice with no jobs comes in with its totals only');
$ci8 = Database::fetchOne("SELECT * FROM invoices WHERE type = 'CI' AND seq = 8");
check($ci8['invoice_date'] === '2026-11-28' && (float) $ci8['carrier_fee'] === 312.5 && (float) InvoiceService::lines((int) $ci8['id'])[0]['rate'] === 3.13, 'a bad date falls back to today; a 4-decimal rate keeps its exact CF Pay (3.125 x 100 = 312.50) and is shown rounded');
check(CompanyService::findByName('prime')['dot_number'] === '2521000' && CompanyService::findByName('Bad Phone Co')['phone'] === null && CompanyService::findByName('Bad <Name>') === null && DriverService::findByName('joe smith')['license'] === 'GA-1', 'companies and drivers are in; the invalid phone was left out, the invalid name was skipped');
check(Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'old_data_imported'") == 1, 'the import is audited');

// the old numbers are kept, new ones continue after them
call('POST', '/invoices', ['carrier' => 'KEN', 'include_invoiced' => '1']);
check(Database::fetchOne("SELECT * FROM invoices WHERE type = 'DI' AND seq = 301") !== null, 'the next new driver invoice is DI-301 (after the highest old number)');
$st = plain(call('GET', '/jobs/statement', [], ['go' => '1', 'carrier' => 'KEN'])->body());
check(str_contains($st, 'On DI-295') && str_contains($st, 'CI-7'), 'the linked job shows "On DI-295" on the statement, so it is not billed again by accident');

// again: nothing twice
$r2 = OldDataImporter::run($data, true);
check($counts()[2] === 5 && $r2['DI'] === ['new' => 0, 'existing' => 2] && $r2['CI'] === ['new' => 0, 'existing' => 2] && $r2['companies']['new'] === 0 && $r2['drivers']['new'] === 0, 'running it again imports nothing twice (companies, drivers and invoices already there are skipped)');

section('Old data import: the ZIP, and a missing file');
fresh_db();
fresh_session();
Clock::fake('2026-11-28');
$admin = make_user('Admin', 'p38b-admin@example.com');
sign_in($admin);
$zipPath = $dir . '/export.zip';
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
foreach ($files as $name => $text) {
    $zip->addFromString($name, $text);
}
$zip->close();
$z = OldDataImporter::run(OldDataReader::fromCsv($zipPath), true);
check($z['applied'] === true && $counts()[2] === 4 && $z['DI']['new'] === 2, 'the old application\'s export ZIP is read and imported the same way');
check(OldDataImporter::run(OldDataReader::fromCsv($dir . '/nope.zip'), true)['errors'] !== [] && OldDataImporter::run(OldDataReader::fromCsv(sys_get_temp_dir() . '/jt-empty-' . bin2hex(random_bytes(3))), true)['errors'] !== [], 'a file or folder that is not there is an error and writes nothing');
file_put_contents($dir . '/notzip.zip', 'this is not a zip');
check(OldDataImporter::run(OldDataReader::fromCsv($dir . '/notzip.zip'), true)['errors'] !== [], 'a file that is not a ZIP is an error');

section('Old data import: the old database');
fresh_db();
fresh_session();
Clock::fake('2026-11-28');
$admin = make_user('Admin', 'p38c-admin@example.com');
sign_in($admin);
$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
foreach ([
    'CREATE TABLE companies (id INTEGER PRIMARY KEY, name TEXT, address TEXT, city TEXT, phone TEXT, dot_number TEXT, mc_number TEXT, created_at TEXT)',
    'CREATE TABLE drivers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, phone TEXT, license TEXT, created_at TEXT)',
    'CREATE TABLE driver_invoices (id INTEGER PRIMARY KEY, driver_id INTEGER, date TEXT, subtotal REAL, carrier_fee REAL, labor_cost REAL, pads REAL, paid REAL, paid_date TEXT, invoice_remarks TEXT, total REAL, created_at TEXT)',
    'CREATE TABLE driver_invoice_items (id INTEGER PRIMARY KEY, invoice_id INTEGER, sort_order INTEGER, job_number TEXT, company_id INTEGER, customer_name TEXT, phone TEXT, from_location TEXT, to_location TEXT, cubic_feet REAL, rate REAL, balance_due REAL, new_balance REAL, remarks TEXT)',
    'CREATE TABLE company_invoices (id INTEGER PRIMARY KEY, company_id INTEGER, driver_invoice_id INTEGER, date TEXT, subtotal REAL, carrier_fee REAL, labor_cost REAL, pads REAL, paid REAL, paid_date TEXT, invoice_remarks TEXT, total REAL, created_at TEXT)',
    'CREATE TABLE company_invoice_items (id INTEGER PRIMARY KEY, invoice_id INTEGER, sort_order INTEGER, job_number TEXT, driver_id INTEGER, customer_name TEXT, phone TEXT, from_location TEXT, to_location TEXT, cubic_feet REAL, rate REAL, balance_due REAL, new_balance REAL, remarks TEXT)',
    "INSERT INTO companies VALUES (1, 'Prime', '12 Main St', 'Atlanta GA', '555-0100', '2521000', 'MC-1', '2026-01-01 00:00:00')",
    "INSERT INTO drivers VALUES (1, 'Joe', 'Smith', '973-718-0688', 'GA-1', '2026-01-01 00:00:00')",
    "INSERT INTO driver_invoices VALUES (295, 1, '2026-11-13', 2775.74, 2550.00, 10, 5, -3, '2026-11-15', 'Week 46', 207.74, '2026-11-13 09:30:00')",
    "INSERT INTO driver_invoice_items VALUES (1, 295, 1, '419714', 1, 'Cust One', '555-0111', 'CO', 'TX', 900, 2.5, 2275.74, 0, 'fragile')",
    "INSERT INTO driver_invoice_items VALUES (2, 295, 2, '419715', 1, 'Cust Two', NULL, 'NC', 'SC', 100, 3, 500, 0, '')",
    "INSERT INTO company_invoices VALUES (9, 1, 295, '2026-11-14', 2275.74, 2250.00, 0, 0, 0, NULL, '', 25.74, '2026-11-14 10:00:00')",
    "INSERT INTO company_invoice_items VALUES (1, 9, 1, '419714', 1, 'Cust One', '555-0111', 'CO', 'TX', 900, 2.5, 2275.74, 0, '')",
] as $sql) {
    $old->exec($sql);
}
$fromDb = OldDataReader::fromDatabase($old);
check($fromDb['exact'] === true && count($fromDb['invoices']) === 2 && $fromDb['invoices'][0]['labor'] === '10' && $fromDb['invoices'][0]['paid_date'] === '2026-11-15', 'the old database is read, with the fields the export lacks');
$rd = OldDataImporter::run($fromDb, true);
$di = Database::fetchOne("SELECT * FROM invoices WHERE type = 'DI' AND seq = 295");
check($rd['applied'] === true && (float) $di['labor'] === 10.0 && (float) $di['pads'] === 5.0 && (float) $di['paid_amount'] === -3.0 && $di['paid_on'] === '2026-11-15' && $di['remarks'] === 'Week 46' && $di['created_at'] === '2026-11-13 09:30:00', 'Labor, Pads, Paid, the paid date, the remarks and the creation time are kept');
check((float) $di['total'] === 207.74 && $rd['mismatch'] === 0 && InvoiceService::status($di) === 'paid', 'the total is worked out again (2775.74 - 2550 - 10 - 5 - 3 = 207.74) and agrees with the old one; an invoice with a paid date comes in as paid');
check(InvoiceService::lines((int) $di['id'])[0]['phone'] === '555-0111', 'the customer phone of each line is kept');
$ci = Database::fetchOne("SELECT * FROM invoices WHERE type = 'CI' AND seq = 9");
check($ci !== null && (int) $ci['source_invoice_id'] === (int) $di['id'], 'a company invoice made from a driver invoice stays linked to it (CI-9 from DI-295)');
check(!str_contains(implode("\n", $rd['warnings']), 'Paid / adjustment'), 'there is no combined-adjustment note (the old database has the three amounts)');
// a total that does not agree is reported
$old->exec('UPDATE driver_invoices SET total = 999 WHERE id = 295');
$again = OldDataImporter::run(OldDataReader::fromDatabase($old), false);
check($again['DI']['existing'] === 1, 'running it again skips the invoice that is already there');
fresh_db();
fresh_session();
$admin = make_user('Admin', 'p38d-admin@example.com');
sign_in($admin);
$bad = OldDataImporter::run(OldDataReader::fromDatabase($old), false);
check($bad['mismatch'] === 1 && str_contains(implode("\n", $bad['warnings']), 'DI-295: the total is 207.74 here and was 999.00'), 'a total that differs from the old one is reported');
$broken = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
check(OldDataReader::fromDatabase($broken)['problems'] !== [], 'a database that is not the old application\'s is reported');

foreach (glob($dir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);
