<?php

declare(strict_types=1);

/**
 * v1.21.0 (dashboard and export): invoice figures on the dashboard (kept apart per kind), the invoice list's totals per kind, and the invoice
 * export (invoices CSV, lines CSV, everything in a ZIP). Included by tests/run.php (after phase38.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Services\InvoiceService;

section('Dashboard and export of invoices');
fresh_db();
fresh_session();
Clock::fake('2026-11-28');
$admin = make_user('Admin', 'p39-admin@example.com');
sign_in($admin);

$mk = static fn (string $n, array $o = []): int => mk_job($o + ['job_number' => $n, 'status' => 'in_transit', 'carrier_name' => 'KEN', 'driver_name' => 'Dan', 'broker' => 'Prime', 'customer_first_name' => 'Ada', 'customer_last_name' => 'Smith', 'cf' => '100', 'carrier_rate' => '2.00', 'balance' => '500.00', 'loaded_date' => '2026-11-05', 'notes' => 'fragile']);
$mk('E-1');
$mk('E-2', ['cf' => '50', 'carrier_rate' => '3.00', 'balance' => '100.00', 'customer_first_name' => '=HYPERLINK("x")', 'customer_last_name' => 'Evil']);
$mk('E-3', ['carrier_name' => 'OTHER', 'driver_name' => 'Zed', 'broker' => 'Next Level', 'cf' => '10', 'carrier_rate' => '50.00', 'balance' => '20.00']); // fee 500 > balance: negative total
$invoiceId = static fn (string $n): int => (int) Database::fetchValue('SELECT id FROM invoices WHERE type = ? AND seq = ?', [substr($n, 0, 2), (int) substr($n, 3)]);

check(str_contains(plain(call('GET', '/dashboard')->body()), 'Saved invoices appear here.'), 'with no invoices the dashboard says where they will appear');
call('POST', '/invoices', ['carrier' => 'KEN']);                               // DI-1: bal 600, fee 350 -> 250
call('POST', '/invoices', ['carrier' => 'OTHER', 'remarks' => '=SUM(1+1)']);   // DI-2: bal 20, fee 500 -> -480
call('POST', '/invoices', ['company' => 'Prime']);                              // CI-1: bal 600, fee 350 -> 250
call('POST', '/invoices/' . $invoiceId('DI-1') . '/paid', ['paid_on' => '2026-11-20']);
call('POST', '/invoices', ['company' => 'Next Level']);                         // CI-2
call('POST', '/invoices/' . $invoiceId('CI-2') . '/void', ['reason' => 'test']);

// 1. dashboard
$dash = plain(call('GET', '/dashboard')->body());
check(str_contains($dash, 'Driver Invoices open') && str_contains($dash, 'Company Invoices open'), 'the dashboard has an Invoices box with the two kinds');
// DI open: DI-2 only (-480.00); paid in 30 days: DI-1. CI open: CI-1 (250), CI-2 is void
check(str_contains($dash, '-$480.00 not settled · 1 paid in 30 days') && str_contains($dash, '$250.00 not settled · 0 paid in 30 days'), 'open totals are kept apart per kind (a driver and a company invoice can hold the same jobs), with the invoices paid in the last 30 days');
check(str_contains($dash, 'DI-2') && str_contains($dash, 'CI-1') && str_contains($dash, 'DI-1') && !str_contains($dash, 'CI-2'), 'the latest invoices are listed, a voided one is not');
check(str_contains(call('GET', '/dashboard')->body(), 'href="/invoices/' . $invoiceId('DI-2') . '"'), 'each links to the invoice');
$staff = make_user('Staff', 'p39-staff@example.com');
sign_in($staff);
check(!str_contains(plain(call('GET', '/dashboard')->body()), 'Driver Invoices open') && !str_contains(call('GET', '/dashboard')->body(), 'All invoices'), 'Staff (no invoices.view) see no invoice box');
sign_in($admin);

// 2. the list's totals per kind
$list = plain(call('GET', '/invoices')->body());
check(str_contains($list, 'Driver Invoices (not voided): -$230.00') && str_contains($list, 'still open -$480.00') && str_contains($list, 'Company Invoices (not voided): $250.00'), 'the list adds the two kinds apart when no type is chosen (DI: 250 - 480 = -230, CI: 250)');
check(str_contains(plain(call('GET', '/invoices', [], ['type' => 'DI'])->body()), 'Total of these (not voided): -$230.00'), 'with a type chosen it shows one total');

// 3. export
$csv = static fn (array $q): string => call('GET', '/invoices/export', [], $q)->content();
$rows = static function (string $text): array {
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, preg_replace('/^\xEF\xBB\xBF/', '', $text));
    rewind($fh);
    $out = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $out[] = $r;
    }
    return $out;
};
$sum = $rows($csv(['kind' => 'summary']));
check(count($sum) === 5 && $sum[0][0] === 'Invoice #' && $sum[0][15] === 'Total due', 'the invoices CSV has a header and one row per invoice (4)');
$byNo = array_column(array_slice($sum, 1), null, 0);
check($byNo['DI-1'][2] === 'Paid' && $byNo['DI-1'][4] === '2026-11-20' && $byNo['DI-1'][15] === '250.00' && $byNo['DI-1'][8] === '2' && $byNo['DI-1'][1] === 'Driver Invoice', 'a row has the status, the paid date, the number of jobs and the total');
check($byNo['DI-2'][15] === '-480.00' && $byNo['DI-2'][16] === 'Company owes carrier 480.00' && $byNo['CI-2'][2] === 'Void', 'a negative total stays a plain number, the row says who owes whom, a voided invoice is marked Void');
check($byNo['DI-2'][18] === "'=SUM(1+1)", 'a remark that starts like a formula is made safe');
check(str_contains($csv(['kind' => 'summary']), "\xEF\xBB\xBF") && str_contains((string) call('GET', '/invoices/export', [], ['kind' => 'summary'])->getHeader('Content-Disposition'), 'invoices-2026-11-28.csv'), 'the file has the BOM Excel needs and a dated name');
check(count($rows($csv(['kind' => 'summary', 'status' => 'paid']))) === 2 && count($rows($csv(['kind' => 'summary', 'type' => 'CI']))) === 3 && count($rows($csv(['kind' => 'summary', 'q' => 'DI-2']))) === 2, 'the export follows the list\'s filters (status, type, search)');
$lines = $rows($csv(['kind' => 'lines']));
// DI-1: E-1, E-2 ; DI-2: E-3 ; CI-1: E-1, E-2 ; CI-2: E-3 -> 6 lines
check(count($lines) === 7 && $lines[0][7] === 'Job #' && $lines[0][18] === 'CF Pay', 'the lines CSV has one row per job line (6)');
$e2 = array_values(array_filter($lines, static fn (array $r): bool => $r[7] === 'E-2' && $r[0] === 'DI-1'))[0];
check($e2[9] === "'=HYPERLINK(\"x\") Evil" && $e2[16] === '50.00' && $e2[17] === '3.00' && $e2[18] === '150.00' && $e2[19] === '100.00' && $e2[8] === 'In Transit', 'a line has its customer (formula-safe), A-CF, rate, CF Pay, Bal. Due and the job\'s status');
check(count($rows($csv(['kind' => 'lines', 'type' => 'CI', 'status' => 'open']))) === 3, 'the lines follow the same filters (CI-1 has two lines)');
// everything in a ZIP
$zipFile = sys_get_temp_dir() . '/jt-exp-' . bin2hex(random_bytes(4)) . '.zip';
file_put_contents($zipFile, call('GET', '/invoices/export', [], ['kind' => 'zip'])->content());
$zip = new ZipArchive();
check($zip->open($zipFile) === true && $zip->numFiles === 4, 'the ZIP holds four files');
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = $zip->getNameIndex($i);
}
sort($names);
check($names === ['companies.csv', 'drivers.csv', 'invoice-lines.csv', 'invoices.csv'], 'invoices, lines, companies and drivers');
check(count($rows((string) $zip->getFromName('invoices.csv'))) === 5 && count($rows((string) $zip->getFromName('invoice-lines.csv'))) === 7 && $rows((string) $zip->getFromName('companies.csv'))[0][0] === 'Name' && $rows((string) $zip->getFromName('drivers.csv'))[0][2] === 'License #', 'with the same rows as the CSV files');
$zip->close();
@unlink($zipFile);
check((int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'invoices_exported'") >= 8, 'every export is audited');
check(str_contains((string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'invoices_exported' ORDER BY id DESC LIMIT 1"), 'zip'), '...with the kind');

// 4. who may
check(str_contains(call('GET', '/invoices')->body(), 'kind=zip') && str_contains(call('GET', '/invoices', [], ['q' => 'nobody'])->body(), 'No invoices yet') && !str_contains(call('GET', '/invoices', [], ['q' => 'nobody'])->body(), 'kind=zip'), 'the list shows the export buttons only when there is something to export');
sign_in($staff);
check(call('GET', '/invoices/export', [], ['kind' => 'summary'])->status() === 403, 'Staff cannot export invoices');
$manager = make_user('Manager', 'p39-mgr@example.com');
sign_in($manager);
check(call('GET', '/invoices/export', [], ['kind' => 'summary'])->status() === 200, 'a Manager can');
$mgrId = (int) Database::fetchValue("SELECT id FROM roles WHERE name = 'Manager'");
Database::execute('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = (SELECT id FROM permissions WHERE name = ?)', [$mgrId, 'jobs.export']);
check(call('GET', '/invoices/export', [], ['kind' => 'summary'])->status() === 403 && call('GET', '/invoices')->status() === 200 && !str_contains(call('GET', '/invoices')->body(), 'kind=zip'), 'seeing invoices is not enough: the export needs the export permission, and without it the buttons are not shown');
sign_in($admin);
