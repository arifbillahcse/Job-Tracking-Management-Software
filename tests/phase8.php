<?php

declare(strict_types=1);

/**
 * Phase 8 tests: exploratory edge cases found while preparing user acceptance testing.
 * Included by tests/run.php (after phase7.php), which provides the fixtures and helpers.
 */

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;

function p8_job(array $override = []): ?array
{
    $res = call('POST', '/jobs', job_input($override));
    return Database::fetchOne('SELECT * FROM jobs WHERE job_number = ?', [job_input($override)['job_number']]);
}

function p8_try(array $override): array
{
    $input = job_input($override);
    $res = call('POST', '/jobs', $input);
    return ['status' => $res->status(), 'location' => (string) $res->getHeader('Location'), 'row' => Database::fetchOne('SELECT * FROM jobs WHERE job_number = ?', [$input['job_number']])];
}

section('Edge cases: money');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'p8-admin@example.com');
sign_in($admin);

$expect = [
    '1e3' => null, '1E3' => null, '0x1A' => null, '1,200' => null, '$12' => null, '12.345' => null, '+5' => null, '-0' => null, '-1' => null,
    'NaN' => null, 'INF' => null, '1e999' => null, '١٢٣' => null, '12.' . '5.5' => null, '99999999999' => null,
    ' 12 ' => '12.00', '.5' => '0.50', '5.' => '5.00', '0' => '0.00', '1200.5' => '1200.50', '9999999999.99' => '9999999999.99',
];
foreach ($expect as $typed => $stored) {
    $r = p8_try(['job_number' => 'M-' . bin2hex(random_bytes(3)), 'balance' => (string) $typed]);
    $actual = $r['row'] === null ? null : number_format((float) $r['row']['balance'], 2, '.', '');
    check($actual === $stored, 'balance ' . json_encode($typed) . ' -> ' . ($stored === null ? 'refused' : $stored) . ($actual === $stored ? '' : ' (got ' . json_encode($actual) . ')'));
}
$r = p8_try(['job_number' => 'M-STORAGE', 'storage_fee' => '10.999']);
check($r['row'] === null, 'a storage fee with three decimals is refused, not rounded');
$r = p8_try(['job_number' => 'M-CF', 'cf' => '900.125']);
check($r['row'] === null, 'cubic feet with three decimals is refused too');
check(str_contains(call('GET', '/jobs/create')->content(), 'name="balance"'), 'the form still renders');
call('POST', '/jobs', job_input(['job_number' => 'M-MSG', 'balance' => '12.345']));
check(str_contains(call('GET', '/jobs/create')->content(), 'at most two decimals'), 'the error says what is allowed');

section('Edge cases: dates');
foreach ([
    ['2026-02-29', false], ['2028-02-29', true], ['2026-13-01', false], ['2026-00-10', false], ['2026-04-31', false], ['2026-1-5', false],
    ['26-10-23', false], ['2026/10/23', false], ['2026-10-23 10:00', false], ['0000-00-00', false], ['', false], ['10/23/2026', false],
] as [$typed, $valid]) {
    $r = p8_try(['job_number' => 'D-' . bin2hex(random_bytes(3)), 'first_available_date' => $typed]);
    check(($r['row'] !== null) === $valid, 'available date ' . json_encode($typed) . ' is ' . ($valid ? 'accepted' : 'refused'));
}
$r = p8_try(['job_number' => 'D-LEAP', 'first_available_date' => '2028-02-29', 'pickup_date' => '2028-02-28']);
check($r['row'] !== null && $r['row']['first_available_date'] === '2028-02-29', 'a real leap day is stored exactly');

section('Edge cases: section boundaries');
$bucket = static function (string $number, string $date): ?string {
    $row = p8_try(['job_number' => $number, 'first_available_date' => $date])['row'];
    return $row === null ? 'refused' : \App\Services\JobBucket::of($row);
};
check($bucket('B-TODAY', '2026-10-23') === 'ready', 'available today is Ready');
check($bucket('B-PAST', '2026-10-01') === 'ready', 'an overdue job is still Ready (and flagged overdue)');
check($bucket('B-30', '2026-11-22') === 'ready', 'exactly 30 days away is Ready');
check($bucket('B-31', '2026-11-23') === 'later', '31 days away is More Than 30 Days');
check($bucket('B-LEAP', '2028-02-29') === 'later', 'a date far in the future is More Than 30 Days');
$ids = array_column(Database::fetchAll("SELECT id FROM jobs WHERE job_number IN ('B-30', 'B-31')"), 'id');
check(str_contains(call('GET', '/jobs/ready')->content(), 'B-30') && !str_contains(call('GET', '/jobs/ready')->content(), 'B-31'), 'the Ready list shows day 30 and not day 31');
check(str_contains(call('GET', '/jobs/more-than-30')->content(), 'B-31') && !str_contains(call('GET', '/jobs/more-than-30')->content(), 'B-30'), 'the later list shows day 31 and not day 30');
Clock::fake('2026-10-24');
check(str_contains(call('GET', '/jobs/ready')->content(), 'B-31'), 'the next day, the 31-day job has moved to Ready by itself');
Clock::fake('2026-10-23');
$onHold = p8_try(['job_number' => 'B-HOLD', 'first_available_date' => '2026-10-25'])['row'];
call('POST', '/jobs/' . $onHold['id'] . '/status', ['to' => 'on_hold', 'note' => 'customer asked to wait']);
call('GET', '/dashboard'); // the confirmation message (which names the job) is shown once, on the first page
check(!str_contains(call('GET', '/jobs/ready')->content(), 'B-HOLD') && str_contains(call('GET', '/jobs', [], ['q' => 'B-HOLD'])->content(), 'B-HOLD'), 'a job on hold leaves Ready but stays in All Jobs');

section('Edge cases: text');
$txt = [
    'Zoë Müller-Łukasiewicz', '李小龍', 'Ольга', 'مريم', "O'Brien", 'Anne "AJ" Smith', 'Smith & Sons <Moving>', 'Emoji 😀🚚', "tab\there", 'trailing   spaces   ',
];
foreach ($txt as $i => $name) {
    $r = p8_try(['job_number' => 'T-' . $i, 'customer_first_name' => $name, 'notes' => "line1\nline2 " . $name]);
    check($r['row'] !== null && $r['row']['customer_first_name'] === trim($name), 'name ' . json_encode($name, JSON_UNESCAPED_UNICODE) . ' round-trips unchanged');
    $page = call('GET', '/jobs/' . $r['row']['id'])->content();
    check(str_contains($page, htmlspecialchars(trim($name), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) && !str_contains($page, '<Moving>'), '...and displays safely');
}
$long = str_repeat('é', 100);
check(p8_try(['job_number' => 'T-100', 'customer_first_name' => $long])['row'] !== null, '100 accented characters fit a 100-character field');
check(p8_try(['job_number' => 'T-101', 'customer_first_name' => $long . 'é'])['row'] === null, '101 are refused with a message, not an error page');
check(p8_try(['job_number' => str_repeat('A', 40)])['row'] !== null && p8_try(['job_number' => str_repeat('B', 41)])['row'] === null, 'job numbers: 40 characters pass, 41 are refused');
check(p8_try(['job_number' => 'N-5000', 'notes' => str_repeat('n', 5000)])['row'] !== null && p8_try(['job_number' => 'N-5001', 'notes' => str_repeat('n', 5001)])['row'] === null, 'notes: 5,000 characters pass, 5,001 are refused');
foreach (['A B', 'A;B', 'A<B', "A\nB", '../x', 'A\\B', 'ÅB', '-A', ''] as $badNumber) {
    check(p8_try(['job_number' => $badNumber])['row'] === null, 'job number ' . json_encode($badNumber, JSON_UNESCAPED_UNICODE) . ' is refused');
}
$dupe = p8_try(['job_number' => 'T-0']);
check($dupe['row'] !== null && (int) Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number = 'T-0'") === 1, 'a repeated job number does not create a second job');

section('Edge cases: search and sort');
$odd = ['%', '_', '\\', "'", '"', '--', '; DROP TABLE jobs', '😀', str_repeat('x', 500), '   ', 'zoë', 'ZOË', '*', '[a-z]', '?', '%%', '\\%'];
foreach ($odd as $q) {
    $res = call('GET', '/jobs', [], ['q' => $q]);
    check($res->status() === 200 && Database::fetchValue('SELECT COUNT(*) FROM jobs') > 0, 'searching for ' . json_encode(mb_substr($q, 0, 12), JSON_UNESCAPED_UNICODE) . ' is safe');
}
check(!str_contains(call('GET', '/jobs', [], ['q' => '%'])->content(), 'B-30'), 'a lone % is a literal, not "match everything"');
check(str_contains(call('GET', '/jobs', [], ['q' => 'Müller'])->content(), 'T-0'), 'searching an accented name finds it');
foreach (['sort' => ['job', 'customer', 'bogus', '', 'id; DROP', ['x']], 'dir' => ['asc', 'desc', 'sideways', '']] as $param => $values) {
    foreach ($values as $v) {
        check(call('GET', '/jobs', [], [$param => $v])->status() === 200, "{$param}=" . json_encode($v) . ' never breaks the list');
    }
}
foreach (['0', '-1', '999999', 'abc', '1e3', '2.5'] as $page) {
    check(call('GET', '/jobs', [], ['page' => $page])->status() === 200, "page={$page} never breaks the list");
}
foreach (['per_page' => '100000', 'status' => 'nope', 'balance' => 'x', 'from' => 'not-a-date', 'to' => '2026-99-99'] as $k => $v) {
    check(call('GET', '/jobs', [], [$k => $v])->status() === 200, "{$k}=" . json_encode($v) . ' is ignored safely');
}

section('Edge cases: workflow and records');
$j = p8_try(['job_number' => 'W-1', 'first_available_date' => '2026-10-30'])['row'];
$dispatch = ['to' => 'in_transit', 'carrier_name' => 'Blue Ridge', 'driver_name' => 'Marcus', 'driver_phone' => '555-010-2231'];
call('POST', '/jobs/' . $j['id'] . '/status', $dispatch);
call('POST', '/jobs/' . $j['id'] . '/status', $dispatch);
check((int) Database::fetchValue("SELECT COUNT(*) FROM job_status_history WHERE job_id = ? AND to_status = 'in_transit'", [$j['id']]) === 1, 'a double-clicked Dispatch records one history row');
check((int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'job_status_changed' AND entity_id = ?", [$j['id']]) === 1, '...and one audit entry');
call('POST', '/jobs/' . $j['id'] . '/status', ['to' => 'delivered', 'delivered_on' => '2026-10-23']);
call('POST', '/jobs/' . $j['id'] . '/status', ['to' => 'delivered', 'delivered_on' => '2026-10-23']);
check((int) Database::fetchValue("SELECT COUNT(*) FROM job_status_history WHERE job_id = ? AND to_status = 'delivered'", [$j['id']]) === 1, 'a double-clicked Deliver records one history row');
$staffUser = make_user('Staff', 'p8-staff@example.com');
sign_in($staffUser);
foreach (['in_transit' => 'Staff cannot reopen a delivered job (needs jobs.reopen)', 'booked' => 'a delivered job cannot jump straight back to booked', 'bogus' => 'an unknown status is refused'] as $to => $label) {
    call('POST', '/jobs/' . $j['id'] . '/status', ['to' => $to, 'note' => 'please'] + $dispatch);
    check(Database::fetchValue('SELECT status FROM jobs WHERE id = ?', [$j['id']]) === 'delivered', $label);
}
sign_in($admin);
call('POST', '/jobs/' . $j['id'] . '/status', ['to' => 'booked', 'note' => 'please']);
check(Database::fetchValue('SELECT status FROM jobs WHERE id = ?', [$j['id']]) === 'delivered', 'not even an administrator can skip a step: delivered -> booked is not a transition');
call('POST', '/jobs/' . $j['id'] . '/delete');
check(call('GET', '/jobs/' . $j['id'])->status() === 404, 'a deleted job is a 404 on its page');
check(call('GET', '/jobs/' . $j['id'] . '/edit')->status() === 404, '...and on its edit page');
check(call('POST', '/jobs/' . $j['id'], job_input(['job_number' => 'W-1', 'notes' => 'ghost edit']))->status() === 404 && Database::fetchValue('SELECT notes FROM jobs WHERE id = ?', [$j['id']]) !== 'ghost edit', '...and cannot be edited by posting to it');
check(call('POST', '/jobs/' . $j['id'] . '/status', ['to' => 'booked'])->status() === 404, '...or have its status changed');
check(!str_contains(call('GET', '/jobs')->content(), 'W-1<'), 'it is gone from the lists');
check(str_contains(call('GET', '/jobs/trash')->content(), 'W-1'), 'and sits in the trash');
check(call('GET', '/jobs/99999999')->status() === 404 && call('GET', '/jobs/0')->status() === 404 && call('GET', '/jobs/-1')->status() === 404, 'impossible job ids are 404s');
check(call('POST', '/jobs/' . $j['id'] . '/restore')->status() === 302 && call('GET', '/jobs/' . $j['id'])->status() === 200, 'restoring brings the job back');

section('Edge cases: imports with money');
$svc = new \App\Services\ImportService();
$mrow = static fn (string $n, string $b): array => [$n, 'Ada', 'Smith', '2026-11-01', '2026-12-01', 'Austin, TX', 'Denver, CO', '900', $b];
$csv = tmp_file(csv_text([CSV_HEAD, $mrow('MONEY-OK', '$1,200.50'), $mrow('MONEY-3DEC', '12.345'), $mrow('MONEY-EXP', '1e3')]));
$st = $svc->stage(upload_of($csv, 'm.csv'), (int) $admin['id']);
$sample = Database::fetchOne('SELECT valid_rows, invalid_rows FROM import_batches WHERE id = ?', [$st['batch']]);
check((int) $sample['valid_rows'] === 1 && (int) $sample['invalid_rows'] === 2, 'import: "$1,200.50" is understood; three decimals and exponents are problem rows');

section('Sessions: idle and absolute limits, browser fingerprint');
fresh_db();
fresh_session();
$enforce = new ReflectionMethod(\App\Core\Session::class, 'enforceLifetime');
$ua = static fn (string $agent): \App\Core\Request => \App\Core\Request::make('GET', '/dashboard', [], [], ['HTTP_USER_AGENT' => $agent]);
$startSession = static function (int $createdAgo, int $lastAgo, string $agent = 'Browser/1') use ($enforce, $ua): void {
    fresh_session();
    $_SESSION['user_id'] = 7;
    $_SESSION['_created'] = time() - $createdAgo;
    $_SESSION['_last'] = time() - $lastAgo;
    $_SESSION['_ua'] = hash('sha256', $agent);
    $enforce->invoke(null, $ua($agent));
};
Config::set('app.session.idle_minutes', 30);
Config::set('app.session.absolute_hours', 8);

$startSession(600, 60);
check(($_SESSION['user_id'] ?? null) === 7, 'a recent session stays signed in');
check(time() - (int) $_SESSION['_last'] <= 1, '...and its idle timer is reset by the request');
$startSession(3600, 29 * 60);
check(($_SESSION['user_id'] ?? null) === 7, '29 minutes idle is still signed in');
$startSession(3600, 31 * 60);
check(!isset($_SESSION['user_id']), '31 minutes idle signs the person out');
check(($_SESSION['_flash_next']['alert'] ?? $_SESSION['_flash_next']['warning'] ?? null) !== null || str_contains(json_encode($_SESSION), 'session expired'), '...and tells them why');
$startSession(8 * 3600 + 60, 10);
check(!isset($_SESSION['user_id']), 'a session older than 8 hours ends even if it is in constant use');
$startSession(7 * 3600, 10);
check(($_SESSION['user_id'] ?? null) === 7, '7 hours old and active is fine');
fresh_session();
$_SESSION['user_id'] = 7;
$_SESSION['_created'] = time() - 60;
$_SESSION['_last'] = time() - 60;
$_SESSION['_ua'] = hash('sha256', 'Browser/1');
$before = session_id();
$enforce->invoke(null, $ua('Different/9'));
check(!isset($_SESSION['user_id']), 'the same session id presented by a different browser is signed out');
check(session_id() !== $before, '...and the session id is replaced');
Config::set('app.session.idle_minutes', 5);
$startSession(3600, 6 * 60);
check(!isset($_SESSION['user_id']), 'the idle limit is read from configuration (5 minutes here)');
Config::set('app.session.idle_minutes', 30);

section('Import repository: column names are never taken from outside');
$repo = new \App\Repositories\ImportRepository();
throws(static fn () => $repo->update(1, ['status = status, user_id' => 1]), 'a made-up column expression is refused');
throws(static fn () => $repo->update(1, ['user_id' => 1]), 'a real but protected column is refused');

section('Arrays where text is expected (?x[]=1, field[]=1)');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'p8-arr@example.com');
sign_in($admin);
$warnings = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$warnings): bool {
    $warnings[] = "{$msg} ({$file}:{$line})";
    return true;
});
$arr = ['x', 'y'];
foreach (['/jobs', '/jobs/ready', '/users', '/audit', '/jobs/export/all'] as $path) {
    $res = call('GET', $path, [], ['q' => $arr, 'sort' => $arr, 'dir' => $arr, 'page' => $arr, 'per_page' => $arr, 'status' => $arr, 'role' => $arr, 'action' => $arr, 'from' => $arr, 'to' => $arr, 'user' => $arr, 'balance' => $arr, 'format' => $arr, 'do' => $arr]);
    check(in_array($res->status(), [200, 302, 404], true), "GET {$path} with array parameters answers normally");
}
$post = array_fill_keys(['job_number', 'customer_first_name', 'customer_last_name', 'from_location', 'to_location', 'first_available_date', 'pickup_date', 'balance', 'cf', 'notes', 'storage_fee', 'job_location'], $arr);
check(call('POST', '/jobs', $post)->status() === 302 && Database::fetchValue('SELECT COUNT(*) FROM jobs') == 0, 'POST /jobs with arrays is refused, creating nothing');
check(call('POST', '/settings', array_fill_keys(['company_name', 'ready_threshold_days', 'currency_symbol', 'timezone', 'page_size'], $arr))->status() === 302 && setting('page_size') === '25', 'POST /settings with arrays is refused, changing nothing');
check(call('POST', '/users', ['name' => $arr, 'email' => $arr, 'role_id' => $arr])->status() === 302 && Database::fetchValue('SELECT COUNT(*) FROM users') == 1, 'POST /users with arrays is refused');
check(call('POST', '/account/password', ['current_password' => $arr, 'password' => $arr, 'password_confirmation' => $arr])->status() === 302, 'POST /account/password with arrays is refused');
check(call('POST', '/roles', ['name' => $arr, 'description' => $arr, 'copy_from' => $arr])->status() === 302, 'POST /roles with arrays is refused');
restore_error_handler();
check($warnings === [], 'none of that raised a PHP warning' . ($warnings === [] ? '' : ': ' . $warnings[0]));
