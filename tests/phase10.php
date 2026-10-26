<?php

declare(strict_types=1);

/**
 * Client change request tests: one-click Move to In Transit / Mark as Delivered, loaded and delivery dates,
 * automatic storage fee (CF x rate), customer phone number, and the database migration behind them.
 * Included by tests/run.php (after phase9.php), which provides the fixtures and helpers.
 */

use App\Core\Clock;
use App\Core\Database;
use App\Core\Request;
use App\Database\Migrator;
use App\Repositories\JobRepository;
use App\Repositories\StatusHistoryRepository;
use App\Services\DashboardService;
use App\Services\JobService;
use App\Services\JobWorkflowService;
use App\Services\SettingsService;
use App\Services\StorageFeeService;

function p10_set(array $values): void
{
    $current = SettingsService::editable();
    $r = SettingsService::validate($values + $current);
    if ($r['errors'] !== []) {
        throw new RuntimeException('settings: ' . json_encode($r['errors']));
    }
    SettingsService::update($r['values']);
}

function p10_job(array $o = []): array
{
    return (new JobRepository())->find(mk_job($o)) ?? [];
}

function p10_fee(int $id): string
{
    return number_format((float) Database::fetchValue('SELECT storage_fee FROM jobs WHERE id = ?', [$id]), 2, '.', '');
}

// ================================================================ the fee rule

section('Storage fee: CF x rate after 30 days');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'p10-admin@example.com');
sign_in($admin);

check(StorageFeeService::rate() === '0.6' && StorageFeeService::days() === 30 && StorageFeeService::basis() === 'first_available', 'the defaults are rate 0.6, after 30 days, counted from the 1st available date');
$base = ['status' => 'booked', 'cf' => '100', 'first_available_date' => '2026-09-23', 'loaded_date' => null, 'delivery_date' => null, 'storage_rate' => null, 'storage_fee_override' => null];
check(StorageFeeService::assess($base, '2026-10-23')['fee'] === '0.00', 'exactly 30 days waited: nothing is charged yet');
check(StorageFeeService::startsOn($base) === '2026-10-24', 'the fee starts on day 31');
check(StorageFeeService::assess($base, '2026-10-24') === ['fee' => '60.00', 'rate' => '0.6', 'manual' => false], 'day 31: CF 100 x 0.6 = 60.00 (the client\'s example)');
check(StorageFeeService::assess($base, '2027-03-01')['fee'] === '60.00', '...and it is charged once, not per day');
foreach ([['12.5', '7.50'], ['33.33', '20.00'], ['0.01', '0.01'], ['999999.99', '599999.99'], ['0', '0.00'], [null, '0.00'], ['-5', '0.00']] as [$cf, $fee]) {
    check(StorageFeeService::assess(['cf' => $cf] + $base, '2026-12-01')['fee'] === $fee, 'CF ' . var_export($cf, true) . ' -> ' . $fee);
}
check(StorageFeeService::assess(['first_available_date' => '2026-12-01'] + $base, '2026-10-23')['fee'] === '0.00', 'a job whose date is still in the future owes nothing');
check(StorageFeeService::assess(['status' => 'cancelled'] + $base, '2026-12-01')['fee'] === '0.00', 'a cancelled job owes nothing');
foreach (['booked', 'in_transit', 'on_hold'] as $status) {
    check(StorageFeeService::assess(['status' => $status] + $base, '2026-12-01')['fee'] === '60.00', "an open job ({$status}) is charged");
}

$manual = StorageFeeService::assess(['storage_fee_override' => '12.34'] + $base, '2026-12-01');
check($manual === ['fee' => '12.34', 'rate' => null, 'manual' => true], 'a fee typed by hand wins over the automatic one');
check(StorageFeeService::assess(['storage_fee_override' => '0.00'] + $base, '2026-12-01')['fee'] === '0.00', 'an override of 0 waives the fee');
check(StorageFeeService::assess(['storage_fee_override' => '5'] + $base, '2026-01-01')['fee'] === '5.00', '...and applies even before the automatic fee would start');

$delivered = ['status' => 'delivered', 'delivery_date' => '2026-10-23', 'storage_rate' => null] + $base;
check(StorageFeeService::assess($delivered, '2027-06-01')['fee'] === '0.00', 'delivered on day 30: no fee, however long ago that was');
$late = ['delivery_date' => '2026-10-24'] + $delivered;
check(StorageFeeService::assess($late, '2027-06-01')['fee'] === '60.00', 'delivered on day 31: the fee is frozen at that moment');
p10_set(['storage_fee_rate' => '1']);
check(StorageFeeService::assess(['storage_rate' => '0.6'] + $late, '2027-06-01')['fee'] === '60.00', 'a later rate change does not rewrite a delivered job (it keeps the rate it was charged at)');
check(StorageFeeService::assess(['status' => 'in_transit', 'delivery_date' => null] + $base, '2027-06-01')['fee'] === '100.00', '...but an open job uses the new rate');
p10_set(['storage_fee_rate' => '0.6']);

p10_set(['storage_fee_basis' => 'loaded']);
check(StorageFeeService::assess($base, '2027-06-01')['fee'] === '0.00' && StorageFeeService::startsOn($base) === null, 'counted from the loaded date: a job that is not loaded yet owes nothing');
check(StorageFeeService::assess(['loaded_date' => '2026-09-01'] + $base, '2026-10-23')['fee'] === '60.00', '...and one loaded more than 30 days ago does');
check(StorageFeeService::assess(['loaded_date' => '2026-09-23'] + $base, '2026-10-23')['fee'] === '0.00', '...from the loaded date, not the 1st available date');
p10_set(['storage_fee_basis' => 'first_available']);
p10_set(['storage_fee_after_days' => '0']);
check(StorageFeeService::assess($base, '2026-09-24')['fee'] === '60.00' && StorageFeeService::assess($base, '2026-09-23')['fee'] === '0.00', 'after 0 days: charged from the day after the date');
p10_set(['storage_fee_after_days' => '45']);
check(StorageFeeService::assess($base, '2026-11-07')['fee'] === '0.00' && StorageFeeService::assess($base, '2026-11-08')['fee'] === '60.00', 'the number of days is configurable');
p10_set(['storage_fee_after_days' => '30', 'storage_fee_rate' => '0']);
check(StorageFeeService::assess($base, '2027-01-01')['fee'] === '0.00', 'rate 0 turns the fee off');
p10_set(['storage_fee_rate' => '0.1234']);
check(StorageFeeService::assess($base, '2027-01-01')['fee'] === '12.34', 'a rate with four decimals works (100 x 0.1234)');
p10_set(['storage_fee_rate' => '0.6']);

// ================================================================ settings validation

section('Storage fee: settings');
$good = SettingsService::editable();
$bad = static fn (string $k, string $v): bool => isset(SettingsService::validate([$k => $v] + $good)['errors'][$k]);
foreach (['0.6', '0', '1', '0.1234', '999.9999', '.5', '10'] as $v) {
    check(!$bad('storage_fee_rate', $v), "rate {$v} is accepted");
}
foreach (['-1', '1e3', '0.12345', '1001', 'abc', '', '0,6', '$1', '1 000', '0.6x'] as $v) {
    check($bad('storage_fee_rate', $v), 'rate ' . json_encode($v) . ' is refused');
}
check(SettingsService::validate(['storage_fee_rate' => '0.60'] + $good)['values']['storage_fee_rate'] === '0.6' && SettingsService::validate(['storage_fee_rate' => '2.0'] + $good)['values']['storage_fee_rate'] === '2', 'the rate is stored without trailing zeros');
$cents = static fn (string $v) => SettingsService::validate(['storage_fee_rate' => $v] + $good);
foreach (['60c' => '0.6', '60 cents' => '0.6', '60¢' => '0.6', '0.5c' => '0.005', '5 C' => '0.05', '100c' => '1'] as $in => $out) {
    check(($cents($in)['values']['storage_fee_rate'] ?? null) === $out && !isset($cents($in)['errors']['storage_fee_rate']), "rate {$in} is read as cents -> {$out} dollars per CF");
}
foreach (['c', '-5c', 'abc c', '60cc', '999999c'] as $v) {
    check(isset($cents($v)['errors']['storage_fee_rate']), 'cent rate ' . json_encode($v) . ' is refused');
}
foreach (['0', '30', '3650'] as $v) {
    check(!$bad('storage_fee_after_days', $v), "{$v} days is accepted");
}
foreach (['-1', '3651', '1.5', 'x', ''] as $v) {
    check($bad('storage_fee_after_days', $v), 'days ' . json_encode($v) . ' is refused');
}
check($bad('storage_fee_basis', 'whenever') && $bad('storage_fee_basis', '') && !$bad('storage_fee_basis', 'loaded'), 'the basis must be one of the two choices');
$page = call('GET', '/settings')->content();
check(str_contains($page, 'name="storage_fee_rate"') && str_contains($page, 'name="storage_fee_after_days"') && str_contains($page, 'name="storage_fee_basis"') && str_contains($page, 'CF 100 &times; rate 0.6 = 60'), 'the settings page has the three fields and the worked example');
check(str_contains($page, 'value="0.6"') && str_contains($page, '<option value="first_available" selected>'), '...showing the current values');
$res = p7_post_settings(['storage_fee_rate' => '0.75']);
check($res->status() === 302 && setting('storage_fee_rate') === '0.75', 'saving a new rate works');
$a = last_audit('settings_updated');
check(str_contains((string) $a['old_values'], '0.6') && str_contains((string) $a['new_values'], '0.75'), '...and is audited with old and new value');
p7_post_settings(['storage_fee_rate' => '-3']);
check(setting('storage_fee_rate') === '0.75' && str_contains(call('GET', '/settings')->content(), 'Enter a rate such as 0.6'), 'a bad rate is refused with a message and changes nothing');
p10_set(['storage_fee_rate' => '0.6']);

// ================================================================ stored fees follow jobs

section('Storage fee: stored on the job, kept current');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'p10-a2@example.com');
$staff = make_user('Staff', 'p10-s2@example.com');
sign_in($admin);
$svc = new JobService();
$mk = static fn (array $o): int => $svc->create(job_input($o));

$old = $mk(['job_number' => 'SF-OLD', 'cf' => '100', 'first_available_date' => '2026-08-01', 'storage_fee_override' => '']);
check(p10_fee($old) === '60.00', 'a job created already past 30 days gets its fee at once: 100 x 0.6 = 60.00');
$new = $mk(['job_number' => 'SF-NEW', 'cf' => '100', 'first_available_date' => '2026-10-10', 'storage_fee_override' => '']);
check(p10_fee($new) === '0.00', 'a recent job starts at 0');
$nocf = $mk(['job_number' => 'SF-NOCF', 'cf' => '', 'first_available_date' => '2026-08-01', 'storage_fee_override' => '']);
check(p10_fee($nocf) === '0.00' && str_contains(storage_fee_note((new JobRepository())->find($nocf)), 'needs a CF'), 'a job without CF owes nothing and says why');
$hand = $mk(['job_number' => 'SF-HAND', 'cf' => '100', 'first_available_date' => '2026-08-01', 'storage_fee_override' => '12.34']);
check(p10_fee($hand) === '12.34', 'a typed override is used as given');
$ids = [$old, $new, $nocf, $hand];

Clock::fake('2026-11-09'); // SF-NEW is now 30 days past its date, 2026-11-10 is day 31
$before = (string) (new JobRepository())->find($new)['updated_at'];
StorageFeeService::refreshIfDue();
check(p10_fee($new) === '0.00', 'on day 30 the fee has not started');
Clock::fake('2026-11-10');
StorageFeeService::refreshIfDue();
check(p10_fee($new) === '60.00', 'on day 31 the daily check charges it: no click, no cron needed');
check((string) (new JobRepository())->find($new)['updated_at'] === $before, 'the recalculation does not touch updated_at (an open edit form is not made stale)');
check(setting('storage_fee_assessed_on') === '2026-11-10', 'the day of the last check is remembered');
Database::execute('UPDATE jobs SET storage_fee = 1 WHERE id = ?', [$new]);
StorageFeeService::refreshIfDue();
check(p10_fee($new) === '1.00', 'the check runs only once per day');
check(StorageFeeService::refresh() >= 1 && p10_fee($new) === '60.00', 'a forced refresh (cli/storage-fees.php) puts it right again');
check(p10_fee($hand) === '12.34', 'an override is never recalculated');

p10_set(['storage_fee_rate' => '1']);
check(p10_fee($old) === '100.00' && p10_fee($new) === '100.00' && p10_fee($hand) === '12.34' && p10_fee($nocf) === '0.00', 'changing the rate re-assesses every open job immediately');
p10_set(['storage_fee_after_days' => '365']);
check(p10_fee($old) === '0.00' && p10_fee($new) === '0.00', 'changing the number of days does too');
p10_set(['storage_fee_after_days' => '30', 'storage_fee_rate' => '0.6']);
check(p10_fee($old) === '60.00', 'and putting it back restores the fees');

// edits
$j = (new JobRepository())->find($old);
$edit = static function (int $id, array $over) use ($svc): array {
    $j = (new JobRepository())->find($id);
    return $svc->update($id, job_input($over + array_intersect_key($j, array_flip(['job_number', 'first_available_date', 'cf'])) + ['storage_fee_override' => '']), (string) $j['updated_at']);
};
$r = $edit($old, ['job_number' => 'SF-OLD', 'first_available_date' => '2026-08-01', 'cf' => '200']);
check($r['ok'] && p10_fee($old) === '120.00', 'changing the CF recalculates: 200 x 0.6 = 120.00');
$r = $edit($old, ['job_number' => 'SF-OLD', 'first_available_date' => '2026-08-01', 'cf' => '200', 'storage_fee_override' => '5']);
check($r['ok'] && p10_fee($old) === '5.00', 'an override entered on the edit form replaces it');
$r = $edit($old, ['job_number' => 'SF-OLD', 'first_available_date' => '2026-08-01', 'cf' => '200', 'storage_fee_override' => '']);
check($r['ok'] && p10_fee($old) === '120.00' && Database::fetchValue('SELECT storage_fee_override FROM jobs WHERE id = ?', [$old]) === null, 'clearing it goes back to automatic');
$r = $edit($old, ['job_number' => 'SF-OLD', 'first_available_date' => '2026-12-31', 'cf' => '200']);
check($r['ok'] && p10_fee($old) === '0.00', 'moving the 1st available date later stops the fee');
$edit($old, ['job_number' => 'SF-OLD', 'first_available_date' => '2026-08-01', 'cf' => '200']);
$audit = last_audit('job_updated');
check(!str_contains((string) $audit['new_values'], '"storage_fee"'), 'the automatic fee itself is not audited as an edit (only the inputs are)');

// staff cannot set it or see it
sign_in($staff);
$sj = $svc->create(job_input(['job_number' => 'SF-STAFF', 'cf' => '100', 'first_available_date' => '2026-08-01', 'storage_fee_override' => '1']));
check(p10_fee($sj) === '60.00' && Database::fetchValue('SELECT storage_fee_override FROM jobs WHERE id = ?', [$sj]) === null, 'Staff cannot set an override, but the automatic fee is still charged');
$view = $svc->find($sj);
check(!array_key_exists('storage_fee', $view) && !array_key_exists('storage_fee_override', $view) && !array_key_exists('storage_rate', $view), 'and the service hides the fee, the override and the rate from Staff');
sign_in($admin);

// workflow
Clock::fake('2026-10-23');
$wf = new JobWorkflowService();
$w = $mk(['job_number' => 'SF-WF', 'cf' => '100', 'first_available_date' => '2026-10-01', 'storage_fee_override' => '']);
check(p10_fee($w) === '0.00', 'a job 22 days past its date: no fee yet');
Clock::fake('2026-11-05'); // 35 days after 2026-10-01
$wf->change($w, 'in_transit', []);
check(p10_fee($w) === '60.00', 'moving to In Transit re-assesses: day 35 -> 60.00');
$wf->change($w, 'delivered', []);
check(p10_fee($w) === '60.00' && (float) Database::fetchValue('SELECT storage_rate FROM jobs WHERE id = ?', [$w]) === 0.6, 'delivery on day 35 freezes the fee and the rate');
Clock::fake('2027-01-01');
p10_set(['storage_fee_rate' => '3']);
StorageFeeService::refresh();
check(p10_fee($w) === '60.00', 'the delivered job stays at 60.00 after the rate changed and a year passed');
$wf->change($w, 'in_transit', ['note' => 'Wrong address']);
check(p10_fee($w) === '300.00', 'reopening it makes it an open job again, charged at today\'s rate (100 x 3)');
$wf->change($w, 'cancelled', ['note' => 'Customer cancelled']);
check(p10_fee($w) === '0.00', 'a cancelled job owes nothing');
p10_set(['storage_fee_rate' => '0.6']);
$early = $mk(['job_number' => 'SF-EARLY', 'cf' => '100', 'first_available_date' => '2026-12-20', 'storage_fee_override' => '']);
Clock::fake('2027-01-10');
$wf->change($early, 'in_transit', []);
$wf->change($early, 'delivered', []);
check(p10_fee($early) === '0.00', 'delivered before day 31: no fee at all');

// dashboard
Clock::fake('2026-11-10');
fresh_db();
fresh_session();
$admin = make_user('Admin', 'p10-a3@example.com');
sign_in($admin);
$svc = new JobService();
$a = $svc->create(job_input(['job_number' => 'DB-A', 'cf' => '100', 'first_available_date' => '2026-08-01', 'storage_fee_override' => '']));
$b = $svc->create(job_input(['job_number' => 'DB-B', 'cf' => '50', 'first_available_date' => '2026-08-01', 'storage_fee_override' => '']));
$c = $svc->create(job_input(['job_number' => 'DB-C', 'cf' => '70', 'first_available_date' => '2026-08-01', 'storage_fee_override' => '']));
(new JobWorkflowService())->change($c, 'in_transit', []);
(new JobWorkflowService())->change($c, 'delivered', []);
$money = (new JobRepository())->moneyTotals();
check($money['storage_fee'] === '90' || (float) $money['storage_fee'] === 90.0, 'the dashboard total counts the fees of open jobs only (60 + 30; the delivered job is not "still owed")');

// ================================================================ one click, counts

section('One-click buttons and the dashboard counts');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$mgr = make_user('Manager', 'p10-m@example.com');
$stf = make_user('Staff', 'p10-st@example.com');
sign_in($stf);
$j1 = mk_job(['job_number' => 'OC-1', 'first_available_date' => '2026-10-30', 'cf' => '10']);
$j2 = mk_job(['job_number' => 'OC-2', 'first_available_date' => '2026-12-30', 'cf' => '10']);
$j3 = mk_job(['job_number' => 'OC-3', 'first_available_date' => '2026-10-25', 'cf' => '10']);
$counts = static fn (): array => (new DashboardService())->build(true)['counts'];
$c0 = $counts();
check($c0['ready'] === 2 && $c0['later'] === 1 && $c0['transit'] === 0 && $c0['delivered'] === 0 && $c0['all'] === 3, 'start: 2 ready, 1 later, 0 in transit, 0 delivered (3 jobs)');

$page = call('GET', '/jobs/ready')->body();
check(substr_count($page, 'Move to In Transit') === 2 && str_contains($page, 'data-confirm="Move job OC-1 to In Transit? Today (Oct 23, 2026) will be saved as the loaded date."'), 'every ready row has the button, and it says what date will be saved');
check(str_contains(call('GET', '/jobs/more-than-30')->body(), 'Move to In Transit'), 'the More Than 30 Days list has it too');
$r = call('POST', "/jobs/{$j1}/status", ['to' => 'in_transit']);
check($r->status() === 302 && (new JobRepository())->find($j1)['loaded_date'] === '2026-10-23', 'one click: In Transit with today as the loaded date');
$c1 = $counts();
check($c1['ready'] === 1 && $c1['later'] === 1 && $c1['transit'] === 1 && $c1['delivered'] === 0 && $c1['all'] === 3, 'the dashboard follows at once: ready 2 -> 1, in transit 0 -> 1, total unchanged');
check(numbers('transit') === ['OC-1'] && numbers('ready') === ['OC-3'], 'the job is in the In Transit list and left Ready');
$tpage = call('GET', '/jobs/in-transit')->body();
check(str_contains($tpage, 'OC-1') && str_contains($tpage, 'Mark as Delivered') && str_contains(plain($tpage), 'Loaded') && str_contains(plain($tpage), 'Oct 23, 2026'), 'In Transit shows it with a Mark as Delivered button and its loaded date');
check(!str_contains($tpage, 'Move to In Transit'), 'and no Move to In Transit');
Clock::fake('2026-10-26');
$r = call('POST', "/jobs/{$j1}/status", ['to' => 'delivered']);
$job = (new JobRepository())->find($j1);
check($r->status() === 302 && $job['delivery_date'] === '2026-10-26' && $job['loaded_date'] === '2026-10-23', 'one click: Delivered with today as the delivery date; the loaded date is kept');
$c2 = $counts();
check($c2['transit'] === 0 && $c2['delivered'] === 1 && $c2['ready'] === 1 && $c2['all'] === 3, 'the dashboard follows: in transit 1 -> 0, delivered 0 -> 1');
$dpage = call('GET', '/jobs/delivered')->body();
check(str_contains($dpage, 'OC-1') && str_contains(plain($dpage), 'Oct 26, 2026') && !str_contains($dpage, 'Mark as Delivered'), 'Delivered shows the delivery date and no button');
check(array_sum(array_map(static fn (string $b): int => (new JobRepository())->count(\App\Services\JobCriteria::forBucket($b)), ['ready', 'later', 'transit', 'delivered'])) === 3, 'every job is in exactly one section: the section counts add up to the total');

$r = call('POST', "/jobs/{$j2}/status", ['to' => 'delivered']);
check((new JobRepository())->find($j2)['status'] === 'booked' && ($_SESSION['_flash_next']['alert']['type'] ?? '') === 'danger', 'delivering a job that was never moved is refused');
call('POST', "/jobs/{$j3}/status", ['to' => 'in_transit']);
call('POST', "/jobs/{$j3}/status", ['to' => 'in_transit']);
check((int) Database::fetchValue("SELECT COUNT(*) FROM job_status_history WHERE job_id = ?", [$j3]) === 1 && ($_SESSION['_flash_next']['alert']['type'] ?? '') === 'danger', 'a double click moves it once and writes one history row');
$noToken = real_router()->dispatch(Request::make('POST', "/jobs/{$j2}/status", ['to' => 'in_transit']));
check($noToken->status() === 403 && (new JobRepository())->find($j2)['status'] === 'booked', 'a click without a CSRF token does nothing');
make_role('ViewerOnly', ['jobs.view']);
fresh_session();
sign_in(make_user('ViewerOnly', 'p10-v@example.com'));
check(call('POST', "/jobs/{$j2}/status", ['to' => 'in_transit'])->status() === 403 && (new JobRepository())->find($j2)['status'] === 'booked', 'a user without jobs.status cannot do it, even by posting');
check(!str_contains(call('GET', "/jobs/{$j2}")->body(), 'Move to In Transit'), '...and the button is not shown');
fresh_session();
sign_in($mgr);
$details = call('GET', "/jobs/{$j2}")->body();
check(str_contains($details, 'Move to In Transit') && !str_contains($details, 'statusModal-in_transit'), 'the details page has the same one-click button, without a dialog');

// ================================================================ phone

section('Customer phone number');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'p10-a4@example.com');
sign_in($admin);
$form = call('GET', '/jobs/create')->content();
check(str_contains($form, 'name="customer_phone"') && str_contains($form, 'type="tel"') && !preg_match('/name="customer_phone"[^>]*required/', $form), 'Add Job has an optional phone field (a tel input, so phones show the dial pad)');
$r = call('POST', '/jobs', job_input(['job_number' => 'PH-1', 'customer_phone' => '(555) 010-4477']));
$ph = (new JobRepository())->find((int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'PH-1'"));
check($r->status() === 302 && $ph['customer_phone'] === '(555) 010-4477', 'the number is saved as typed');
call('POST', '/jobs', job_input(['job_number' => 'PH-2', 'customer_phone' => '  ']));
check(Database::fetchValue("SELECT customer_phone FROM jobs WHERE job_number = 'PH-2'") === null, 'a blank number is stored as NULL');
foreach (['abc', '123', '555-0100; DROP TABLE jobs', '<b>5551234567</b>', str_repeat('5', 31)] as $badPhone) {
    call('POST', '/jobs', job_input(['job_number' => 'PH-X', 'customer_phone' => $badPhone]));
    check(Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number = 'PH-X'") == 0 && isset($_SESSION['_flash_next']['errors']['customer_phone']), 'phone ' . json_encode($badPhone) . ' is refused with a message');
}
$id = (int) $ph['id'];
$details = call('GET', "/jobs/{$id}")->body();
check(str_contains($details, 'Customer phone') && str_contains($details, 'href="tel:5550104477"') && str_contains($details, '(555) 010-4477'), 'Job Details shows it as a tap-to-call link');
$list = call('GET', '/jobs')->body();
check(str_contains($list, 'href="tel:5550104477"'), 'the job lists show it under the customer name, also as a link');
check(str_contains(call('GET', '/jobs/more-than-30')->body(), 'href="tel:5550104477"') && !str_contains(call('GET', '/jobs/ready')->body(), 'tel:5550104477'), 'the section lists show it too, and only for the jobs they contain');
$edit = call('GET', "/jobs/{$id}/edit")->content();
check(str_contains($edit, 'value="(555) 010-4477"'), 'Edit Job shows the saved number');
$loaded = (string) $ph['updated_at'];
call('POST', "/jobs/{$id}", job_input(['job_number' => 'PH-1', 'customer_phone' => '555.010.9999', 'loaded_at' => $loaded]));
check((new JobRepository())->find($id)['customer_phone'] === '555.010.9999', 'it can be changed on Edit Job');
$loaded = (string) (new JobRepository())->find($id)['updated_at'];
call('POST', "/jobs/{$id}", job_input(['job_number' => 'PH-1', 'customer_phone' => '', 'loaded_at' => $loaded]));
check((new JobRepository())->find($id)['customer_phone'] === null, '...or removed');
call('POST', "/jobs/{$id}", job_input(['job_number' => 'PH-1', 'customer_phone' => '(555) 010-4477', 'loaded_at' => (string) (new JobRepository())->find($id)['updated_at']]));
check(str_contains(call('GET', '/jobs', [], ['q' => '0104477'])->body(), 'PH-1') && !str_contains(call('GET', '/jobs', [], ['q' => '0109999'])->body(), 'PH-1'), 'searching part of the number finds the job');
$csv = (new \App\Services\ExportService())->csv(\App\Services\JobCriteria::forBucket('all'), true)->content();
check(str_contains($csv, 'Customer Phone') && str_contains($csv, '(555) 010-4477'), 'the export has a Customer Phone column');
check(in_array('Customer Phone', ExportServiceColumns::labels(), true), 'the import template has it too');
$svc = new \App\Services\ImportService();
$file = tmp_file(csv_text([['Job #', 'Customer First Name', 'Customer Last Name', 'Customer Phone', '1st Available Date', 'From', 'To', 'Phone', 'Status', 'Delivered Date'], ['IMP-PH', 'A', 'B', '(555) 010-1212', '2026-12-01', 'X', 'Y', '', '', ''], ['IMP-BAD', 'A', 'B', 'nope', '2026-12-01', 'X', 'Y', '', '', '']]));
$st = $svc->stage(upload_of($file, 'phone.csv'), (int) $admin['id']);
$batch = Database::fetchOne('SELECT valid_rows, invalid_rows FROM import_batches WHERE id = ?', [$st['batch']]);
check((int) $batch['valid_rows'] === 1 && (int) $batch['invalid_rows'] === 1, 'import: a Customer Phone column is read and validated');
$svc->commit((int) $st['batch'], (int) $admin['id']);
check(Database::fetchValue("SELECT customer_phone FROM jobs WHERE job_number = 'IMP-PH'") === '(555) 010-1212', '...and saved on the new job (the plain Phone column still means the driver)');

// ================================================================ dates

section('Loaded date and delivery date');
$wf = new JobWorkflowService();
$t = mk_job(['job_number' => 'DT-1', 'first_available_date' => '2026-10-30', 'cf' => '10']);
check(!str_contains(call('GET', "/jobs/{$t}/edit")->content(), 'name="loaded_date"'), 'a booked job has no date fields on Edit');
$wf->change($t, 'in_transit', []);
$f = call('GET', "/jobs/{$t}/edit")->content();
check(str_contains($f, 'name="loaded_date"') && str_contains($f, 'value="2026-10-23"') && !str_contains($f, 'name="delivery_date"'), 'an in-transit job shows the loaded date (set by the click) and no delivery date');
$edit = static function (int $id, array $over): void {
    $j = (new JobRepository())->find($id);
    call('POST', "/jobs/{$id}", job_input(['job_number' => $j['job_number'], 'first_available_date' => substr((string) $j['first_available_date'], 0, 10), 'loaded_at' => (string) $j['updated_at']] + $over));
};
$edit($t, ['loaded_date' => '2026-10-20']);
check((new JobRepository())->find($t)['loaded_date'] === '2026-10-20', 'the loaded date can be corrected if the click was late');
$edit($t, ['loaded_date' => '2026-10-24']);
check((new JobRepository())->find($t)['loaded_date'] === '2026-10-20' && isset($_SESSION['_flash_next']['errors']['loaded_date']), 'but not into the future');
$edit($t, ['loaded_date' => 'yesterday']);
check(isset($_SESSION['_flash_next']['errors']['loaded_date']), 'a non-date is refused');
$wf->change($t, 'delivered', []);
$f = call('GET', "/jobs/{$t}/edit")->content();
check(str_contains($f, 'name="delivery_date"') && str_contains($f, 'value="2026-10-23"'), 'a delivered job shows both dates');
$edit($t, ['loaded_date' => '2026-10-20', 'delivery_date' => '2026-10-19']);
check(isset($_SESSION['_flash_next']['errors']['delivery_date']) && (new JobRepository())->find($t)['delivery_date'] === '2026-10-23', 'delivery before loading is refused');
$edit($t, ['loaded_date' => '2026-10-20', 'delivery_date' => '']);
check(isset($_SESSION['_flash_next']['errors']['delivery_date']), 'a delivered job must keep a delivery date');
$edit($t, ['loaded_date' => '2026-10-20', 'delivery_date' => '2026-10-22']);
$j = (new JobRepository())->find($t);
check($j['delivery_date'] === '2026-10-22' && $j['delivered_at'] === '2026-10-22 00:00:00', 'the delivery date can be corrected (and the list stays consistent)');
$a = last_audit('job_updated');
check(str_contains((string) $a['old_values'], '2026-10-23') && str_contains((string) $a['new_values'], '2026-10-22'), '...with an audit entry holding old and new');
check(str_contains(plain(call('GET', "/jobs/{$t}")->body()), 'Delivery date Oct 22, 2026') && str_contains(plain(call('GET', "/jobs/{$t}")->body()), 'Loaded date Oct 20, 2026'), 'Job Details shows both dates');
// sorting by the date columns
$s1 = mk_job(['job_number' => 'DT-S1', 'status' => 'in_transit', 'loaded_date' => '2026-10-01', 'in_transit_at' => '2026-10-01 10:00:00', 'first_available_date' => '2026-09-01']);
$s2 = mk_job(['job_number' => 'DT-S2', 'status' => 'in_transit', 'loaded_date' => '2026-10-15', 'in_transit_at' => '2026-10-15 10:00:00', 'first_available_date' => '2026-09-01']);
$byLoaded = (new JobRepository())->fetch(\App\Services\JobCriteria::fromRequest(Request::make('GET', '/', [], ['sort' => 'dispatched', 'dir' => 'desc']), 'transit', true), 50, 0);
check(array_column($byLoaded, 'job_number') === ['DT-S2', 'DT-S1'], 'the In Transit list sorts by loaded date (newest first by default)');

// forging
sign_in(make_user('Staff', 'p10-forge@example.com'));
$b = mk_job(['job_number' => 'DT-F', 'first_available_date' => '2026-11-30']);
$bj = (new JobRepository())->find($b);
call('POST', "/jobs/{$b}", job_input(['job_number' => 'DT-F', 'first_available_date' => '2026-11-30', 'loaded_at' => (string) $bj['updated_at'], 'loaded_date' => '2026-10-01', 'delivery_date' => '2026-10-02', 'storage_rate' => '9', 'storage_fee' => '999']));
$bj = (new JobRepository())->find($b);
check($bj['loaded_date'] === null && $bj['delivery_date'] === null && $bj['storage_rate'] === null && (float) $bj['storage_fee'] === 0.0, 'dates, the rate and the fee cannot be forged onto a booked job through the edit form');

// ================================================================ migration

section('Migration 008 on a database that already has jobs');
$dir = sys_get_temp_dir() . '/jt-mig-' . bin2hex(random_bytes(4));
mkdir($dir);
foreach (glob(BASE_PATH . '/database/migrations/00[1-7]_*.sql') as $f) {
    copy($f, $dir . '/' . basename($f));
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Database::setConnection($pdo);
(new Migrator($dir))->migrate();
$pdo->exec("INSERT INTO roles (id, name, description, is_system, created_at, updated_at) VALUES (1, 'R', '', 0, '2026-01-01', '2026-01-01')");
$now = '2026-01-01 00:00:00';
$ins = $pdo->prepare("INSERT INTO jobs (job_number, customer_first_name, customer_last_name, first_available_date, from_location, to_location, status, in_transit_at, delivered_at, storage_fee, balance, created_at, updated_at) VALUES (?, 'A', 'B', '2026-01-01', 'X', 'Y', ?, ?, ?, ?, 0, ?, ?)");
$ins->execute(['OLD-BOOKED', 'booked', null, null, 0, $now, $now]);
$ins->execute(['OLD-TRANSIT', 'in_transit', '2026-03-05 09:30:00', null, 12.5, $now, $now]);
$ins->execute(['OLD-DELIVERED', 'delivered', '2026-03-05 09:30:00', '2026-03-20 00:00:00', 45, $now, $now]);
copy(BASE_PATH . '/database/migrations/008_add_phone_dates_and_storage_fee.sql', $dir . '/008_add_phone_dates_and_storage_fee.sql');
$applied = (new Migrator($dir))->migrate();
check($applied === ['008_add_phone_dates_and_storage_fee.sql'], 'only the new migration runs on an existing database');
$rows = array_column($pdo->query('SELECT * FROM jobs')->fetchAll(), null, 'job_number');
check($rows['OLD-BOOKED']['loaded_date'] === null && $rows['OLD-BOOKED']['storage_fee_override'] === null && $rows['OLD-BOOKED']['customer_phone'] === null, 'a booked job gets empty new columns');
check($rows['OLD-TRANSIT']['loaded_date'] === '2026-03-05' && $rows['OLD-TRANSIT']['delivery_date'] === null, 'an in-transit job gets its loaded date from the old dispatch time');
check($rows['OLD-DELIVERED']['loaded_date'] === '2026-03-05' && $rows['OLD-DELIVERED']['delivery_date'] === '2026-03-20', 'a delivered job gets both dates');
check((float) $rows['OLD-TRANSIT']['storage_fee_override'] === 12.5 && (float) $rows['OLD-DELIVERED']['storage_fee_override'] === 45.0 && $rows['OLD-BOOKED']['storage_fee_override'] === null, 'storage fees typed by hand before the change become overrides (nothing the client entered is lost)');
check((float) $rows['OLD-TRANSIT']['storage_fee'] === 12.5, '...and the stored fee is unchanged');
check((new Migrator($dir))->migrate() === [], 'running it again does nothing');
Database::setConnection(null);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);
fresh_db();
fresh_session();

class ExportServiceColumns
{
    /** @return array<int,string> */
    public static function labels(): array
    {
        return \App\Services\ExportService::templateRows(true)[0];
    }
}
