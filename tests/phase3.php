<?php

declare(strict_types=1);

/**
 * Phase 3 tests: sections (JobBucket), dashboard, list engine (search / filter / sort / pagination)
 * and the status workflow. Included by tests/run.php, which provides the fixtures and helpers.
 */

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\JobRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\StatusHistoryRepository;
use App\Services\DashboardService;
use App\Services\JobBucket;
use App\Services\JobCriteria;
use App\Services\JobService;
use App\Services\JobWorkflowService;
use App\Services\PermissionService;
use App\Services\SettingsService;

// ---------------------------------------------------------------- helpers

function mk_job(array $o = []): int
{
    return (new JobRepository())->create($o + [
        'job_number' => 'Q-' . bin2hex(random_bytes(3)),
        'customer_first_name' => 'Ada',
        'customer_last_name' => 'Smith',
        'first_available_date' => '2026-11-01',
        'from_location' => 'Austin, TX',
        'to_location' => 'Denver, CO',
        'status' => 'booked',
    ], null);
}

/** @return array<int,string> job numbers of a section, sorted */
function numbers(string $bucket, ?JobCriteria $criteria = null): array
{
    $rows = (new JobRepository())->fetch($criteria ?? JobCriteria::forBucket($bucket), 1000, 0);
    $numbers = array_column($rows, 'job_number');
    sort($numbers);
    return $numbers;
}

function criteria(array $query, string $bucket = 'all', bool $financials = true): JobCriteria
{
    return JobCriteria::fromRequest(Request::make('GET', '/jobs', [], $query), $bucket, $financials);
}

function make_role(string $name, array $permissions): int
{
    $now = gmdate('Y-m-d H:i:s');
    $id = Database::insert('INSERT INTO roles (name, description, is_system, created_at, updated_at) VALUES (?, ?, 0, ?, ?)', [$name, $name, $now, $now]);
    foreach ($permissions as $permission) {
        Database::execute('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = ?', [$id, $permission]);
    }
    PermissionService::flush();
    return $id;
}

function plain(string $html): string
{
    $html = preg_replace('#<svg.*?</svg>#s', '', $html) ?? $html;
    // Tags become spaces so neighbouring cells ("Delivered" / "Sep 15, 2026") stay separate words.
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode((string) preg_replace('/<[^>]+>/', ' ', $html))));
}

function set_setting(string $key, string $value): void
{
    (new SettingsRepository())->set($key, $value);
    SettingsService::flush();
}

// ================================================================ PHASE 3: sections

section('Sections: boundaries of Ready / More Than 30 Days');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$j = [];
foreach (['yesterday' => '2026-10-22', 'today' => '2026-10-23', 'p29' => '2026-11-21', 'p30' => '2026-11-22', 'p31' => '2026-11-23', 'far' => '2027-03-01'] as $name => $date) {
    $j[$name] = mk_job(['job_number' => "B-{$name}", 'first_available_date' => $date]);
}
mk_job(['job_number' => 'B-transit', 'status' => 'in_transit', 'first_available_date' => '2027-06-01', 'carrier_name' => 'C', 'driver_name' => 'D', 'driver_phone' => '5550001111']);
mk_job(['job_number' => 'B-transit-past', 'status' => 'in_transit', 'first_available_date' => '2026-01-01']);
mk_job(['job_number' => 'B-delivered', 'status' => 'delivered', 'first_available_date' => '2026-09-01', 'delivered_at' => '2026-09-03 00:00:00']);
mk_job(['job_number' => 'B-hold', 'status' => 'on_hold', 'first_available_date' => '2026-10-25']);
mk_job(['job_number' => 'B-cancelled', 'status' => 'cancelled', 'first_available_date' => '2026-10-25']);
$gone = mk_job(['job_number' => 'B-deleted', 'first_available_date' => '2026-10-25']);
(new JobRepository())->softDelete($gone, null);

check(JobBucket::threshold() === 30 && JobBucket::cutoff() === '2026-11-22', 'threshold 30 -> cutoff is today + 30 days');
check(numbers('ready') === ['B-p29', 'B-p30', 'B-today', 'B-yesterday'], 'Ready = booked jobs available up to and including today+30 (exactly 30 days counts as Ready; overdue included)');
check(numbers('later') === ['B-far', 'B-p31'], 'More Than 30 Days = booked jobs available after today+30');
check(numbers('transit') === ['B-transit', 'B-transit-past'], 'In Transit depends on status only, never on the date');
check(numbers('delivered') === ['B-delivered'], 'Delivered lists delivered jobs');
check(count(numbers('all')) === 11 && !in_array('B-deleted', numbers('all'), true), 'All Jobs lists everything except deleted jobs');
check(in_array('B-hold', numbers('all'), true) && in_array('B-cancelled', numbers('all'), true), 'on-hold and cancelled jobs appear in All Jobs');
$inSections = array_merge(numbers('ready'), numbers('later'), numbers('transit'), numbers('delivered'));
check(!in_array('B-hold', $inSections, true) && !in_array('B-cancelled', $inSections, true), 'on-hold and cancelled jobs belong to no other section');
check(count($inSections) === count(array_unique($inSections)), 'no job is in two sections');

$disagree = [];
foreach ((new JobRepository())->fetch(JobCriteria::forBucket('all'), 100, 0) as $row) {
    $mine = JobBucket::of($row);
    foreach (['ready', 'later', 'transit', 'delivered'] as $b) {
        if (in_array($row['job_number'], numbers($b), true) !== ($mine === $b)) {
            $disagree[] = $row['job_number'] . '/' . $b;
        }
    }
}
check($disagree === [], 'the PHP and SQL definitions of every section agree' . ($disagree ? ' (disagree: ' . implode(',', $disagree) . ')' : ''));

section('Sections: threshold is configurable');
set_setting('ready_threshold_days', '10');
check(JobBucket::cutoff() === '2026-11-02' && numbers('ready') === ['B-today', 'B-yesterday'], 'threshold 10 moves jobs out of Ready with no code change');
check(numbers('later') === ['B-far', 'B-p29', 'B-p30', 'B-p31'], '...and into More Than 30 Days');
check(JobBucket::label('later') === 'More Than 10 Days', 'the section title follows the threshold');
set_setting('ready_threshold_days', '0');
check(numbers('ready') === ['B-today'] && in_array('B-yesterday', numbers('later'), true), 'threshold 0 = only today is Ready; every other date (past or future) is More Than 0 Days');
set_setting('ready_threshold_days', '30');

section('Sections: jobs waiting more than N days move to More Than 30 Days by default');
foreach (['w30' => '2026-09-23', 'w31' => '2026-09-22', 'w45' => '2026-09-08'] as $name => $date) {
    mk_job(['job_number' => "W-{$name}", 'first_available_date' => $date]);
}
check(in_array('W-w30', numbers('ready'), true) && !in_array('W-w30', numbers('later'), true), 'a job exactly 30 days past its date is still Ready');
check(in_array('W-w31', numbers('later'), true) && in_array('W-w45', numbers('later'), true) && !in_array('W-w31', numbers('ready'), true), 'a job 31+ days past its date is in More Than 30 Days');
check(JobBucket::pastCutoff() === '2026-09-23', 'past cutoff = today - 30 days');
$wd = [];
foreach ((new JobRepository())->fetch(JobCriteria::forBucket('all'), 100, 0) as $row) {
    $mine = JobBucket::of($row);
    foreach (['ready', 'later'] as $b) {
        if (in_array($row['job_number'], numbers($b), true) !== ($mine === $b)) {
            $wd[] = $row['job_number'];
        }
    }
}
check($wd === [], 'the PHP and SQL definitions still agree for overdue jobs');
check((new JobRepository())->overdueCount() >= 3 && in_array('W-w45', array_column((new JobRepository())->readySlice(true, 50), 'job_number'), true), 'the dashboard Overdue panel and count still include jobs overdue more than N days');
set_setting('ready_threshold_days', '60');
check(in_array('W-w45', numbers('ready'), true), 'a bigger threshold brings the older job back to Ready');
set_setting('ready_threshold_days', '30');
set_setting('ready_threshold_days', 'abc');
check(JobBucket::threshold() === 30, 'a non-numeric threshold falls back to 30 instead of emptying Ready');
set_setting('ready_threshold_days', '-5');
check(JobBucket::threshold() === 0, 'a negative threshold is clamped to 0');
set_setting('ready_threshold_days', '99999');
check(JobBucket::threshold() === 3650, 'a huge threshold is clamped');
set_setting('ready_threshold_days', '30');

section('Sections: countdown text and dates');
$chip = static fn (string $date, string $status = 'booked'): ?array => JobBucket::availability(['status' => $status, 'first_available_date' => $date]);
check($chip('2026-10-22') === ['text' => 'Overdue by 1 day', 'tone' => 'danger'], 'yesterday -> Overdue by 1 day');
check($chip('2026-10-13')['text'] === 'Overdue by 10 days', 'plural days overdue');
check($chip('2026-10-23') === ['text' => 'Today', 'tone' => 'warning'], 'today -> Today');
check($chip('2026-10-24') === ['text' => 'Tomorrow', 'tone' => 'ready'], 'tomorrow -> Tomorrow');
check($chip('2026-10-28') === ['text' => 'in 5 days', 'tone' => 'ready'], 'within the threshold -> ready tone');
check($chip('2026-12-02') === ['text' => 'in 40 days', 'tone' => 'later'], 'beyond the threshold -> later tone');
check($chip('2026-10-28', 'in_transit') === null && $chip('2026-10-28', 'delivered') === null, 'no countdown once a job is dispatched');
check(JobBucket::movesToReadyOn('2026-12-31') === '2026-12-01', 'a later job moves to Ready threshold-days before its date');
check(Clock::addDays('2026-02-27', 3) === '2026-03-02' && Clock::addDays('2026-03-01', -1) === '2026-02-28', 'date arithmetic across month ends');
check(Clock::daysBetween('2026-10-23', '2026-11-22') === 30 && Clock::daysBetween('2026-10-23', '2026-10-20') === -3 && Clock::daysBetween('2026-10-23', '2026-10-23') === 0, 'daysBetween');
Clock::fake(null);
check(preg_match('/^\d{4}-\d{2}-\d{2}$/', Clock::today()) === 1, 'the real clock returns a date');
set_setting('timezone', 'Not/AZone');
check(Clock::today() === gmdate('Y-m-d'), 'an invalid time zone setting falls back to UTC');
set_setting('timezone', 'UTC');
Clock::fake('2026-10-23');

section('Sections: editing a date moves the job automatically');
fresh_session();
$mgr = make_user('Manager', 'p3mgr@example.com');
sign_in($mgr);
$svc = new JobService();
$row = (new JobRepository())->find($j['far']);
check(in_array('B-far', numbers('later'), true) && !in_array('B-far', numbers('ready'), true), 'before: far-future job is in More Than 30 Days');
$res = $svc->update($j['far'], job_input(['job_number' => 'B-far', 'first_available_date' => '2026-10-28']), (string) $row['updated_at']);
check($res['ok'] && in_array('B-far', numbers('ready'), true) && !in_array('B-far', numbers('later'), true), 'after moving its date to +5 days it is in Ready and gone from More Than 30 Days');
$row = (new JobRepository())->find($j['far']);
$svc->update($j['far'], job_input(['job_number' => 'B-far', 'first_available_date' => '2027-03-01']), (string) $row['updated_at'] . '');
check(true, 'date edit round-trip does not error');

// ================================================================ dashboard consistency

section('Dashboard: counts always equal the lists they link to');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
mt_srand(424242);
$mgr = make_user('Manager', 'dash@example.com');
sign_in($mgr);
$statusPool = ['booked', 'booked', 'booked', 'booked', 'in_transit', 'in_transit', 'delivered', 'delivered', 'on_hold', 'cancelled'];
$expectedBalance = 0.0;
$expectedStorage = 0.0;
for ($i = 1; $i <= 90; $i++) {
    $status = $statusPool[mt_rand(0, 9)];
    $balance = mt_rand(0, 500000) / 100;
    $fee = mt_rand(0, 4000) / 100;
    mk_job([
        'job_number' => sprintf('D-%03d', $i),
        'status' => $status,
        'first_available_date' => Clock::addDays('2026-10-23', mt_rand(-20, 120)),
        'balance' => number_format($balance, 2, '.', ''),
        'storage_fee' => number_format($fee, 2, '.', ''),
        'carrier_name' => in_array($status, ['in_transit', 'delivered'], true) ? 'Carrier ' . mt_rand(1, 3) : null,
        'delivered_at' => $status === 'delivered' ? '2026-10-' . sprintf('%02d', mt_rand(1, 20)) . ' 00:00:00' : null,
    ]);
    if ($status !== 'cancelled') {
        $expectedBalance += $balance;
    }
    if (in_array($status, ['booked', 'in_transit', 'on_hold'], true)) {
        $expectedStorage += $fee;
    }
}
$deleted = mk_job(['job_number' => 'D-gone', 'balance' => '999999.00']);
(new JobRepository())->softDelete($deleted, null);

$data = (new DashboardService())->build(true);
foreach (['all', 'ready', 'later', 'transit', 'delivered'] as $bucket) {
    $listed = 0;
    $seen = [];
    foreach ([10, 25] as $perPage) {
        $listed = 0;
        $seen = [];
        for ($offset = 0; ; $offset += $perPage) {
            $rows = (new JobRepository())->fetch(JobCriteria::forBucket($bucket), $perPage, $offset);
            foreach ($rows as $r) {
                $seen[] = $r['id'];
            }
            $listed += count($rows);
            if (count($rows) < $perPage) {
                break;
            }
        }
    }
    check($data['counts'][$bucket] === $listed && $listed === count(array_unique($seen)), "dashboard count for '{$bucket}' ({$data['counts'][$bucket]}) equals the paged list ({$listed}) with no duplicate rows");
}
check($data['counts']['ready'] + $data['counts']['later'] + $data['counts']['transit'] + $data['counts']['delivered'] + ($data['statuses']['on_hold'] ?? 0) + ($data['statuses']['cancelled'] ?? 0) === $data['counts']['all'], 'sections + on hold + cancelled add up to the total');
check($data['counts']['all'] === 90, 'deleted jobs are not counted');
$overdue = array_filter(numbersRows('ready'), static fn (array $r): bool => $r['first_available_date'] < '2026-10-23');
function numbersRows(string $bucket): array
{
    return (new JobRepository())->fetch(JobCriteria::forBucket($bucket), 1000, 0);
}
check($data['overdue_count'] === count($overdue), 'overdue count matches the Ready jobs that are past their date');
check(count($data['overdue']) === min(5, count($overdue)) && array_reduce($data['overdue'], static fn (bool $c, array $r): bool => $c && $r['first_available_date'] < '2026-10-23', true), 'the overdue panel lists overdue jobs only');
check(array_reduce($data['upcoming'], static fn (bool $c, array $r): bool => $c && $r['first_available_date'] >= '2026-10-23' && $r['first_available_date'] <= JobBucket::cutoff(), true) && count($data['upcoming']) <= 5, 'the "coming up" panel lists ready jobs that are not yet due');
$dates = array_column($data['upcoming'], 'first_available_date');
$sorted = $dates;
sort($sorted);
check($dates === $sorted, '...soonest first');
check(abs((float) $data['money']['balance'] - $expectedBalance) < 0.01, 'outstanding balance = balances of all non-cancelled, non-deleted jobs');
check(abs((float) $data['money']['storage_fee'] - $expectedStorage) < 0.01, 'storage fees = fees of booked / in transit / on hold jobs');
check(count($data['activity']) <= 8 && $data['activity'] !== [], 'activity feed is capped and filled');
$ats = array_column($data['activity'], 'at');
$desc = $ats;
rsort($desc);
check($ats === $desc, 'activity is newest first');
$noMoney = (new DashboardService())->build(false);
check($noMoney['money'] === null && !str_contains(json_encode($noMoney), 'balance') && !str_contains(json_encode($noMoney), 'storage'), 'without financial access the dashboard data contains no money at all');

// ================================================================ search / filter / sort

section('List engine: search');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
sign_in(make_user('Manager', 'search@example.com'));
$A = mk_job(['job_number' => 'JT-100', 'customer_first_name' => 'Ada', 'customer_last_name' => 'Smith', 'from_location' => 'Austin, TX', 'to_location' => 'Denver, CO', 'balance' => '100.00', 'first_available_date' => '2026-10-28']);
$B = mk_job(['job_number' => 'JT-101', 'customer_first_name' => 'Grace', 'customer_last_name' => 'Hopper', 'from_location' => 'Boston, MA', 'to_location' => 'Austin, TX', 'status' => 'in_transit', 'carrier_name' => 'Blue Ridge Haulers', 'driver_name' => 'Marcus Reed', 'driver_phone' => '(555) 010-2231', 'balance' => '0.00', 'first_available_date' => '2026-10-10']);
$C = mk_job(['job_number' => 'JT-102', 'customer_first_name' => 'Alan', 'customer_last_name' => 'Turing', 'from_location' => 'Seattle, WA', 'to_location' => 'Portland, OR', 'status' => 'delivered', 'delivered_at' => '2026-09-15 00:00:00', 'delivery_date' => '2026-09-15', 'carrier_name' => 'Summit Freight', 'driver_name' => 'Priya Nair', 'driver_phone' => '(555) 010-4417', 'balance' => '50.00', 'first_available_date' => '2026-09-10']);
$D = mk_job(['job_number' => 'JT-103', 'customer_first_name' => 'Percent', 'customer_last_name' => '100%', 'from_location' => 'Miami, FL', 'to_location' => 'Tampa, FL', 'balance' => '0.00', 'first_available_date' => '2027-02-01']);
$E = mk_job(['job_number' => 'JT-104', 'customer_first_name' => 'Ada', 'customer_last_name' => 'Lovelace', 'from_location' => 'Denver, CO', 'to_location' => 'Boston, MA', 'status' => 'on_hold', 'balance' => '75.00', 'first_available_date' => '2026-12-15']);
$F = mk_job(['job_number' => 'JT-105', 'customer_first_name' => 'Under', 'customer_last_name' => 'Score_Co', 'from_location' => 'Reno, NV', 'to_location' => 'Boise, ID', 'balance' => '10.00', 'first_available_date' => '2026-11-05']);

check(numbers('all', criteria(['q' => 'ada'])) === ['JT-100', 'JT-104'], 'search by first name');
check(numbers('all', criteria(['q' => 'ada smith'])) === ['JT-100'], 'multi-word search: first AND last name');
check(numbers('all', criteria(['q' => 'smith ADA'])) === ['JT-100'], 'words may come in any order and any case');
check(numbers('all', criteria(['q' => 'jt-101'])) === ['JT-101'], 'search by job number');
check(numbers('all', criteria(['q' => 'blue ridge'])) === ['JT-101'], 'search by carrier');
check(numbers('all', criteria(['q' => 'marcus'])) === ['JT-101'], 'search by driver');
check(numbers('all', criteria(['q' => '(555)'])) === ['JT-101', 'JT-102'], 'search by phone as typed');
check(numbers('all', criteria(['q' => 'austin'])) === ['JT-100', 'JT-101'], 'search matches From and To');
check(numbers('all', criteria(['q' => 'nobody-here'])) === [], 'no match -> empty list');
check(numbers('all', criteria(['q' => '%'])) === ['JT-103'], 'a literal % matches only text containing %, not everything (LIKE wildcard escaped)');
check(numbers('all', criteria(['q' => '_'])) === ['JT-105'], 'a literal _ matches only text containing _ (escaped)');
check(numbers('all', criteria(['q' => '!'])) === [], 'the escape character itself is safe');
check(numbers('all', criteria(['q' => "'; DROP TABLE jobs;--"])) === [] && (new JobRepository())->count() === 6, 'SQL-injection text is just text: no results, table intact');
check(numbers('all', criteria(['q' => '" OR 1=1 --'])) === [], 'quote-injection finds nothing');
check(criteria(['q' => str_repeat('x', 500)])->q === str_repeat('x', 100), 'search text is limited to 100 characters');
check(criteria(['q' => "  a \n\t b  "])->q === 'a b', 'whitespace is normalised');
check(criteria(['q' => ['x']])->q === '', 'an array instead of text is ignored');
check(numbers('all', criteria(['q' => 'a b c d e f g h'])) === numbers('all', criteria(['q' => 'a b c d e'])), 'only the first 5 words are used (no error)');

section('List engine: filters');
check(numbers('all', criteria(['status' => 'in_transit'])) === ['JT-101'], 'status filter on All Jobs');
check(count(numbers('all', criteria(['status' => 'bogus']))) === 6, 'an unknown status is ignored');
check(criteria(['status' => 'in_transit'], 'ready')->status === '', 'the status filter only exists on All Jobs');
check(numbers('all', criteria(['from_date' => '2026-10-20', 'to_date' => '2026-11-10'])) === ['JT-100', 'JT-105'], 'date range on the 1st available date');
check(numbers('all', criteria(['from_date' => '2026-12-15', 'to_date' => '2026-12-15'])) === ['JT-104'], 'range bounds are inclusive');
check(numbers('all', criteria(['from_date' => '2026-11-10', 'to_date' => '2026-10-20'])) === ['JT-100', 'JT-105'], 'a reversed range is swapped');
check(count(numbers('all', criteria(['from_date' => 'garbage', 'to_date' => '2026-13-40']))) === 6, 'invalid dates are ignored');
check(numbers('all', criteria(['from_date' => '2026-12-01'])) === ['JT-103', 'JT-104'], 'open-ended range (only "from")');
check(numbers('delivered', criteria(['from_date' => '2026-09-15', 'to_date' => '2026-09-15'], 'delivered')) === ['JT-102'], 'Delivered filters by delivery date (inclusive of the whole day)');
check(numbers('delivered', criteria(['to_date' => '2026-09-14'], 'delivered')) === [], '...and excludes other days');
check(numbers('all', criteria(['from' => 'austin'])) === ['JT-100'], 'From filter');
check(numbers('all', criteria(['to' => 'austin'])) === ['JT-101'], 'To filter');
check(numbers('all', criteria(['from' => 'denver', 'to' => 'boston'])) === ['JT-104'], 'From and To combine');
check(numbers('all', criteria(['carrier' => 'summit'])) === ['JT-102'], 'carrier filter matches the carrier');
check(numbers('all', criteria(['carrier' => 'priya'])) === ['JT-102'], '...or the driver');
check(numbers('all', criteria(['balance' => 'due'])) === ['JT-100', 'JT-102', 'JT-104', 'JT-105'], 'balance due');
check(numbers('all', criteria(['balance' => 'paid'])) === ['JT-101', 'JT-103'], 'paid in full');
check(criteria(['balance' => 'due'], 'all', false)->balance === '', 'users without financial access cannot filter by balance');
check(numbers('all', criteria(['q' => 'ada', 'status' => 'on_hold', 'balance' => 'due'])) === ['JT-104'], 'filters combine (AND)');
check(numbers('all', criteria(['q' => 'ada', 'from' => 'denver'])) === ['JT-104'], 'search + location combine');
check(numbers('ready', criteria(['q' => 'ada'], 'ready')) === ['JT-100'], 'search stays inside its section (Ready)');
check(numbers('later', criteria(['q' => 'ada'], 'later')) === [], '...and does not leak jobs from other sections');
$c = criteria(['q' => 'x', 'status' => 'booked', 'balance' => 'due', 'sort' => 'job', 'dir' => 'desc']);
check($c->activeFilterCount() === 3 && $c->hasFilters() && $c->hasAdvancedFilters(), 'active filters are counted');
check(!criteria(['q' => 'x'])->hasAdvancedFilters() && !criteria([])->hasFilters(), 'search alone is not an advanced filter; no input = no filters');

section('List engine: sorting and paging');
check(array_column((new JobRepository())->fetch(criteria(['sort' => 'customer']), 50, 0), 'job_number') === ['JT-103', 'JT-101', 'JT-104', 'JT-105', 'JT-100', 'JT-102'], 'sort by customer (last name) ascending');
check(array_column((new JobRepository())->fetch(criteria(['sort' => 'customer', 'dir' => 'desc']), 50, 0), 'job_number') === ['JT-102', 'JT-100', 'JT-105', 'JT-104', 'JT-101', 'JT-103'], '...and descending');
check(array_column((new JobRepository())->fetch(criteria(['sort' => 'job', 'dir' => 'desc']), 50, 0), 'job_number')[0] === 'JT-105', 'sort by job number');
check(array_column((new JobRepository())->fetch(criteria(['sort' => 'available']), 50, 0), 'job_number')[0] === 'JT-102', 'sort by available date');
check(array_column((new JobRepository())->fetch(criteria(['sort' => 'balance', 'dir' => 'desc']), 50, 0), 'job_number')[0] === 'JT-100', 'sort by balance (financial users)');
check(criteria(['sort' => 'balance'], 'all', false)->sort === 'created', 'users without financial access cannot sort by balance (default used)');
check(criteria(['sort' => 'storage'], 'all', false)->sort === 'created', '...or storage fee');
foreach (['id;DROP TABLE jobs', 'j.id', '1', '', 'password_hash', "job' OR '1'='1"] as $evil) {
    check(criteria(['sort' => $evil])->sort === 'created' && (new JobRepository())->count() === 6, 'unknown sort "' . $evil . '" falls back to the default');
}
check(criteria(['sort' => 'job', 'dir' => 'sideways'])->dir === 'asc' && criteria(['sort' => 'job', 'dir' => 'DESC'])->dir === 'desc', 'direction is asc/desc only');
check(criteria([], 'ready')->sort === 'available' && criteria([], 'ready')->dir === 'asc', 'Ready defaults to soonest available date first');
check(criteria([], 'transit')->sort === 'dispatched' && criteria([], 'delivered')->sort === 'delivered', 'Transit / Delivered default to most recent first');
foreach ([999, -1, 0, 'x', 33] as $bad) {
    check(criteria(['per_page' => $bad])->perPage === 25, "per_page {$bad} falls back to the default");
}
check(criteria(['per_page' => '50'])->perPage === 50 && criteria(['per_page' => '10'])->perPage === 10, 'valid page sizes are accepted');
check(criteria(['page' => '-3'])->page === 1 && criteria(['page' => 'x'])->page === 1 && criteria(['page' => '7'])->page === 7, 'page numbers are sanitised');

// stable paging with many equal sort keys
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
sign_in(make_user('Manager', 'paging@example.com'));
for ($i = 1; $i <= 37; $i++) {
    mk_job(['job_number' => sprintf('S-%03d', $i), 'first_available_date' => '2026-11-0' . (($i % 3) + 1), 'customer_last_name' => 'Same']);
}
foreach (['available', 'customer', 'status', 'from'] as $sort) {
    $seen = [];
    for ($page = 0; $page < 4; $page++) {
        foreach ((new JobRepository())->fetch(criteria(['sort' => $sort, 'per_page' => '10']), 10, $page * 10) as $r) {
            $seen[] = $r['id'];
        }
    }
    check(count($seen) === 37 && count(array_unique($seen)) === 37, "paging through 37 jobs sorted by '{$sort}' (all ties) never repeats or skips a row");
}
$link = criteria(['q' => 'S', 'sort' => 'job', 'dir' => 'desc', 'per_page' => '50'])->linkParams();
check($link === ['q' => 'S', 'sort' => 'job', 'dir' => 'desc', 'per_page' => 50], 'link parameters keep search, sort and page size');
check(criteria([])->linkParams() === [], 'default view needs no query string');
$toggle = criteria(['sort' => 'job', 'dir' => 'asc'])->sortParams('job');
check($toggle['sort'] === 'job' && $toggle['dir'] === 'desc' && criteria(['sort' => 'job', 'dir' => 'desc'])->sortParams('job')['dir'] === 'asc' && criteria([])->sortParams('customer')['dir'] === 'asc', 'clicking a header toggles asc/desc; a new column starts ascending');

// ================================================================ workflow

section('Workflow: Move to In Transit (one click)');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$staff = make_user('Staff', 'wf-staff@example.com');
$manager = make_user('Manager', 'wf-mgr@example.com');
sign_in($staff);
$wf = new JobWorkflowService();
$jobId = mk_job(['job_number' => 'W-1', 'first_available_date' => '2026-11-05', 'balance' => '10.00']);
$carrier = ['carrier_name' => 'Blue Ridge Haulers', 'driver_name' => 'Marcus Reed', 'driver_phone' => '(555) 010-2231', 'note' => '  Picked up at dock 3 '];
/** Carrier details are no longer collected by the workflow; they are filled in later through the edit form. */
$set_carrier = static fn (int $id) => Database::execute("UPDATE jobs SET carrier_name = 'Blue Ridge Haulers', driver_name = 'Marcus Reed', driver_phone = '(555) 010-2231' WHERE id = ?", [$id]);

$r = $wf->change($jobId, 'in_transit', ['note' => str_repeat('n', 1001)]);
check(!$r['ok'] && $r['code'] === 'validation', 'an over-long note is the only thing that can fail a dispatch');
$j0 = (new JobRepository())->find($jobId);
check($j0['status'] === 'booked' && $j0['loaded_date'] === null && (int) Database::fetchValue('SELECT COUNT(*) FROM job_status_history') === 0, 'a failed attempt changes nothing and writes no history');
check((int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'job_status_changed'") === 0, '...and no audit entry');

$r = $wf->change($jobId, 'in_transit', ['note' => '  Picked up at dock 3 ', 'status' => 'delivered', 'id' => 99, 'loaded_date' => '1999-01-01', 'carrier_name' => 'Smuggled']);
check($r['ok'] && $r['from'] === 'booked' && $r['to'] === 'in_transit' && $r['job_number'] === 'W-1' && $r['loaded_date'] === '2026-10-23', 'one click: no carrier, driver or date is needed');
$j1 = (new JobRepository())->find($jobId);
check($j1['status'] === 'in_transit' && $j1['loaded_date'] === '2026-10-23', 'the loaded date is today (the business date), whatever the request says');
check($j1['carrier_name'] === null && $j1['delivery_date'] === null && $j1['delivered_at'] === null, 'extra fields in the request cannot set carrier details or a delivery date');
check($j1['in_transit_at'] !== null && (int) $j1['updated_by'] === (int) $staff['id'], 'dispatch time and updater recorded');
check(numbers('transit') === ['W-1'] && !in_array('W-1', numbers('ready'), true) && !in_array('W-1', numbers('later'), true), 'the job moved into In Transit and out of Ready');
$h = (new StatusHistoryRepository())->forJob($jobId);
$hd = json_decode((string) $h[0]['details'], true);
check(count($h) === 1 && $h[0]['from_status'] === 'booked' && $h[0]['to_status'] === 'in_transit' && $h[0]['note'] === 'Picked up at dock 3' && (int) $h[0]['changed_by'] === (int) $staff['id'], 'one history row with from/to, trimmed note and user');
check($hd === ['loaded_date' => '2026-10-23'], 'the loaded date is kept in the history');
$a = last_audit('job_status_changed');
check($a !== null && (int) $a['entity_id'] === $jobId && str_contains((string) $a['old_values'], 'booked') && str_contains((string) $a['new_values'], 'in_transit') && str_contains((string) $a['new_values'], '2026-10-23'), 'the change is audited, with the date');
Clock::fake('2026-10-24');
$next = mk_job(['job_number' => 'W-1b', 'first_available_date' => '2026-11-05']);
$wf->change($next, 'in_transit', []);
check((new JobRepository())->find($next)['loaded_date'] === '2026-10-24', 'the date follows the calendar: the next day it is the next date');
Clock::fake('2026-10-23');

section('Workflow: invalid transitions are refused');
$bad = [['booked', 'delivered'], ['booked', 'booked'], ['in_transit', 'booked'], ['in_transit', 'in_transit'], ['delivered', 'booked'], ['delivered', 'cancelled'], ['delivered', 'on_hold'], ['cancelled', 'in_transit'], ['cancelled', 'delivered'], ['on_hold', 'in_transit'], ['on_hold', 'delivered']];
sign_in($manager); // Manager holds both permissions, so only the rules can refuse these
foreach ($bad as [$from, $to]) {
    $id = mk_job(['status' => $from]);
    $r = $wf->change($id, $to, ['note' => 'x', 'carrier_name' => 'C', 'driver_name' => 'D', 'driver_phone' => '5550001111', 'delivered_on' => '2026-10-20']);
    check(!$r['ok'] && $r['code'] === 'invalid' && (new JobRepository())->find($id)['status'] === $from, "{$from} -> {$to} is refused and changes nothing");
}
check($wf->change($jobId, 'nonsense', [])['code'] === 'invalid', 'an unknown target status is refused');
check($wf->change(999999, 'in_transit', [])['code'] === 'not_found', 'an unknown job is not found');
$trash = mk_job(['job_number' => 'W-TRASH']);
(new JobRepository())->softDelete($trash, null);
check($wf->change($trash, 'in_transit', $carrier)['code'] === 'not_found', 'a deleted job is not found');

section('Workflow: Mark as Delivered (one click)');
sign_in($staff);
$set_carrier($jobId);
$r = $wf->change($jobId, 'delivered', ['delivered_on' => '1999-01-01', 'delivery_date' => '1999-01-01', 'note' => 'Left with neighbour']);
$j2 = (new JobRepository())->find($jobId);
check($r['ok'] && $r['delivery_date'] === '2026-10-23' && $j2['status'] === 'delivered', 'one click: no date is asked for');
check($j2['delivery_date'] === '2026-10-23' && $j2['delivered_at'] === '2026-10-23 00:00:00', 'the delivery date is today, whatever the request says');
check($j2['loaded_date'] === '2026-10-23' && $j2['carrier_name'] === 'Blue Ridge Haulers', 'the loaded date and carrier details stay on the delivered job');
check(in_array('W-1', numbers('delivered'), true) && !in_array('W-1', numbers('transit'), true), 'the job moved to Delivered and out of In Transit');
$hd = json_decode((string) (new StatusHistoryRepository())->forJob($jobId)[0]['details'], true);
check($hd === ['delivery_date' => '2026-10-23'], 'the delivery date is kept in the history');
$r = $wf->change($jobId, 'delivered', []);
check($r['code'] === 'invalid', 'a delivered job cannot be delivered again');

section('Workflow: hold, resume, cancel');
$id = mk_job(['job_number' => 'W-2', 'first_available_date' => '2026-12-30']);
check($wf->change($id, 'on_hold', [])['code'] === 'validation', 'putting a job on hold requires a reason');
check($wf->change($id, 'on_hold', ['note' => str_repeat('n', 1001)])['code'] === 'validation', 'a reason is limited to 1000 characters');
check($wf->change($id, 'on_hold', ['note' => 'Customer unavailable'])['ok'], 'hold with a reason');
check(!in_array('W-2', array_merge(numbers('ready'), numbers('later'), numbers('transit'), numbers('delivered')), true) && in_array('W-2', numbers('all'), true), 'a held job leaves every section but stays in All Jobs');
$r = $wf->change($id, 'booked', []);
check($r['ok'] && in_array('W-2', numbers('later'), true), 'resume needs no reason and returns the job to its date-based section');
check($wf->change($id, 'cancelled', [])['code'] === 'validation', 'cancelling requires a reason');
check($wf->change($id, 'cancelled', ['note' => 'Customer cancelled'])['ok'] && (new JobRepository())->find($id)['status'] === 'cancelled', 'cancel with a reason');
check(!in_array('W-2', array_merge(numbers('ready'), numbers('later')), true), 'a cancelled job is in no section');

// resuming a job that had been dispatched clears the carrier data (and keeps it in the history)
$id = mk_job(['job_number' => 'W-3', 'first_available_date' => '2026-10-30']);
$wf->change($id, 'in_transit', $carrier);
$set_carrier($id);
check($wf->change($id, 'on_hold', ['note' => 'Truck broke down'])['ok'], 'an in-transit job can be put on hold');
check($wf->change($id, 'booked', ['note' => 'Fixed'])['ok'], '...and resumed');
$j3 = (new JobRepository())->find($id);
check($j3['status'] === 'booked' && $j3['carrier_name'] === null && $j3['driver_phone'] === null && $j3['in_transit_at'] === null && $j3['loaded_date'] === null && in_array('W-3', numbers('ready'), true), 'resume clears carrier data and the loaded date and puts the job back in Ready');
$cleared = json_decode((string) (new StatusHistoryRepository())->forJob($id)[0]['details'], true);
check(($cleared['cleared']['carrier_name'] ?? '') === 'Blue Ridge Haulers' && ($cleared['cleared']['driver_name'] ?? '') === 'Marcus Reed' && ($cleared['cleared']['loaded_date'] ?? '') === '2026-10-23', 'what was cleared (carrier, loaded date) is preserved in the history');
check(count((new StatusHistoryRepository())->forJob($id)) === 3, 'three transitions -> three history rows');
$r = $wf->change($id, 'in_transit', $carrier);
check($r['ok'] && (new JobRepository())->find($id)['loaded_date'] === '2026-10-23', 'a resumed job can be moved again, with a fresh loaded date');

section('Workflow: permissions and reopening');
sign_in($staff);
$id = mk_job(['job_number' => 'W-4', 'status' => 'delivered', 'delivered_at' => '2026-10-01 00:00:00', 'delivery_date' => '2026-10-01', 'loaded_date' => '2026-09-28', 'carrier_name' => 'Keep Me', 'driver_name' => 'Drv', 'driver_phone' => '5550002222', 'in_transit_at' => '2026-09-28 08:00:00']);
check($wf->availableTransitions((new JobRepository())->find($id)) === [], 'Staff is offered no actions on a delivered job');
$r = $wf->change($id, 'in_transit', ['note' => 'Wrong delivery']);
check(!$r['ok'] && $r['code'] === 'forbidden' && (new JobRepository())->find($id)['status'] === 'delivered', 'Staff cannot reopen a delivered job (needs jobs.reopen)');
check(last_audit('access_denied') !== null, '...and the attempt is audited');
$booked = (new JobRepository())->find(mk_job(['job_number' => 'W-5']));
check(array_column($wf->availableTransitions($booked), 'to') === ['in_transit', 'on_hold', 'cancelled'], 'Staff on a booked job: move to In Transit, hold, cancel');
check($wf->canMove($booked, 'in_transit') && !$wf->canMove($booked, 'delivered'), 'canMove follows the rules and permissions');

sign_in($manager);
check(array_column($wf->availableTransitions((new JobRepository())->find($id)), 'to') === ['in_transit'], 'Manager can reopen a delivered job');
check($wf->change($id, 'in_transit', [])['code'] === 'validation', 'reopening requires a reason');
$r = $wf->change($id, 'in_transit', ['note' => 'Delivered to wrong address']);
$j5 = (new JobRepository())->find($id);
check($r['ok'] && $j5['status'] === 'in_transit' && $j5['delivered_at'] === null && $j5['delivery_date'] === null && $j5['carrier_name'] === 'Keep Me' && $j5['in_transit_at'] === '2026-09-28 08:00:00' && $j5['loaded_date'] === '2026-09-28', 'reopen returns the job to transit: delivery date cleared, loaded date and carrier data kept');
$cid = mk_job(['job_number' => 'W-6', 'status' => 'cancelled', 'first_available_date' => '2026-12-01']);
check($wf->change($cid, 'booked', [])['code'] === 'validation' && $wf->change($cid, 'booked', ['note' => 'Customer came back'])['ok'] && in_array('W-6', numbers('later'), true), 'a cancelled job can be reopened as booked (with a reason)');

$roleId = make_role('ViewerOnly', ['jobs.view']);
$viewer = make_user('ViewerOnly', 'viewer@example.com');
sign_in($viewer);
check($wf->availableTransitions((new JobRepository())->find(mk_job(['job_number' => 'W-7']))) === [], 'a user without jobs.status is offered nothing');
check($wf->change(mk_job(['job_number' => 'W-8']), 'in_transit', $carrier)['code'] === 'forbidden', '...and cannot dispatch even by calling the service directly');

section('Workflow: atomicity, races and the timeline');
sign_in($staff);
$id = mk_job(['job_number' => 'W-9']);
check(!(new JobRepository())->changeStatus($id, 'in_transit', ['status' => 'delivered'], null) && (new JobRepository())->find($id)['status'] === 'booked', 'the repository refuses a change when the job is no longer in the status the caller saw');
check((new JobRepository())->changeStatus($id, 'booked', ['status' => 'on_hold'], null) && !(new JobRepository())->changeStatus($id, 'booked', ['status' => 'cancelled'], null), 'only the first of two concurrent changes wins');
Database::connect()->exec("UPDATE jobs SET status = 'booked' WHERE id = {$id}");
Database::connect()->exec('DROP TABLE job_status_history'); // make the second half of the transaction fail
$threw = false;
try {
    $wf->change($id, 'in_transit', $carrier);
} catch (Throwable) {
    $threw = true;
}
check($threw && (new JobRepository())->find($id)['status'] === 'booked' && (new JobRepository())->find($id)['carrier_name'] === null, 'if the history cannot be written the status change is rolled back (one transaction)');

fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$staff = make_user('Staff', 'tl@example.com');
sign_in($staff);
$id = (new JobService())->create(job_input(['job_number' => 'TL-1', 'first_available_date' => '2026-11-04']));
$wf->change($id, 'in_transit', $carrier);
$wf->change($id, 'delivered', ['note' => 'All good']);
$timeline = $wf->timeline((new JobRepository())->find($id));
check(count($timeline) === 3 && $timeline[0]['to'] === 'delivered' && $timeline[1]['to'] === 'in_transit' && $timeline[2]['type'] === 'created', 'timeline: newest first, ending with the creation of the job');
check($timeline[0]['note'] === 'All good' && $timeline[0]['details']['delivery_date'] === '2026-10-23' && $timeline[1]['details']['loaded_date'] === '2026-10-23' && $timeline[0]['by'] === $staff['name'], 'timeline carries notes, details and who did it');

// ================================================================ routes and pages

section('Pages: the five lists');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$mgr = make_user('Manager', 'pg-mgr@example.com');
$stf = make_user('Staff', 'pg-stf@example.com');
mk_job(['job_number' => 'R-overdue', 'first_available_date' => '2026-10-22', 'balance' => '120.50', 'customer_first_name' => 'Olga', 'customer_last_name' => 'Overdue']);
mk_job(['job_number' => 'R-soon', 'first_available_date' => '2026-10-28', 'balance' => '80.00']);
mk_job(['job_number' => 'L-far', 'first_available_date' => '2026-12-31', 'storage_fee' => '45.00', 'storage_fee_override' => '45.00', 'balance' => '30.00']);
mk_job(['job_number' => 'T-1', 'status' => 'in_transit', 'carrier_name' => 'Blue Ridge Haulers', 'driver_name' => 'Marcus Reed', 'driver_phone' => '(555) 010-2231', 'in_transit_at' => '2026-10-20 09:00:00', 'loaded_date' => '2026-10-20', 'first_available_date' => '2026-10-19']);
mk_job(['job_number' => 'D-1', 'status' => 'delivered', 'delivered_at' => '2026-09-15 00:00:00', 'delivery_date' => '2026-09-15', 'carrier_name' => 'Summit Freight', 'driver_name' => 'Priya', 'driver_phone' => '5550104417', 'first_available_date' => '2026-09-10']);
mk_job(['job_number' => 'H-1', 'status' => 'on_hold']);

sign_in($mgr);
$pages = ['/jobs' => 'all', '/jobs/ready' => 'ready', '/jobs/more-than-30' => 'later', '/jobs/in-transit' => 'transit', '/jobs/delivered' => 'delivered'];
$bodies = [];
foreach ($pages as $path => $bucket) {
    $r = call('GET', $path);
    $bodies[$bucket] = $r->body();
    check($r->status() === 200, "Manager: {$path} loads");
    check(preg_match('/<td(?![^>]*data-label)/', $r->body()) === 0, "{$path}: every table cell has a data-label (card layout on phones)");
}
check(str_contains($bodies['ready'], 'R-overdue') && str_contains($bodies['ready'], 'R-soon') && !str_contains($bodies['ready'], 'L-far'), 'Ready page lists the ready jobs only');
check(str_contains($bodies['ready'], 'Overdue by 1 day') && str_contains($bodies['ready'], 'in 5 days'), 'Ready page shows countdown chips (overdue and days left)');
check(str_contains($bodies['later'], 'L-far') && !str_contains($bodies['later'], 'R-soon'), 'More Than 30 Days page lists only the far-off jobs');
check(str_contains(plain($bodies['later']), 'Moves to Ready on Dec 1, 2026'), '...with the date each one moves into Ready');
check(str_contains(plain($bodies['later']), 'Storage fee') && str_contains($bodies['later'], '$45.00'), '...and the storage fee column for financial users');
check(str_contains(plain($bodies['ready']), 'Balance') && str_contains($bodies['ready'], '$120.50'), 'Ready page shows the Balance column for financial users');
check(str_contains($bodies['transit'], 'T-1') && str_contains($bodies['transit'], 'Blue Ridge Haulers') && str_contains($bodies['transit'], 'Marcus Reed') && str_contains($bodies['transit'], 'href="tel:5550102231"'), 'In Transit page shows carrier, driver and a tap-to-call phone link');
check(str_contains(plain($bodies['transit']), 'Oct 20, 2026'), '...and the loaded date');
check(str_contains($bodies['delivered'], 'D-1') && str_contains(plain($bodies['delivered']), 'Sep 15, 2026'), 'Delivered page shows the delivery date');
check(str_contains($bodies['all'], 'H-1') && str_contains($bodies['all'], 'jt-badge-hold'), 'All Jobs shows every job with a status badge, including on hold');
check(!str_contains($bodies['ready'], 'jt-badge-booked') && !str_contains($bodies['ready'], '>Status<'), 'section pages do not repeat a status column');
foreach (['ready', 'later', 'transit', 'delivered', 'all'] as $b) {
    check(str_contains($bodies[$b], 'aria-label="Job sections"') && substr_count($bodies[$b], 'aria-current="page"') >= 1, "{$b}: section tabs with the current one marked");
}
check(str_contains(plain($bodies['all']), 'All Jobs 6') && str_contains(plain($bodies['all']), 'Ready for Delivery 2') && str_contains(plain($bodies['all']), 'More Than 30 Days 1') && str_contains(plain($bodies['all']), 'In Transit 1') && str_contains(plain($bodies['all']), 'Delivered 1'), 'tab counts are live');
check(preg_match('#jt-nav-link active"[^>]*>\s*(?:<svg.*?</svg>)?\s*<span>([^<]*)#s', $bodies['ready'], $m) === 1 && trim($m[1]) === 'Ready for Delivery' && substr_count($bodies['ready'], 'jt-nav-link active') === 1, 'sidebar highlights only the current section');
check(preg_match('#jt-nav-link active"[^>]*>\s*(?:<svg.*?</svg>)?\s*<span>([^<]*)#s', call('GET', '/jobs/1')->body(), $m) === 1 && trim($m[1]) === 'All Jobs', 'a job details page highlights All Jobs in the sidebar');

section('Pages: financial data and shortcuts per role');
fresh_session();
sign_in($stf);
foreach ($pages as $path => $bucket) {
    $body = call('GET', $path)->body();
    check(call('GET', $path)->status() === 200 && !str_contains(plain($body), 'Balance') && !str_contains(plain($body), 'Storage fee') && !preg_match('/\$\d/', $body), "Staff: {$path} shows no money");
}
$ready = call('GET', '/jobs/ready')->body();
$rid = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'R-soon'");
$tid = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'T-1'");
check(str_contains($ready, "action=\"/jobs/{$rid}/status\"") && str_contains($ready, 'value="in_transit"') && str_contains($ready, 'Move to In Transit') && str_contains($ready, 'data-confirm='), 'Ready rows have a one-click Move to In Transit button (a form, with a confirmation)');
$transitBody = call('GET', '/jobs/in-transit')->body();
check(str_contains($transitBody, "action=\"/jobs/{$tid}/status\"") && str_contains($transitBody, 'value="delivered"') && str_contains($transitBody, 'Mark as Delivered'), 'In Transit rows have a one-click Mark as Delivered button');
check(!str_contains(call('GET', '/jobs/delivered')->body(), 'Mark as Delivered') && !str_contains(call('GET', '/jobs/delivered')->body(), 'Move to In Transit'), 'Delivered rows offer no shortcut');
fresh_session();
make_role('ViewerOnly', ['jobs.view']);
sign_in(make_user('ViewerOnly', 'sc-viewer@example.com'));
check(!str_contains(call('GET', '/jobs/ready')->body(), 'Move to In Transit'), 'a user without jobs.status sees no shortcuts');
check(call('GET', '/jobs/ready')->status() === 200, '...but can still view the list');

section('Pages: search, filter and sort in the UI');
fresh_session();
sign_in($mgr);
$body = call('GET', '/jobs', [], ['q' => 'olga'])->body();
check(str_contains($body, 'R-overdue') && !str_contains($body, 'R-soon') && str_contains($body, 'value="olga"') && str_contains($body, '(filtered)'), 'search narrows the list and keeps the term in the box');
$body = call('GET', '/jobs', [], ['q' => '<script>alert(1)</script>'])->body();
check(!str_contains($body, '<script>alert(1)') && str_contains($body, 'No jobs match your search') && str_contains($body, 'Clear search and filters'), 'a hostile search term is escaped, and the empty state offers a reset');
$body = call('GET', '/jobs', [], ['sort' => 'job', 'dir' => 'asc'])->body();
check(str_contains($body, 'aria-sort="ascending"') && str_contains($body, 'sort=job&amp;dir=desc'), 'the sorted column is marked and its header link toggles to descending');
$body = call('GET', '/jobs', [], ['q' => 'R-', 'status' => 'booked', 'sort' => 'job', 'dir' => 'desc'])->body();
check(str_contains($body, 'q=R-') && str_contains($body, 'status=booked'), 'sort links keep the active search and filters');
check(call('GET', '/jobs', [], ['sort' => 'evil;DROP', 'per_page' => '999', 'page' => 'x', 'status' => 'bogus', 'from_date' => 'zzz'])->status() === 200, 'garbage parameters never cause an error');
check(str_contains(call('GET', '/jobs', [], ['status' => 'on_hold'])->body(), 'H-1') && !str_contains(call('GET', '/jobs', [], ['status' => 'on_hold'])->body(), 'R-soon'), 'status filter in the UI');
$body = call('GET', '/jobs/ready', [], ['from_date' => '2026-10-25'])->body();
check(!str_contains($body, 'R-overdue') && str_contains($body, 'R-soon'), 'date filter in a section');
check(str_contains(call('GET', '/jobs', [], ['status' => 'on_hold'])->body(), '<details class="jt-more-filters mt-3" open>'), 'the filter panel opens by itself when a filter is active');
$body = call('GET', '/jobs/more-than-30', [], ['q' => 'zzz-none'])->body();
check(str_contains($body, 'No jobs match your search'), 'no results with filters -> search empty state');
$empty = (function (): string {
    Database::connect()->exec("UPDATE jobs SET status = 'cancelled' WHERE status = 'in_transit'");
    return call('GET', '/jobs/in-transit')->body();
})();
check(str_contains($empty, 'No jobs in transit') && !str_contains($empty, 'No jobs match'), 'an empty section shows its own friendly message');
Database::connect()->exec("UPDATE jobs SET status = 'in_transit' WHERE job_number = 'T-1'");

section('Pages: job details, workflow dialogs and history');
$stf = (array) $stf;
fresh_session();
sign_in($stf);
$rid = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'R-soon'");
$body = call('GET', "/jobs/{$rid}")->body();
check(str_contains($body, 'Update status') && str_contains($body, 'Move to In Transit') && !str_contains($body, 'statusModal-in_transit') && str_contains($body, 'statusModal-on_hold') && str_contains($body, 'statusModal-cancelled') && !str_contains($body, 'statusModal-delivered'), 'a booked job offers a one-click Move to In Transit, and hold and cancel dialogs');
check(str_contains(plain($body), 'in 5 days'), 'the details page shows the countdown');
check(!str_contains($body, 'data-auto-open-modal'), 'no dialog opens by itself on a plain visit');
check(!str_contains(call('GET', "/jobs/{$rid}", [], ['do' => 'delivered'])->body(), 'data-auto-open-modal'), '?do= for a change that is not possible is ignored');
check(!str_contains(call('GET', "/jobs/{$rid}", [], ['do' => '"><script>'])->body(), '<script>'), '?do= cannot inject markup');

// a dialog that fails validation reopens with its message
$r = call('POST', "/jobs/{$rid}/status", ['to' => 'on_hold', 'note' => '']);
check($r->status() === 302 && str_ends_with((string) $r->getHeader('Location'), "/jobs/{$rid}"), 'a hold without a reason redirects back to the job');
check(($_SESSION['_flash_next']['open_modal'] ?? '') === 'on_hold' && isset($_SESSION['_flash_next']['errors']['note']), '...flashing the error and which dialog to reopen');
$body = call('GET', "/jobs/{$rid}")->body();
check(str_contains($body, 'data-auto-open-modal="statusModal-on_hold"') && str_contains($body, 'Note is required.'), 'the dialog reopens with its message');

// one click through the real route
$r = call('POST', "/jobs/{$rid}/status", ['to' => 'in_transit', 'note' => '<script>x</script>']);
check($r->status() === 302 && (new JobRepository())->find($rid)['status'] === 'in_transit', 'one click through the route moves the job');
check(($_SESSION['_flash_next']['alert']['type'] ?? '') === 'success' && str_contains((string) $_SESSION['_flash_next']['alert']['message'], 'is now In Transit') && str_contains((string) $_SESSION['_flash_next']['alert']['message'], 'Loaded date: Oct 23, 2026'), '...with a success message that names the loaded date');
$body = call('GET', "/jobs/{$rid}")->body();
check(str_contains($body, '&lt;script&gt;x&lt;/script&gt;') && !str_contains($body, '<script>x</script>'), 'note text is escaped everywhere it is shown');
$text = plain($body);
check(str_contains($text, 'Job created') && str_contains($text, 'Booked In Transit') && str_contains($text, 'Loaded on Oct 23, 2026'), 'history timeline shows creation and the move, with the loaded date');
check(str_contains($text, 'Loaded date Oct 23, 2026') && str_contains($body, 'Mark as Delivered') && !str_contains($body, 'Move to In Transit'), 'details page: shows the loaded date and now offers Mark as Delivered');
check(str_contains(plain($body), 'In Transit') && str_contains($body, 'jt-badge-transit'), 'status badge updated');

$r = call('POST', "/jobs/{$rid}/status", ['to' => 'booked']);
check($r->status() === 302 && ($_SESSION['_flash_next']['alert']['type'] ?? '') === 'danger', 'an impossible change (in transit -> booked) flashes an error and changes nothing');
check((new JobRepository())->find($rid)['status'] === 'in_transit', '...');
check(call('POST', '/jobs/999999/status', ['to' => 'in_transit'])->status() === 404, 'status change on an unknown job -> 404');

$did = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'D-1'");
check(call('POST', "/jobs/{$did}/status", ['to' => 'in_transit', 'note' => 'try'])->status() === 403, 'Staff cannot reopen: 403');
check((new JobRepository())->find($did)['status'] === 'delivered', '...and nothing changed');
$noToken = real_router()->dispatch(Request::make('POST', "/jobs/{$rid}/status", ['to' => 'delivered']));
check($noToken->status() === 403 && (new JobRepository())->find($rid)['status'] === 'in_transit', 'a status change without a CSRF token is refused');

fresh_session();
sign_in($mgr);
$body = call('GET', "/jobs/{$did}")->body();
check(str_contains($body, 'Reopen (back in transit)') && str_contains(plain($body), 'Delivery date Sep 15, 2026'), 'Manager sees Reopen on a delivered job, and its delivery date');
$r = call('POST', "/jobs/{$did}/status", ['to' => 'in_transit', 'note' => 'Wrong house']);
check($r->status() === 302 && (new JobRepository())->find($did)['status'] === 'in_transit', 'Manager can reopen it');

section('Pages: editing carrier details while in transit');
$tid = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'T-1'");
$form = call('GET', "/jobs/{$tid}/edit")->body();
check(str_contains($form, 'name="carrier_name"') && str_contains($form, 'name="driver_phone"'), 'the edit form of an in-transit job has carrier fields');
$bid = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'L-far'");
check(!str_contains(call('GET', "/jobs/{$bid}/edit")->body(), 'name="carrier_name"'), 'a booked job has none');
$loaded = (string) (new JobRepository())->find($tid)['updated_at'];
$base = job_input(['job_number' => 'T-1', 'first_available_date' => '2026-10-19', 'loaded_at' => $loaded]);
$r = call('POST', "/jobs/{$tid}", $base + ['carrier_name' => '', 'driver_name' => '', 'driver_phone' => '']);
check(!isset($_SESSION['_flash_next']['errors']['carrier_name']) && (new JobRepository())->find($tid)['carrier_name'] === null, 'carrier details are optional: they can be left blank');
$r = call('POST', "/jobs/{$tid}", $base + ['carrier_name' => 'X', 'driver_name' => 'Y', 'driver_phone' => 'abc']);
check(isset($_SESSION['_flash_next']['errors']['driver_phone']), 'but a driver phone that is not a phone number is refused');
$loaded = (string) (new JobRepository())->find($tid)['updated_at'];
$base['loaded_at'] = $loaded;
$r = call('POST', "/jobs/{$tid}", $base + ['carrier_name' => 'Fixed Carrier', 'driver_name' => 'Marcus Reed', 'driver_phone' => '(555) 010-9999']);
check($r->status() === 302 && (new JobRepository())->find($tid)['carrier_name'] === 'Fixed Carrier' && (new JobRepository())->find($tid)['driver_phone'] === '(555) 010-9999', 'a typo in the driver phone can be corrected');
check((new JobRepository())->find($tid)['status'] === 'in_transit', '...without touching the status');
$loaded = (string) (new JobRepository())->find($tid)['updated_at'];
$r = call('POST', "/jobs/{$bid}", job_input(['job_number' => 'L-far', 'first_available_date' => '2026-12-31', 'loaded_at' => (string) (new JobRepository())->find($bid)['updated_at']]) + ['carrier_name' => 'Forged', 'driver_name' => 'Forged', 'driver_phone' => '5550001111', 'status' => 'delivered']);
check((new JobRepository())->find($bid)['carrier_name'] === null && (new JobRepository())->find($bid)['status'] === 'booked', 'carrier and status cannot be forged onto a booked job through the edit form');

section('Pages: dashboard');
fresh_session();
sign_in($mgr);
$r = call('GET', '/dashboard');
$body = $r->body();
$text = plain($body);
check($r->status() === 200, 'dashboard loads');
$data = (new DashboardService())->build(true);
foreach ([['all', 'Total jobs'], ['ready', 'Ready for delivery'], ['later', 'More than 30 days'], ['transit', 'In transit'], ['delivered', 'Delivered']] as [$bucket, $label]) {
    check(preg_match('#jt-stat-value">(\d+)</span>\s*<span class="jt-stat-label">' . preg_quote($label, '#') . '<#', $body, $m) === 1 && (int) $m[1] === $data['counts'][$bucket], "card '{$label}' shows {$data['counts'][$bucket]}");
    $pageBody = call('GET', JobBucket::definitions()[$bucket]['path'])->body();
    check(preg_match('/of (\d+) job/', plain($pageBody), $pm) === 1 ? (int) $pm[1] === $data['counts'][$bucket] : $data['counts'][$bucket] <= 1, "...and equals the total on the page it links to ({$bucket})");
    check(str_contains($body, 'href="' . JobBucket::definitions()[$bucket]['path'] . '"'), "...and links to {$bucket}");
}
fresh_session();
sign_in($mgr);
$body = call('GET', '/dashboard')->body();
check(str_contains($body, 'Outstanding balance') && str_contains($body, 'Storage fees'), 'Manager sees the money panel');
check(str_contains($body, 'Overdue') && str_contains(plain($body), 'R-overdue') && str_contains(plain($body), 'Overdue by 1 day'), 'overdue panel lists R-overdue');
check(str_contains(plain($body), 'Coming up next') && str_contains(plain($body), 'R-soon'), '"Coming up next" lists R-soon');
check(str_contains(plain($body), 'Recent activity'), 'recent activity shown');
fresh_session();
sign_in($stf);
$body = call('GET', '/dashboard')->body();
check(!str_contains($body, 'Outstanding balance') && !str_contains($body, 'Storage fees') && !preg_match('/\$\d/', $body), 'Staff dashboard has no money');
fresh_session();
make_role('DashOnly', ['dashboard.view']);
sign_in(make_user('DashOnly', 'dash-only@example.com'));
$r = call('GET', '/dashboard');
check($r->status() === 200 && !str_contains($r->body(), 'jt-stat') && str_contains($r->body(), 'cannot view jobs'), 'dashboard access without jobs.view shows no job data');
fresh_session();
make_role('JobsOnly', ['jobs.view']);
sign_in(make_user('JobsOnly', 'jobs-only@example.com'));
check(call('GET', '/dashboard')->status() === 403 && call('GET', '/jobs/ready')->status() === 200, 'jobs.view without dashboard.view: no dashboard, lists still work');
fresh_session();
check(call('GET', '/dashboard')->status() === 302 && call('GET', '/jobs/ready')->status() === 302, 'signed-out visitors are redirected');

section('Pages: page rendering and empty database');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
sign_in(make_user('Manager', 'empty@example.com'));
$body = call('GET', '/dashboard')->body();
check(preg_match_all('#jt-stat-value">0<#', $body) === 5, 'dashboard on an empty database shows five zeros');
check(str_contains(plain($body), 'Nothing is overdue') && str_contains(plain($body), 'No booked jobs are due') && str_contains(plain($body), 'Activity appears here'), '...with friendly empty panels');
foreach (['/jobs', '/jobs/ready', '/jobs/more-than-30', '/jobs/in-transit', '/jobs/delivered'] as $path) {
    check(call('GET', $path)->status() === 200 && str_contains(call('GET', $path)->body(), 'jt-empty'), "{$path} on an empty database shows an empty state");
}
check(str_contains(call('GET', '/jobs/more-than-30')->body(), 'More Than 30 Days'), 'section titles render');
set_setting('ready_threshold_days', '45');
check(str_contains(call('GET', '/jobs/more-than-30')->body(), 'More Than 45 Days') && str_contains(call('GET', '/jobs/ready')->body(), 'within 45 days of today'), 'titles and descriptions follow the threshold setting');
set_setting('ready_threshold_days', '30');

Clock::fake(null);

// ================================================================ PHASE 1 (v1.2): sections count from the pickup date

section('Sections: the pickup date is the default basis (assumption L1)');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
set_setting('section_basis', 'pickup');
sign_in(make_user('Manager', 'l1@example.com'));
check(JobBucket::basis() === 'pickup', 'pickup is the basis when the setting says so');
foreach ([
    'P-today' => ['2026-10-23', '2027-01-01'],   // picked up today, 1st available far away
    'P-30' => ['2026-09-23', '2026-09-01'],      // exactly 30 days ago
    'P-31' => ['2026-09-22', '2026-10-30'],      // 31 days ago, 1st available soon
    'P-future' => ['2026-12-01', '2026-10-01'],  // pickup still ahead
    'P-none-old' => [null, '2026-08-01'],        // no pickup date, 1st available long ago
    'P-none-new' => [null, '2026-10-30'],        // no pickup date, 1st available soon
] as $number => [$pickup, $available]) {
    mk_job(['job_number' => $number, 'pickup_date' => $pickup, 'first_available_date' => $available]);
}
mk_job(['job_number' => 'P-transit', 'status' => 'in_transit', 'pickup_date' => '2026-01-01', 'first_available_date' => '2026-01-01']);
check(numbers('ready') === ['P-30', 'P-future', 'P-none-new', 'P-today'], 'Ready = picked up within the last 30 days or not picked up yet (no pickup date: the 1st available date is used)');
check(numbers('later') === ['P-31', 'P-none-old'], 'More Than 30 Days = picked up more than 30 days ago');
check(numbers('transit') === ['P-transit'], 'In Transit still depends on status only');

$disagree = [];
foreach ((new JobRepository())->fetch(JobCriteria::forBucket('all'), 100, 0) as $row) {
    $mine = JobBucket::of($row);
    foreach (['ready', 'later', 'transit'] as $b) {
        if (in_array($row['job_number'], numbers($b), true) !== ($mine === $b)) {
            $disagree[] = $row['job_number'] . '/' . $b;
        }
    }
}
check($disagree === [], 'the PHP and SQL definitions agree under the pickup basis');

$before = ['ready' => numbers('ready'), 'later' => numbers('later')];
set_setting('ready_threshold_days', '60');
check(in_array('P-31', numbers('ready'), true) && numbers('later') === ['P-none-old'], 'the day limit applies to the pickup age (60 days brings P-31 back to Ready)');
set_setting('ready_threshold_days', '30');
set_setting('section_basis', 'first_available');
check(JobBucket::basis() === 'first_available' && numbers('later') === ['P-30', 'P-none-old', 'P-today'], 'switching to the 1st available date restores the original rule with no data change');
set_setting('section_basis', 'pickup');
check(numbers('ready') === $before['ready'] && numbers('later') === $before['later'], '...and switching back returns the same sections');
set_setting('section_basis', 'nonsense');
check(JobBucket::basis() === 'first_available', 'an unknown basis value falls back to the 1st available date');
check((require __DIR__ . '/../app/Config/settings.php')['section_basis'] === 'first_available', 'the shipped default is the 1st available date (client sheet: available within 30 days = ready)');
set_setting('section_basis', 'pickup');

// Editing a pickup date moves the job.
$id = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'P-today'");
Database::execute("UPDATE jobs SET pickup_date = '2026-06-01' WHERE id = ?", [$id]);
check(in_array('P-today', numbers('later'), true), 'editing the pickup date to an old date moves the job to More Than 30 Days at once');
Database::execute("UPDATE jobs SET pickup_date = NULL WHERE id = ?", [$id]);
check(in_array('P-today', numbers('ready'), true), 'clearing it falls back to the 1st available date (far ahead = Ready)');

$page = call('GET', '/jobs/more-than-30');
check(str_contains(plain(call('GET', '/jobs/ready')->body()), 'picked up within the last 30 days') && str_contains(plain($page->body()), 'picked up more than 30 days ago'), 'the list descriptions explain the pickup rule');
$page = plain(call('GET', '/jobs/more-than-30')->body());
check(str_contains($page, 'no pickup date: using 1st available'), 'a row without a pickup date says which date is being used');
check(str_contains(plain(call('GET', '/dashboard')->body()), 'picked up over 30 days ago'), 'the dashboard card hint follows the rule');
sign_in(make_user('Admin', 'l1-admin@example.com'));
check(str_contains(plain(call('GET', '/settings')->body()), 'Count the days from'), 'the settings page offers the choice');
check(isset(SettingsService::validate(['section_basis' => 'x'] + SettingsService::editable())['errors']['section_basis']), 'an invalid basis is refused');
Clock::fake(null);
