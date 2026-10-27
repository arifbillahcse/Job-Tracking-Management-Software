<?php

declare(strict_types=1);

/**
 * Roadmap phase 2 (version 1.2): the client's sheet fields on a job - E-CF, A-CF, E-Total, Deposit, Grand total, BH total,
 * Paid, Foreman, Job lot, Color - and migration 009. Included by tests/run.php (after phase10.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Database\Migrator;
use App\Repositories\JobRepository;
use App\Services\ExportService;
use App\Services\ImportService;
use App\Services\JobCriteria;
use App\Services\SettingsService;
use App\Services\StorageFeeService;

/** Numeric columns come back as numbers on SQLite and as strings on MariaDB; compare them as floats. */
function n(mixed $v): ?float
{
    return $v === null ? null : (float) $v;
}

section('Sheet fields: migration 009 keeps existing data');
$dir = sys_get_temp_dir() . '/jt-mig9-' . bin2hex(random_bytes(4));
mkdir($dir);
foreach (glob(BASE_PATH . '/database/migrations/00[1-8]_*.sql') as $f) {
    copy($f, $dir . '/' . basename($f));
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Database::setConnection($pdo);
(new Migrator($dir))->migrate();
$now = '2026-01-01 00:00:00';
$pdo->exec("INSERT INTO jobs (job_number, customer_first_name, customer_last_name, first_available_date, from_location, to_location, status, cf, balance, storage_fee, storage_fee_override, created_at, updated_at) VALUES ('OLD-9', 'A', 'B', '2026-01-01', 'X', 'Y', 'booked', 900, 123.45, 40, 40, '{$now}', '{$now}')");
copy(BASE_PATH . '/database/migrations/009_add_job_money_and_details.sql', $dir . '/009_add_job_money_and_details.sql');
check((new Migrator($dir))->migrate() === ['009_add_job_money_and_details.sql'], 'only migration 009 runs on an existing database');
$old = $pdo->query("SELECT * FROM jobs WHERE job_number = 'OLD-9'")->fetch();
check((float) $old['cf'] === 900.0 && (float) $old['balance'] === 123.45 && (float) $old['storage_fee_override'] === 40.0 && (float) $old['storage_fee'] === 40.0, 'cf, balance and the manual storage fee are untouched');
check($old['estimated_cf'] === null && $old['estimated_total'] === null && $old['deposit'] === null && $old['grand_total'] === null && $old['bh_total'] === null && $old['paid'] === null && $old['foreman_name'] === null && $old['job_lot'] === null && $old['color'] === null, 'the new columns start empty (nothing is calculated or invented)');
check((new Migrator($dir))->migrate() === [], 'running it again does nothing');
Database::setConnection(null);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);

section('Sheet fields: create, edit, view');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'p11-admin@example.com');
$staff = make_user('Staff', 'p11-staff@example.com');
$mgr = make_user('Manager', 'p11-mgr@example.com');
sign_in($admin);
$form = call('GET', '/jobs/create')->content();
$all = true;
foreach (['estimated_cf', 'cf', 'estimated_total', 'deposit', 'grand_total', 'bh_total', 'paid', 'balance', 'foreman_name', 'job_lot', 'color'] as $f) {
    $all = $all && str_contains($form, 'name="' . $f . '"');
}
check($all, 'Add Job has E-CF, A-CF, E-Total, Deposit, Grand total, BH total, Paid, Balance, Foreman, Job lot and Color');
check(str_contains($form, 'A-CF (actual cubic feet)') && str_contains($form, 'E-CF (estimated cubic feet)'), '...with the sheet\'s labels');
$sample = ['job_number' => 'S-11', 'customer_first_name' => 'John', 'customer_last_name' => 'smith', 'pickup_date' => '2026-10-24', 'first_available_date' => '2026-10-29',
    'from_location' => 'GA', 'to_location' => 'CA', 'estimated_cf' => '1000', 'estimated_total' => '3000', 'deposit' => '1000', 'cf' => '1200', 'grand_total' => '3500',
    'bh_total' => '2500', 'paid' => '1250', 'balance' => '1250', 'foreman_name' => 'Joe', 'job_lot' => 'L-7', 'color' => 'Blue', 'storage_fee_override' => ''];
$r = call('POST', '/jobs', job_input($sample));
$id = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'S-11'");
$row = (new JobRepository())->find($id);
check($r->status() === 302 && n($row['estimated_cf']) === 1000.0 && n($row['estimated_total']) === 3000.0 && n($row['deposit']) === 1000.0 && n($row['grand_total']) === 3500.0 && n($row['bh_total']) === 2500.0 && n($row['paid']) === 1250.0 && n($row['balance']) === 1250.0 && n($row['cf']) === 1200.0, 'the sheet\'s sample row is saved exactly as typed');
check($row['foreman_name'] === 'Joe' && $row['job_lot'] === 'L-7' && $row['color'] === 'Blue', 'foreman, job lot and color are saved');
check(n($row['grand_total']) === 3500.0 && n($row['bh_total']) === 2500.0, 'no formula is applied: Grand total and BH total are stored as typed, not derived');
$page = plain(call('GET', "/jobs/{$id}")->body());
check(str_contains($page, 'E-CF 1,000') && str_contains($page, 'A-CF 1,200') && str_contains($page, 'Deposit $1,000.00') && str_contains($page, 'Grand total $3,500.00') && str_contains($page, 'BH total $2,500.00') && str_contains($page, 'Paid $1,250.00') && str_contains($page, 'Foreman Joe') && str_contains($page, 'Job lot L-7') && str_contains($page, 'Color Blue'), 'Job Details shows every new field');
$edit = call('GET', "/jobs/{$id}/edit")->content();
check(preg_match('/name="paid"[^>]*value="1250(\.00)?"/', $edit) === 1, 'Edit Job shows the saved money');
call('POST', "/jobs/{$id}", array_merge(job_input($sample), ['paid' => '2000', 'foreman_name' => 'Sam', 'loaded_at' => (string) $row['updated_at']]));
$row = (new JobRepository())->find($id);
check(n($row['paid']) === 2000.0 && $row['foreman_name'] === 'Sam', 'they can be edited');
$audit = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'job_updated' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$id]);
check(str_contains($audit, '"paid":"2000.00"') && str_contains($audit, '"foreman_name":"Sam"'), 'the change is audited with the new values');
call('POST', '/jobs', job_input(['job_number' => 'S-EMPTY', 'estimated_cf' => '', 'deposit' => '', 'paid' => '', 'foreman_name' => '  ', 'job_lot' => '', 'color' => '']));
$e = Database::fetchOne("SELECT * FROM jobs WHERE job_number = 'S-EMPTY'");
check($e['estimated_cf'] === null && $e['deposit'] === null && $e['paid'] === null && $e['foreman_name'] === null && $e['job_lot'] === null && $e['color'] === null, 'empty fields are stored as NULL, not as 0');
foreach (['deposit' => '-5', 'paid' => 'abc', 'grand_total' => '12.345', 'estimated_cf' => '-1', 'color' => str_repeat('x', 61), 'foreman_name' => str_repeat('x', 151)] as $field => $bad) {
    call('POST', '/jobs', job_input(['job_number' => 'S-BAD', $field => $bad]));
    check(Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number = 'S-BAD'") == 0 && isset($_SESSION['_flash_next']['errors'][$field]), "{$field} = " . json_encode(substr($bad, 0, 12)) . ' is refused');
}

section('Sheet fields: who can see and change the money');
fresh_session();
sign_in($staff);
$form = call('GET', '/jobs/create')->content();
check(str_contains($form, 'name="estimated_cf"') && str_contains($form, 'name="foreman_name"') && !str_contains($form, 'name="deposit"') && !str_contains($form, 'name="paid"') && !str_contains($form, 'name="grand_total"'), 'Staff sees E-CF and Foreman but none of the money fields');
check(!str_contains(plain(call('GET', "/jobs/{$id}")->body()), 'Grand total') && !str_contains(call('GET', "/jobs/{$id}")->body(), '3,500.00'), 'Job Details hides the money from Staff');
call('POST', '/jobs', job_input(['job_number' => 'S-STAFF', 'deposit' => '999', 'paid' => '999', 'grand_total' => '999', 'estimated_total' => '999', 'bh_total' => '999']));
$s = Database::fetchOne("SELECT * FROM jobs WHERE job_number = 'S-STAFF'");
check($s !== null && $s['deposit'] === null && $s['paid'] === null && $s['grand_total'] === null && $s['estimated_total'] === null && $s['bh_total'] === null, 'a forged request cannot make Staff write money fields');
$viewed = (new \App\Services\JobService())->find($id);
check($viewed !== null && array_intersect(['estimated_total', 'deposit', 'grand_total', 'bh_total', 'paid', 'balance', 'storage_fee'], array_keys($viewed)) === [] && isset($viewed['estimated_cf'], $viewed['foreman_name']), 'the service strips the money fields for Staff before any template sees them (E-CF and Foreman stay)');
$body = call('GET', '/jobs')->body();
check(!str_contains($body, '3,500') && !str_contains($body, '2,500.00'), 'the lists do not leak the money either');
$staffCsv = (new ExportService())->csv(JobCriteria::forBucket('all'), false)->content();
check(!str_contains($staffCsv, 'Deposit') && !str_contains($staffCsv, 'Grand Total') && !str_contains($staffCsv, 'BH Total') && !str_contains($staffCsv, 'Paid') && !str_contains($staffCsv, 'E-Total') && str_contains($staffCsv, 'E-CF') && str_contains($staffCsv, 'Foreman'), 'the export for Staff has no money columns but has E-CF and Foreman');
fresh_session();
sign_in($mgr);
check(str_contains(plain(call('GET', "/jobs/{$id}")->body()), 'Grand total $3,500.00'), 'a Manager sees the money');

section('Sheet fields: search, import and export');
check(str_contains(call('GET', '/jobs', [], ['q' => 'Sam'])->body(), 'S-11') && str_contains(call('GET', '/jobs', [], ['q' => 'L-7'])->body(), 'S-11'), 'search finds a job by foreman or job lot');
$csv = (new ExportService())->csv(JobCriteria::forBucket('all'), true)->content();
foreach (['E-CF', 'A-CF', 'E-Total', 'Deposit', 'Grand Total', 'BH Total', 'Paid', 'Foreman', 'Job Lot', 'Color'] as $h) {
    check(str_contains($csv, $h), "the All Jobs export has a {$h} column");
}
check(str_contains($csv, '2000.00') && str_contains($csv, 'Sam'), '...with the values');
$svc = new ImportService();
$file = tmp_file(csv_text([
    ['job#', 'customer Fname', 'customer L name', 'pickupdate', '1st availble date', 'from', 'To', 'E-CF', 'E-Total', 'Deposit', 'A-CF', 'Grand Total', 'BH Total', 'Paid', 'Forman', 'Job lot', 'color'],
    ['IMP-S1', 'John', 'smith', '10/24/26', '10/29/26', 'GA', 'CA', '1000', '3000', '1000', '1200', '3500', '2500', '1250', 'Joe', 'A1', 'Red'],
]));
$st = $svc->stage(upload_of($file, 'sheet.csv'), (int) $mgr['id']);
$b = Database::fetchOne('SELECT valid_rows, invalid_rows FROM import_batches WHERE id = ?', [$st['batch']]);
check((int) $b['valid_rows'] === 1 && (int) $b['invalid_rows'] === 0, 'a file with the client\'s own headings (Forman, A-CF, E-Total, ...) is read');
$svc->commit((int) $st['batch'], (int) $mgr['id']);
$im = Database::fetchOne("SELECT * FROM jobs WHERE job_number = 'IMP-S1'");
check(n($im['estimated_cf']) === 1000.0 && n($im['cf']) === 1200.0 && n($im['estimated_total']) === 3000.0 && n($im['deposit']) === 1000.0 && n($im['grand_total']) === 3500.0 && n($im['bh_total']) === 2500.0 && n($im['paid']) === 1250.0 && $im['foreman_name'] === 'Joe' && $im['job_lot'] === 'A1' && $im['color'] === 'Red', '...and every column is saved');
fresh_session();
sign_in($staff);
$file = tmp_file(csv_text([['Job #', 'Customer First Name', 'Customer Last Name', '1st Available Date', 'From', 'To', 'Deposit', 'Foreman'], ['IMP-S2', 'A', 'B', '2026-12-01', 'X', 'Y', '500', 'Zed']]));
$st = $svc->stage(upload_of($file, 'nomoney.csv'), (int) $staff['id']);
$svc->commit((int) $st['batch'], (int) $staff['id']);
$im = Database::fetchOne("SELECT deposit, foreman_name FROM jobs WHERE job_number = 'IMP-S2'");
check($im !== false && $im['deposit'] === null && $im['foreman_name'] === 'Zed', 'without the financial permission an imported Deposit is ignored, a Foreman is kept');
Clock::fake(null);

// ================================================================ roadmap phase 3: pickup basis and monthly fee

section('Storage fee: counted from the pickup date, charged every month (assumptions N1, N2)');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$defaults = require BASE_PATH . '/app/Config/settings.php';
check($defaults['storage_fee_basis'] === 'pickup' && $defaults['storage_fee_mode'] === 'monthly' && $defaults['storage_fee_rate'] === '0.6' && $defaults['storage_fee_after_days'] === '30', 'shipped defaults: from the pickup date, every month, rate 0.6, after 30 days');
p10_set(['storage_fee_basis' => 'pickup', 'storage_fee_mode' => 'monthly']);
check(StorageFeeService::basis() === 'pickup' && StorageFeeService::mode() === 'monthly', 'the settings take effect');
$b = ['status' => 'booked', 'cf' => '1000', 'pickup_date' => '2026-09-23', 'first_available_date' => '2026-12-31', 'loaded_date' => null, 'delivery_date' => null, 'storage_rate' => null, 'storage_fee_override' => null];
check(StorageFeeService::startsOn($b) === '2026-10-24', 'the fee starts 30 days after the pickup date, not after the 1st available date');
check(StorageFeeService::assess($b, '2026-10-23')['fee'] === '0.00', 'day 30 after pickup: nothing yet');
check(StorageFeeService::assess($b, '2026-10-24')['fee'] === '600.00', 'day 31: A-CF 1000 x 0.6 = 600.00 (the sheet\'s example)');
check(StorageFeeService::assess($b, '2026-11-22')['fee'] === '600.00' && StorageFeeService::assess($b, '2026-11-23')['fee'] === '1200.00', 'still one month on day 60, the second month starts on day 61');
check(StorageFeeService::assess($b, '2027-01-21')['fee'] === '1800.00' && StorageFeeService::assess($b, '2027-01-22')['fee'] === '2400.00', 'and so on: 90 days = 3 months, 91 days = 4 months');
check(StorageFeeService::assess($b, '2026-11-30')['fee'] === '1200.00', 'no prorating: a part month counts as a whole month');
check(StorageFeeService::months('2026-10-24', '2026-10-23') === 0 && StorageFeeService::months('2026-10-24', '2026-10-24') === 1 && StorageFeeService::months('2026-10-24', '2026-11-22') === 1 && StorageFeeService::months('2026-10-24', '2026-11-23') === 2, 'months(): 0 before, 1 from the first day, 2 after 30 more days');
$np = ['pickup_date' => null] + $b;
check(StorageFeeService::startsOn($np) === '2027-01-31', 'a job with no pickup date is counted from its 1st available date');
p10_set(['storage_fee_mode' => 'once']);
check(StorageFeeService::assess($b, '2027-06-01')['fee'] === '600.00', 'mode "once" charges CF x rate a single time, as before');
p10_set(['storage_fee_mode' => 'monthly', 'storage_fee_basis' => 'first_available']);
check(StorageFeeService::startsOn($b) === '2027-01-31', 'the 1st available date can still be chosen');
p10_set(['storage_fee_basis' => 'pickup']);

// delivered job: frozen at the months it had on delivery day, at the rate then
$d = $b + [];
$d['status'] = 'delivered';
$d['delivery_date'] = '2026-11-25';
check(StorageFeeService::assess($d, '2030-01-01')['fee'] === '1200.00', 'a delivered job keeps the months it had on its delivery date (2 months)');
$d['storage_rate'] = '0.5';
check(StorageFeeService::assess($d, '2030-01-01')['fee'] === '1000.00', '...at the rate it was charged then (0.5 x 1000 x 2)');

// stored fees follow the mode and never touch a manual override
$j1 = p10_job(['job_number' => 'N-AUTO', 'cf' => '1000', 'pickup_date' => '2026-07-25', 'first_available_date' => '2026-12-01']);   // 90 days ago
$j2 = p10_job(['job_number' => 'N-MAN', 'cf' => '1000', 'pickup_date' => '2026-07-25', 'first_available_date' => '2026-12-01', 'storage_fee_override' => '77.00', 'storage_fee' => '77.00']);
StorageFeeService::refresh();
check(p10_fee((int) $j1['id']) === '1200.00', 'refresh stores the monthly fee on an open job (90 days after pickup = 2 months = 1200)');
check(p10_fee((int) $j2['id']) === '77.00', 'a manually entered fee is not touched');
p10_set(['storage_fee_mode' => 'once']);
check(p10_fee((int) $j1['id']) === '600.00' && p10_fee((int) $j2['id']) === '77.00', 'switching the mode re-assesses automatic fees at once; the manual one stays');
p10_set(['storage_fee_mode' => 'monthly']);
$f = (new \App\Repositories\JobRepository())->find((int) $j1['id']);
check(str_contains(storage_fee_note($f), 'A-CF 1,000') && preg_match('/× \d months/', storage_fee_note($f)) === 1, 'the note on the job explains the months');

check(isset(SettingsService::validate(['storage_fee_mode' => 'weekly'] + SettingsService::editable())['errors']['storage_fee_mode']) && !isset(SettingsService::validate(['storage_fee_mode' => 'once'] + SettingsService::editable())['errors']['storage_fee_mode']), 'the mode must be monthly or once');
sign_in(make_user('Admin', 'n-admin@example.com'));
$page = plain(call('GET', '/settings')->body());
check(str_contains(call('GET', '/settings')->body(), 'name="storage_fee_mode"') && str_contains($page, 'pickup date') && str_contains($page, 'with no prorating'), 'the settings page offers the mode and the pickup date, and explains no prorating');
Clock::fake(null);

// ================================================================ roadmap phase 4: columns everywhere, storage fee permission

section('CF and Storage Fee columns in every list, and who may change the rate');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'q4-admin@example.com');
$mgr = make_user('Manager', 'q4-mgr@example.com');
$stf = make_user('Staff', 'q4-stf@example.com');
p10_set(['storage_fee_basis' => 'pickup', 'storage_fee_mode' => 'monthly']);
mk_job(['job_number' => 'Q4-R', 'cf' => '100', 'first_available_date' => '2026-11-01']);
mk_job(['job_number' => 'Q4-L', 'cf' => '100', 'first_available_date' => '2027-03-01']);
mk_job(['job_number' => 'Q4-T', 'cf' => '100', 'status' => 'in_transit', 'first_available_date' => '2026-11-01', 'storage_fee' => '60.00', 'storage_fee_override' => '60.00']);
mk_job(['job_number' => 'Q4-D', 'cf' => '100', 'status' => 'delivered', 'first_available_date' => '2026-09-01', 'storage_fee' => '60.00', 'delivered_at' => '2026-10-01 00:00:00']);
sign_in($mgr);
foreach (['/jobs', '/jobs/ready', '/jobs/more-than-30', '/jobs/in-transit', '/jobs/delivered'] as $path) {
    $h = plain(call('GET', $path)->body());
    check(str_contains($h, 'A-CF') && str_contains($h, 'Storage fee'), "{$path} shows the A-CF and Storage fee columns");
}
check(str_contains(plain(call('GET', '/jobs/in-transit')->body()), '$60.00'), 'the fee itself is shown on the In Transit list');
foreach (['ready', 'later', 'transit', 'delivered', 'all'] as $bucket) {
    check(in_array('Storage Fee', array_map([\App\Services\Import\JobFields::class, 'label'], ExportService::columns($bucket, true)), true) && !in_array('storage_fee', ExportService::columns($bucket, false), true), "the {$bucket} export has a Storage Fee column, but not for a user without financial access");
}
fresh_session();
sign_in($stf);
foreach (['/jobs', '/jobs/ready', '/jobs/more-than-30', '/jobs/in-transit', '/jobs/delivered'] as $path) {
    $h = plain(call('GET', $path)->body());
    check(str_contains($h, 'A-CF') && !str_contains($h, 'Storage fee'), "{$path}: Staff sees A-CF but no Storage fee column");
}

check(Database::fetchValue("SELECT COUNT(*) FROM permissions WHERE name = 'settings.storage_fee'") == 1, 'the settings.storage_fee permission exists');
$has = static fn (string $role): bool => (int) Database::fetchValue("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.name = ? AND p.name = 'settings.storage_fee'", [$role]) === 1;
check($has('Admin') && $has('Manager') && !$has('Staff'), 'Admin and Manager have it by default, Staff does not');
check(call('GET', '/settings/storage-fee')->status() === 403 && call('POST', '/settings/storage-fee', ['storage_fee_rate' => '9'])->status() === 403, 'Staff cannot open or post the storage fee page');
fresh_session();
sign_in($mgr);
check(call('GET', '/settings')->status() === 403, 'a Manager still cannot open the full Settings page');
$page = call('GET', '/settings/storage-fee');
$pb = $page->body();
check($page->status() === 200 && str_contains($pb, 'name="storage_fee_rate"') && str_contains($pb, 'name="storage_fee_mode"') && !str_contains($pb, 'name="company_name"'), 'a Manager gets a page with only the storage fee settings');
$j = mk_job(['job_number' => 'Q4-FEE', 'cf' => '1000', 'first_available_date' => '2026-12-01', 'pickup_date' => '2026-09-01']);   // 52 days -> 1 month
StorageFeeService::refresh();
check(p10_fee($j) === '600.00', 'a job picked up 52 days ago has a fee of 600.00 at rate 0.6');
$r = call('POST', '/settings/storage-fee', ['storage_fee_rate' => '75c', 'storage_fee_after_days' => '30', 'storage_fee_basis' => 'pickup', 'storage_fee_mode' => 'monthly', 'company_name' => 'Hacked', 'ready_threshold_days' => '5']);
check($r->status() === 302 && StorageFeeService::rate() === '0.75', 'a Manager can change the rate (cents accepted)');
check(p10_fee($j) === '750.00', '...and open jobs are re-calculated at once');
check(SettingsService::get('company_name') !== 'Hacked' && SettingsService::get('ready_threshold_days') === '30', 'other settings in the request are ignored');
$audit = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'settings_updated' ORDER BY id DESC LIMIT 1");
check(str_contains($audit, 'storage_fee_rate') && !str_contains($audit, 'company_name'), 'the change is audited, with only the storage keys');
$r = call('POST', '/settings/storage-fee', ['storage_fee_rate' => 'abc', 'storage_fee_after_days' => '30', 'storage_fee_basis' => 'pickup', 'storage_fee_mode' => 'monthly']);
check(StorageFeeService::rate() === '0.75' && isset($_SESSION['_flash_next']['errors']), 'a bad rate is refused and nothing changes');
$r = call('POST', '/settings/storage-fee', ['storage_fee_rate' => '1', 'storage_fee_after_days' => '30', 'storage_fee_basis' => 'nonsense', 'storage_fee_mode' => 'monthly']);
check(StorageFeeService::rate() === '0.75', 'an invalid basis refuses the whole save');
$n = plain((string) preg_replace('#.*<aside(.*?)</aside>.*#s', '$1', call('GET', '/account')->body()));
check(str_contains($n, 'Storage Fee') && !preg_match('/\bSettings\b/', $n), 'the menu shows Storage Fee (not Settings) to a Manager');
Clock::fake(null);
