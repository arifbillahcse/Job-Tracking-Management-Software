<?php

declare(strict_types=1);

/**
 * Client requests (v1.7.0): Future Jobs and Booked Jobs sections, Range and Pads on a job, storage fee payments.
 * Migrations 017 and 018. Included by tests/run.php (after phase19.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Database\Migrator;
use App\Repositories\JobRepository;
use App\Services\JobBucket;
use App\Services\StorageFeeService;
use App\Services\StoragePaymentService;

section('Migrations 017 and 018 on an existing database');
$dir = sys_get_temp_dir() . '/jt-mig17-' . bin2hex(random_bytes(4));
mkdir($dir);
foreach (glob(BASE_PATH . '/database/migrations/0{0[1-9],1[0-6]}_*.sql', GLOB_BRACE) as $f) {
    copy($f, $dir . '/' . basename($f));
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Database::setConnection($pdo);
(new Migrator($dir))->migrate();
$pdo->exec("INSERT INTO jobs (job_number, customer_first_name, customer_last_name, first_available_date, from_location, to_location, status, storage_fee, created_at, updated_at) VALUES ('OLD-1', 'A', 'B', '2026-01-01', 'X', 'Y', 'booked', 75.50, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
foreach (['017_job_range_pads.sql', '018_storage_payments.sql'] as $m) {
    copy(BASE_PATH . '/database/migrations/' . $m, $dir . '/' . $m);
}
check((new Migrator($dir))->migrate() === ['017_job_range_pads.sql', '018_storage_payments.sql'], 'only migrations 017 and 018 run on an existing database');
$old = $pdo->query("SELECT job_range, pads, storage_fee, storage_paid FROM jobs WHERE job_number = 'OLD-1'")->fetch();
check($old['job_range'] === null && $old['pads'] === null && (float) $old['storage_fee'] === 75.5 && (float) $old['storage_paid'] === 0.0, 'existing jobs keep their data: Range and Pads empty, nothing paid');
check((int) $pdo->query('SELECT COUNT(*) FROM storage_payments')->fetchColumn() === 0, 'the payments table starts empty');
check((new Migrator($dir))->migrate() === [], 'running them again does nothing');
Database::setConnection(null);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);

section('Future Jobs and Booked Jobs');
fresh_db();
fresh_session();
Clock::fake('2026-11-15');
set_setting('section_basis', 'pickup');
$mgr = make_user('Manager', 'p20-mgr@example.com');
sign_in($mgr);
$jobs = [
    'F-TOMORROW' => ['2026-11-16', 'booked'], 'F-NEXTMONTH' => ['2026-12-20', 'booked'],
    'R-TODAY' => ['2026-11-15', 'booked'], 'R-RECENT' => ['2026-11-01', 'booked'], 'L-OLD' => ['2026-09-01', 'booked'],
    'T-ROAD' => ['2026-11-05', 'in_transit'], 'H-HOLD' => ['2026-11-20', 'on_hold'], 'D-DONE' => ['2026-10-01', 'delivered'], 'C-GONE' => ['2026-11-02', 'cancelled'],
];
foreach ($jobs as $n => [$pickup, $status]) {
    mk_job(['job_number' => $n, 'pickup_date' => $pickup, 'first_available_date' => $pickup, 'status' => $status]);
}
$list = static function (string $path): array {
    $html = call('GET', $path)->body();
    preg_match_all('~class="jt-jobno" href="/jobs/\d+">([A-Z]-[A-Z]+)</a>~', $html, $m);
    $found = array_values(array_unique($m[1]));
    sort($found);
    return $found;
};
check($list('/jobs/future') === ['F-NEXTMONTH', 'F-TOMORROW'], 'Future Jobs = booked jobs whose pickup date is after today');
check($list('/jobs/booked') === ['F-NEXTMONTH', 'F-TOMORROW', 'H-HOLD', 'L-OLD', 'R-RECENT', 'R-TODAY', 'T-ROAD'], 'Booked Jobs = every job that is not delivered (on hold and in transit included, cancelled left out)');
check($list('/jobs/ready') === ['R-RECENT', 'R-TODAY'], 'Ready for Delivery no longer lists a job that is not picked up yet');
check($list('/jobs/more-than-30') === ['L-OLD'], 'More Than 30 Days is unchanged');
check(str_contains(plain(call('GET', '/jobs')->body()), 'of 9 jobs'), 'All Jobs still lists everything (9 jobs)');
$ready = $list('/jobs/ready');
check(array_intersect($list('/jobs/future'), $ready, $list('/jobs/more-than-30'), $list('/jobs/delivered')) === [] && array_intersect($list('/jobs/future'), $ready) === [], 'no job is in Future and in Ready (a job appears once in the date sections)');
$tabs = plain(call('GET', '/jobs')->body());
check(preg_match('~All Jobs \d+ Booked Jobs 7 Future Jobs 2 Ready for Delivery 2 More Than 30 Days 1 In Transit 1 Delivered 1~', $tabs) === 1, 'the tabs on top show Booked Jobs and Future Jobs with their counts: ' . (preg_match('~All Jobs.*?Delivered \d+~', $tabs, $tm) ? $tm[0] : '?'));
$dash = plain(call('GET', '/dashboard')->body());
check(str_contains($dash, 'Booked jobs') && str_contains($dash, 'Future jobs') && str_contains($dash, 'pickup date after today'), 'the dashboard has Booked jobs and Future jobs cards');
check(JobBucket::of(['status' => 'booked', 'pickup_date' => '2026-11-16', 'first_available_date' => '2026-11-16']) === JobBucket::FUTURE && JobBucket::of(['status' => 'on_hold', 'pickup_date' => '2026-11-16', 'first_available_date' => '2026-11-16']) === null, 'the PHP twin agrees: future for a booked job, none for a job on hold');
foreach (['/jobs/future', '/jobs/booked'] as $uri) {
    check(call('GET', $uri)->status() === 200 && call('GET', '/jobs/export/' . ltrim(strrchr($uri, '/'), '/'))->status() === 200, "{$uri} opens and exports");
}
make_role('NoPerms20', []);
sign_in(make_user('NoPerms20', 'p20-none@example.com'));
check(in_array(call('GET', '/jobs/future')->status(), [302, 403], true), 'a user without jobs.view cannot open Future Jobs');

section('Range and Pads');
sign_in($mgr);
$form = call('GET', '/jobs/create')->body();
check(preg_match('~name="color".*?name="job_range".*?name="pads"~s', $form) === 1, 'the job form has Range and Pads right after Color');
call('POST', '/jobs', job_input(['job_number' => 'RP-1', 'color' => 'blue', 'job_range' => '2', 'pads' => '14']));
call('POST', '/jobs', job_input(['job_number' => 'RP-2', 'color' => '', 'job_range' => '', 'pads' => '']));
$rp = static fn (string $n): array => Database::fetchAll('SELECT job_range, pads FROM jobs WHERE job_number = ?', [$n])[0] ?? [];
check($rp('RP-1') === ['job_range' => '2', 'pads' => '14'] && $rp('RP-2') === ['job_range' => null, 'pads' => null], 'Range and Pads are saved; empty stays empty');
call('POST', '/jobs', job_input(['job_number' => 'RP-LONG', 'pads' => str_repeat('9', 61)]));
check((int) Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number = 'RP-LONG'") === 0, 'more than 60 characters is refused');
$id = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'RP-1'");
check(str_contains(plain(call('GET', "/jobs/{$id}")->body()), 'Range 2') && str_contains(plain(call('GET', "/jobs/{$id}")->body()), 'Pads 14'), 'Job details show Range and Pads');
$html = call('GET', '/jobs', [], ['q' => 'RP-'])->body();
check(str_contains(plain($html), 'Range / Pads') && str_contains($html, 'sort=range') && str_contains($html, 'sort=pads'), 'the Jobs list has a Range / Pads column, both sortable');
$asc = (function () { $h = call('GET', '/jobs', [], ['q' => 'RP-', 'sort' => 'pads', 'dir' => 'asc'])->body(); preg_match_all('~RP-[12]~', $h, $m); return array_values(array_unique($m[0])); })();
check($asc === ['RP-2', 'RP-1'], 'sorting by Pads puts the job with none first');
check(isset(\App\Services\Import\JobFields::all()['job_range'], \App\Services\Import\JobFields::all()['pads']) && \App\Services\Import\JobFields::label('pads') === 'Pads', 'Import and export know the Range and Pads columns');

section('Storage fee payments');
fresh_db();
fresh_session();
Clock::fake('2026-11-15');
$mgr = make_user('Manager', 'p20b-mgr@example.com');
$stf = make_user('Staff', 'p20b-stf@example.com');
set_setting('storage_fee_mode', 'monthly');
sign_in($mgr);
// A-CF 1000 x 0.60, picked up 45 days ago: one month = $600.00 due
$jid = mk_job(['job_number' => 'SP-1', 'cf' => '1000', 'pickup_date' => '2026-10-01', 'first_available_date' => '2026-10-01', 'status' => 'booked']);
StorageFeeService::settle($jid);
$show = static fn () => plain(call('GET', "/jobs/{$jid}")->body());
check((float) Database::fetchValue('SELECT storage_fee FROM jobs WHERE id = ?', [$jid]) === 600.0 && str_contains($show(), 'Storage due $600.00'), 'the fee is $600.00 and all of it is due');
check(str_contains($show(), 'Record payment') && str_contains($show(), 'Storage fee payments'), 'the job page has a Storage fee payments box with a Record payment form');
call('POST', "/jobs/{$jid}/storage-payments", ['amount' => '200', 'paid_on' => '2026-11-15', 'note' => 'November']);
check((float) Database::fetchValue('SELECT storage_paid FROM jobs WHERE id = ?', [$jid]) === 200.0 && (int) Database::fetchValue('SELECT COUNT(*) FROM storage_payments WHERE job_id = ?', [$jid]) === 1, 'a $200 payment is recorded');
check(str_contains($show(), 'Storage due $400.00') && str_contains($show(), 'Storage paid $200.00') && str_contains($show(), 'November'), 'the job page shows paid $200.00, due $400.00 and the payment with its note');
$list = plain(call('GET', '/jobs', [], ['q' => 'SP-1'])->body());
check(str_contains($list, '$400.00') && str_contains($list, 'paid $200.00'), 'the Jobs list shows the storage fee still due ($400.00, paid $200.00)');
check((float) (new JobRepository())->moneyTotals()['storage_fee'] === 400.0, 'the dashboard storage total is what is still due');
call('POST', "/jobs/{$jid}/storage-payments", ['amount' => '400.01', 'paid_on' => '2026-11-15']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM storage_payments WHERE job_id = ?', [$jid]) === 1, 'a payment above what is due is refused');
foreach ([['0', '2026-11-15'], ['-5', '2026-11-15'], ['abc', '2026-11-15'], ['10', '2026-11-16'], ['10', 'not a date'], ['10', '']] as [$a, $d]) {
    call('POST', "/jobs/{$jid}/storage-payments", ['amount' => $a, 'paid_on' => $d]);
}
check((int) Database::fetchValue('SELECT COUNT(*) FROM storage_payments WHERE job_id = ?', [$jid]) === 1, 'zero, negative, text, a future date and a missing date are all refused');
call('POST', "/jobs/{$jid}/storage-payments", ['amount' => '400', 'paid_on' => '2026-11-14']);
check((float) StoragePaymentService::due((array) Database::fetchOne('SELECT * FROM jobs WHERE id = ?', [$jid])) === 0.0 && str_contains($show(), 'Storage due $0.00') && str_contains($show(), 'No storage fee is due'), 'paying the rest leaves nothing due');
$pid = (int) Database::fetchValue('SELECT id FROM storage_payments WHERE job_id = ? ORDER BY id DESC LIMIT 1', [$jid]);
call('POST', "/jobs/{$jid}/storage-payments/{$pid}/delete");
check((float) Database::fetchValue('SELECT storage_paid FROM jobs WHERE id = ?', [$jid]) === 200.0 && str_contains($show(), 'Storage due $400.00'), 'removing a payment makes the amount due again');
check((float) Database::fetchValue('SELECT storage_paid FROM jobs WHERE id = ?', [$jid]) === (float) Database::fetchValue('SELECT SUM(amount) FROM storage_payments WHERE job_id = ?', [$jid]), 'jobs.storage_paid always equals the sum of the payments');
check(call('POST', "/jobs/{$jid}/storage-payments/99999/delete")->status() === 404, 'removing a payment that does not exist is a 404');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'storage_payment_added' ORDER BY id DESC LIMIT 1");
check(str_contains($aud, 'SP-1') && str_contains($aud, '400.00') && Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'storage_payment_removed'") == 1, 'recording and removing a payment are audited');
// next month the fee is two months: what was paid still counts
Clock::fake('2026-12-20');
StorageFeeService::settle($jid);
check((float) Database::fetchValue('SELECT storage_fee FROM jobs WHERE id = ?', [$jid]) === 1200.0 && str_contains($show(), 'Storage due $1,000.00'), 'a month later the fee is $1,200.00 and, with $200.00 paid, $1,000.00 is due');
Clock::fake('2026-11-15');
// a manual fee override is paid down the same way
$j2 = mk_job(['job_number' => 'SP-2', 'cf' => '10', 'storage_fee_override' => '90.00', 'status' => 'booked']);
StorageFeeService::settle($j2);
call('POST', "/jobs/{$j2}/storage-payments", ['amount' => '30', 'paid_on' => '2026-11-15']);
check((float) Database::fetchValue('SELECT storage_paid FROM jobs WHERE id = ?', [$j2]) === 30.0 && str_contains(plain(call('GET', "/jobs/{$j2}")->body()), 'Storage due $60.00'), 'a manual fee of $90.00 with $30.00 paid has $60.00 due');
// nothing due, nothing to pay
$j3 = mk_job(['job_number' => 'SP-3', 'cf' => '100', 'status' => 'booked']);
call('POST', "/jobs/{$j3}/storage-payments", ['amount' => '5', 'paid_on' => '2026-11-15']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM storage_payments WHERE job_id = ?', [$j3]) === 0, 'a job with no storage fee cannot be paid');
// sorting by storage fee due never errors and orders by what is due
$order = (function () { $h = call('GET', '/jobs', [], ['q' => 'SP-', 'sort' => 'storage', 'dir' => 'desc'])->body(); preg_match_all('~SP-[123]~', $h, $m); return array_values(array_unique($m[0])); })();
check($order === ['SP-1', 'SP-2', 'SP-3'], 'sorting by Storage fee due orders by what is still due: ' . implode(',', $order));
// permissions
sign_in($stf);
$before = (int) Database::fetchValue('SELECT COUNT(*) FROM storage_payments');
check(call('POST', "/jobs/{$jid}/storage-payments", ['amount' => '1', 'paid_on' => '2026-11-15'])->status() === 403 && call('POST', "/jobs/{$jid}/storage-payments/{$pid}/delete")->status() === 403 && (int) Database::fetchValue('SELECT COUNT(*) FROM storage_payments') === $before, 'Staff cannot record or remove a storage payment (403)');
check(!str_contains(plain(call('GET', "/jobs/{$jid}")->body()), 'Storage fee payments') && !str_contains(plain(call('GET', "/jobs/{$jid}")->body()), 'Storage paid'), 'Staff do not see the storage payments or the paid amount');
// a delivered job can still be paid (the fee is frozen at delivery)
sign_in($mgr);
$jd = mk_job(['job_number' => 'SP-D', 'cf' => '1000', 'pickup_date' => '2026-09-01', 'first_available_date' => '2026-09-01', 'status' => 'delivered', 'delivery_date' => '2026-10-20', 'delivered_at' => '2026-10-20 00:00:00']);
StorageFeeService::settle($jd);
$feeD = (float) Database::fetchValue('SELECT storage_fee FROM jobs WHERE id = ?', [$jd]);
call('POST', "/jobs/{$jd}/storage-payments", ['amount' => '100', 'paid_on' => '2026-11-15']);
check($feeD > 100 && (float) Database::fetchValue('SELECT storage_paid FROM jobs WHERE id = ?', [$jd]) === 100.0, 'a delivered job (fee frozen at $' . number_format($feeD, 2) . ') can be paid down too');
