<?php

declare(strict_types=1);

/**
 * Roadmap phases 5 and 6 (version 1.2): foremen, foreman trips with per-job expense rows, migration 010, permissions,
 * audit. Included by tests/run.php (after phase11.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Database\Migrator;
use App\Repositories\JobRepository;
use App\Repositories\TripRepository;
use App\Services\EnvironmentCheck;
use App\Services\TripCriteria;
use App\Services\TripService;
use App\Services\TripTotals;

section('Trips: migration 010 keeps existing data');
$dir = sys_get_temp_dir() . '/jt-mig10-' . bin2hex(random_bytes(4));
mkdir($dir);
foreach (glob(BASE_PATH . '/database/migrations/00[1-9]_*.sql') as $f) {
    copy($f, $dir . '/' . basename($f));
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Database::setConnection($pdo);
(new Migrator($dir))->migrate();
$now = '2026-01-01 00:00:00';
$pdo->exec("INSERT INTO jobs (job_number, customer_first_name, customer_last_name, first_available_date, from_location, to_location, status, cf, balance, created_at, updated_at) VALUES ('OLD-10', 'A', 'B', '2026-01-01', 'X', 'Y', 'booked', 900, 50, '{$now}', '{$now}')");
copy(BASE_PATH . '/database/migrations/010_create_foremen_and_trips.sql', $dir . '/010_create_foremen_and_trips.sql');
check((new Migrator($dir))->migrate() === ['010_create_foremen_and_trips.sql'], 'only migration 010 runs on an existing database');
check((int) $pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn() === 1 && (float) $pdo->query("SELECT balance FROM jobs")->fetchColumn() === 50.0, 'existing jobs are untouched');
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('foremen', 'trips', 'trip_jobs')")->fetchAll(PDO::FETCH_COLUMN);
sort($tables);
check($tables === ['foremen', 'trip_jobs', 'trips'], 'the three new tables exist');
check((new Migrator($dir))->migrate() === [], 'running it again does nothing');
Database::setConnection(null);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);

section('Foremen');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'f-admin@example.com');
$mgr = make_user('Manager', 'f-mgr@example.com');
$stf = make_user('Staff', 'f-stf@example.com');
sign_in($stf);
check(call('GET', '/foremen')->status() === 403 && call('GET', '/trips')->status() === 403 && call('GET', '/trips/create')->status() === 403 && call('POST', '/foremen', ['name' => 'X'])->status() === 403, 'Staff (no trip permissions) cannot open or post anything');
fresh_session();
sign_in($mgr);
check(call('GET', '/foremen')->status() === 200 && str_contains(call('GET', '/foremen')->body(), 'No foremen yet'), 'a Manager sees the (empty) foremen page');
$r = call('POST', '/foremen', ['name' => 'Joe', 'phone' => '(555) 010-2231']);
$joe = (int) Database::fetchValue("SELECT id FROM foremen WHERE name = 'Joe'");
check($r->status() === 302 && $joe > 0, 'a foreman can be added');
call('POST', '/foremen', ['name' => 'Sam', 'phone' => '']);
$sam = (int) Database::fetchValue("SELECT id FROM foremen WHERE name = 'Sam'");
check(Database::fetchValue('SELECT phone FROM foremen WHERE id = ?', [$sam]) === null, 'a blank phone is stored as NULL');
call('POST', '/foremen', ['name' => 'Joe']);
check((int) Database::fetchValue("SELECT COUNT(*) FROM foremen WHERE name = 'Joe'") === 1 && isset($_SESSION['_flash_next']['errors']['name']), 'a duplicate name is refused');
call('POST', '/foremen', ['name' => '']);
check(isset($_SESSION['_flash_next']['errors']['name']), 'a name is required');
call('POST', '/foremen', ['name' => 'Bad', 'phone' => 'abc']);
check((int) Database::fetchValue("SELECT COUNT(*) FROM foremen WHERE name = 'Bad'") === 0, 'a bad phone number is refused');
call('POST', "/foremen/{$sam}", ['name' => 'Samuel', 'phone' => '555.010.7777']);
check(Database::fetchValue('SELECT name FROM foremen WHERE id = ?', [$sam]) === 'Samuel', 'a foreman can be renamed');
check(call('GET', "/foremen/{$sam}/edit")->status() === 200 && call('GET', '/foremen/9999/edit')->status() === 404, 'the edit page opens; an unknown id is a 404');
check(str_contains(call('GET', '/foremen')->body(), 'Samuel') && str_contains(call('GET', '/foremen')->body(), 'href="tel:5550102231"'), 'the list shows them, phone as a tap-to-call link');
$a = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'foreman_created' ORDER BY id LIMIT 1");
check(str_contains($a, 'Joe'), 'adding a foreman is audited');

section('Trips: create with the client\'s sheet columns');
$j1 = mk_job(['job_number' => 'TJ-11', 'customer_first_name' => 'John', 'customer_last_name' => 'smith', 'pickup_date' => '2026-10-24', 'from_location' => 'GA', 'to_location' => 'CA']);
$j2 = mk_job(['job_number' => 'TJ-12', 'customer_first_name' => 'Mia', 'customer_last_name' => 'Lee', 'pickup_date' => null, 'from_location' => 'FL', 'to_location' => 'NY']);
$j3 = mk_job(['job_number' => 'TJ-13']);
$gone = mk_job(['job_number' => 'TJ-GONE']);
(new JobRepository())->softDelete($gone, null);
$form = call('GET', '/trips/create');
$fb = $form->body();
check($form->status() === 200 && str_contains($fb, 'name="trip_number"') && str_contains($fb, 'name="foreman_id"') && str_contains($fb, 'name="collected_by"') && str_contains($fb, 'name="begins_on"') && str_contains($fb, 'name="ends_on"') && str_contains($fb, 'name="rows[0][job_number]"') && str_contains($fb, 'name="rows[0][diesel]"') && str_contains($fb, 'name="rows[4][commission]"') && !str_contains($fb, 'name="rows[5][job_number]"'), 'the form has the header, five spare job rows and the expense columns');
$post = [
    'trip_number' => 'TRIP-1', 'foreman_id' => (string) $joe, 'begins_on' => '2026-10-20', 'ends_on' => '2026-10-25', 'collected_by' => 'BH Office', 'notes' => 'First run',
    'rows' => [
        ['job_number' => 'tj-11', 'job_date' => '', 'bh' => 'BH', 'diesel' => '120.10', 'hotels' => '80', 'helper' => '50.20', 'misc' => '10', 'commission' => '375', 'extra' => '5', 'remark' => 'ok'],
        ['job_number' => 'TJ-12', 'job_date' => '2026-10-22', 'bh' => '', 'diesel' => '0.2', 'hotels' => '', 'helper' => '', 'misc' => '', 'commission' => '', 'extra' => '', 'remark' => ''],
        ['job_number' => '', 'job_date' => '', 'bh' => '', 'diesel' => '', 'hotels' => '', 'helper' => '', 'misc' => '', 'commission' => '', 'extra' => '', 'remark' => ''],
    ],
];
$r = call('POST', '/trips', $post);
$t1 = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'TRIP-1'");
check($r->status() === 302 && $t1 > 0, 'a trip with two jobs is created (a blank spare row is ignored)');
$trip = Database::fetchOne('SELECT * FROM trips WHERE id = ?', [$t1]);
check((int) $trip['foreman_id'] === $joe && $trip['begins_on'] === '2026-10-20' && $trip['ends_on'] === '2026-10-25' && $trip['collected_by'] === 'BH Office', 'the header is saved');
$rows = (new TripRepository())->rows($t1);
check(count($rows) === 2 && $rows[0]['job_number'] === 'TJ-11' && $rows[0]['customer_name'] === 'John smith' && $rows[0]['from_location'] === 'GA', 'the job number is matched case-insensitively; customer name and From are copied from the job');
check($rows[0]['job_date'] === '2026-10-24' && $rows[1]['job_date'] === '2026-10-22', 'a blank Date defaults to the job\'s pickup date; a typed one is kept');
check(n($rows[0]['diesel']) === 120.1 && n($rows[0]['hotels']) === 80.0 && n($rows[0]['helper']) === 50.2 && n($rows[0]['commission']) === 375.0 && $rows[0]['bh'] === 'BH' && $rows[1]['hotels'] === null, 'the expenses are saved exactly as typed (empty = NULL, not 0)');
check(TripTotals::forRows($rows)['diesel'] === '120.30' && TripTotals::forRows($rows)['total'] === '640.50', 'totals are exact in cents (120.10 + 0.20 = 120.30), total expenses = sum of the six columns');
check(TripTotals::forRows([['diesel' => '0.29'], ['diesel' => '0.57'], ['diesel' => null, 'misc' => '1.15']])['diesel'] === '0.86' && TripTotals::forRows([['misc' => '1.15'], ['misc' => '2.30']])['total'] === '3.45' && TripTotals::forRows([])['total'] === '0.00', 'totals do not lose a cent to floating point (0.29 + 0.57 = 0.86, 1.15 + 2.30 = 3.45)');
$page = plain(call('GET', "/trips/{$t1}")->body());
check(str_contains($page, 'Trip TRIP-1') && str_contains($page, 'Joe') && str_contains($page, 'BH Office') && str_contains($page, 'TJ-11') && str_contains($page, 'John smith') && str_contains($page, 'Totals') && str_contains($page, '$640.50') && str_contains($page, '$120.30'), 'the trip page shows the rows and the totals');
$audit = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'trip_created' ORDER BY id DESC LIMIT 1");
check(str_contains($audit, 'TRIP-1') && str_contains($audit, 'TJ-11') && str_contains($audit, 'TJ-12'), 'creation is audited with the jobs on it');
(new JobRepository())->update($j1, ['customer_last_name' => 'Changed'], null);
check((new TripRepository())->rows($t1)[0]['customer_name'] === 'John smith', 'editing the job later does not rewrite the trip row');

section('Trips: validation');
$bad = static function (array $over, string $field, string $why) use ($post): void {
    $before = (int) Database::fetchValue('SELECT COUNT(*) FROM trips');
    call('POST', '/trips', $over + ['trip_number' => 'TRIP-X'] + $post);
    check((int) Database::fetchValue('SELECT COUNT(*) FROM trips') === $before && isset($_SESSION['_flash_next']['errors'][$field]), $why);
};
$bad(['trip_number' => ''], 'trip_number', 'a trip number is required');
$bad(['trip_number' => 'TRIP-1'], 'trip_number', 'a duplicate trip number is refused');
$bad(['trip_number' => 'bad number!'], 'trip_number', 'a trip number with odd characters is refused');
$bad(['foreman_id' => ''], 'foreman_id', 'a foreman is required');
$bad(['foreman_id' => '9999'], 'foreman_id', 'an unknown foreman is refused');
$bad(['begins_on' => '2026-13-45'], 'begins_on', 'an impossible date is refused');
$bad(['begins_on' => '2026-10-25', 'ends_on' => '2026-10-20'], 'ends_on', 'the trip cannot end before it begins');
$bad(['rows' => []], 'rows', 'a trip needs at least one job');
$bad(['rows' => [['job_number' => 'NOPE-1']]], 'rows.0.job_number', 'an unknown job number is refused');
$bad(['rows' => [['job_number' => 'TJ-GONE']]], 'rows.0.job_number', 'a deleted job cannot be added');
$bad(['rows' => [['job_number' => 'TJ-11'], ['job_number' => 'tj-11']]], 'rows.1.job_number', 'the same job twice on one trip is refused');
$bad(['rows' => [['job_number' => 'TJ-11', 'diesel' => '-5']]], 'rows.0.diesel', 'a negative expense is refused');
$bad(['rows' => [['job_number' => 'TJ-11', 'misc' => 'abc']]], 'rows.0.misc', 'a non-numeric expense is refused');
$bad(['rows' => [['job_number' => 'TJ-11', 'extra' => '1.234']]], 'rows.0.extra', 'a third decimal is refused (no silent rounding)');
$bad(['rows' => [['job_number' => 'TJ-11', 'remark' => str_repeat('x', 501)]]], 'rows.0.remark', 'a too-long remark is refused');
$bad(['rows' => [['job_number' => 'TJ-11', 'job_date' => 'tomorrow']]], 'rows.0.job_date', 'a bad row date is refused');
$bad(['collected_by' => str_repeat('x', 151)], 'collected_by', 'collected by has a length limit');
check(call('POST', '/trips', ['trip_number' => 'T-NOCSRF', '_csrf' => 'wrong'] + $post)->status() === 403, 'a request without a valid token is refused');
$bad(['rows' => 'not-an-array'], 'rows', 'a malformed rows field is refused, not a crash');
$bad(['rows' => ['x' => 'y']], 'rows', 'a non-array row is skipped, not a crash (and then there is no job)');

section('Trips: edit, conflict, delete');
call('GET', '/trips'); // a page load, so the flash data of the last failed post is gone
$edit = call('GET', "/trips/{$t1}/edit");
$eb = $edit->body();
check($edit->status() === 200 && str_contains($eb, 'value="TRIP-1"') && str_contains($eb, 'value="TJ-11"') && (str_contains($eb, 'value="120.1"') || str_contains($eb, 'value="120.10"')), 'Edit shows the saved trip and rows');
check(str_contains($eb, 'name="rows[2][job_number]"') && str_contains($eb, 'name="rows[6][job_number]"') && !str_contains($eb, 'name="rows[7][job_number]"'), '...with five spare rows after the two saved ones');
$loaded = (string) Database::fetchValue('SELECT updated_at FROM trips WHERE id = ?', [$t1]);
$existing = (new TripRepository())->rows($t1);
$edited = $post;
$edited['loaded_at'] = $loaded;
$edited['collected_by'] = 'New Collector';
$edited['rows'] = [
    ['id' => (string) $existing[0]['id'], 'job_number' => 'TJ-11', 'job_date' => '', 'bh' => 'BH', 'diesel' => '200', 'hotels' => '80', 'helper' => '50.20', 'misc' => '10', 'commission' => '375', 'extra' => '5', 'remark' => 'ok'],
    ['job_number' => 'TJ-13', 'job_date' => '', 'bh' => '', 'diesel' => '30'],
];
$r = call('POST', "/trips/{$t1}", $edited);
$rows = (new TripRepository())->rows($t1);
check($r->status() === 302 && count($rows) === 2 && $rows[0]['job_number'] === 'TJ-11' && $rows[1]['job_number'] === 'TJ-13' && n($rows[0]['diesel']) === 200.0 && Database::fetchValue('SELECT collected_by FROM trips WHERE id = ?', [$t1]) === 'New Collector', 'rows can be changed, one removed and one added; the header can be edited');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'trip_updated' ORDER BY id DESC LIMIT 1");
check(str_contains($aud, 'New Collector') && str_contains($aud, 'TJ-13') && str_contains($aud, '"total_expenses"'), 'the edit is audited (header changes, jobs, total expenses)');
Database::execute('UPDATE trips SET updated_at = ? WHERE id = ?', ['2026-10-23 12:00:01', $t1]); // another save, a moment later
$r = call('POST', "/trips/{$t1}", $edited);
check(isset($_SESSION['_flash_next']['alert']) && str_contains(json_encode($_SESSION['_flash_next']['alert']), 'Someone else changed this trip'), 'saving with a stale form is refused as a conflict');
$loaded = (string) Database::fetchValue('SELECT updated_at FROM trips WHERE id = ?', [$t1]);
$before = (int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'trip_updated'");
$same = $edited;
$same['loaded_at'] = $loaded;
$same['rows'][0]['id'] = (string) $rows[0]['id'];
call('POST', "/trips/{$t1}", $same);
check((int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'trip_updated'") === $before, 'saving with no change writes no audit entry');
call('POST', "/trips/{$t1}", ['trip_number' => 'TRIP-1', 'foreman_id' => (string) $joe, 'loaded_at' => (string) Database::fetchValue('SELECT updated_at FROM trips WHERE id = ?', [$t1]), 'rows' => [['job_number' => 'TJ-11']]] + []);
check(count((new TripRepository())->rows($t1)) === 1, 'a row left out of the form is removed from the trip');
$sam2 = (int) $sam;
call('POST', "/foremen/{$sam2}/toggle");
check((int) Database::fetchValue('SELECT active FROM foremen WHERE id = ?', [$sam2]) === 0 && !str_contains(call('GET', '/trips/create')->body(), '>Samuel<'), 'a deactivated foreman disappears from the trip form');
call('POST', "/trips/{$t1}", ['trip_number' => 'TRIP-1', 'foreman_id' => (string) $sam2, 'loaded_at' => (string) Database::fetchValue('SELECT updated_at FROM trips WHERE id = ?', [$t1]), 'rows' => [['job_number' => 'TJ-11']]]);
check((int) Database::fetchValue('SELECT foreman_id FROM trips WHERE id = ?', [$t1]) === $sam2 && str_contains(call('GET', "/trips/{$t1}/edit")->body(), 'Samuel (inactive)'), 'but stays on his existing trips (shown as inactive)');
call('POST', "/foremen/{$sam2}/toggle");
$r = call('POST', "/trips/{$t1}/delete");
check($r->status() === 302 && Database::fetchValue('SELECT deleted_at FROM trips WHERE id = ?', [$t1]) !== null && call('GET', "/trips/{$t1}")->status() === 404 && call('GET', "/trips/{$t1}/edit")->status() === 404, 'delete is a soft delete; the trip then answers 404');
check(Database::fetchValue('SELECT COUNT(*) FROM jobs WHERE id = ?', [$j1]) == 1 && (int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$t1]) === 1, 'the jobs are not affected and the rows are kept');
check(call('POST', "/trips/{$t1}/delete")->status() === 404 && call('GET', '/trips/99999')->status() === 404 && call('POST', '/trips/99999', $post)->status() === 404, 'deleting twice or opening an unknown trip is a 404');
check(str_contains((string) Database::fetchValue("SELECT old_values FROM audit_logs WHERE action = 'trip_deleted' ORDER BY id DESC LIMIT 1"), 'TRIP-1'), 'delete is audited');

section('Trips: list, search, filters, foreman totals');
fresh_session();
sign_in($mgr);
$repo = new TripRepository();
$mk = static function (string $number, int $foreman, ?string $begins, string $jobNumber, string $diesel) use ($repo): int {
    $job = $repo->findJobByNumber($jobNumber);
    return $repo->create(['trip_number' => $number, 'foreman_id' => $foreman, 'begins_on' => $begins, 'ends_on' => null, 'collected_by' => 'Col-' . $number, 'notes' => null], [[
        'job_id' => (int) $job['id'], 'job_number' => $job['job_number'], 'customer_name' => $job['customer_first_name'] . ' ' . $job['customer_last_name'], 'job_date' => null,
        'from_location' => $job['from_location'], 'bh' => null, 'diesel' => $diesel, 'hotels' => null, 'helper' => null, 'misc' => null, 'commission' => null, 'extra' => null, 'remark' => null,
    ]], null);
};
for ($i = 1; $i <= 27; $i++) {
    $mk('LT-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), $i % 2 === 0 ? $joe : $sam, sprintf('2026-09-%02d', min($i, 28)), $i % 3 === 0 ? 'TJ-12' : 'TJ-13', '10.00');
}
$list = call('GET', '/trips');
$lb = $list->body();
check($list->status() === 200 && str_contains(plain($lb), '27 trips') && str_contains($lb, 'LT-27') && !str_contains($lb, 'LT-01'), 'the list shows the newest trip first, 25 per page (27 trips)');
check(str_contains(call('GET', '/trips', [], ['page' => '2'])->body(), 'LT-01') && str_contains($lb, 'Total expenses'), 'page 2 has the rest; a Manager sees the Total expenses column');
$ids = static fn (array $q): string => implode(',', array_map(static fn (array $r): string => $r['trip_number'], (new TripService())->search(TripCriteria::fromRequest(\App\Core\Request::make('GET', '/trips', [], $q)), 100, 0)));
check($ids(['q' => 'LT-05']) === 'LT-05', 'search by trip number');
check(substr_count($ids(['q' => 'Samuel']), 'LT') === 14 && $ids(['q' => 'nomatch']) === '', 'search by foreman name');
check($ids(['q' => 'Col-LT-09']) === 'LT-09', 'search by collected by');
check(substr_count($ids(['q' => 'TJ-12']), 'LT') === 9 && substr_count($ids(['q' => 'Mia']), 'LT') === 9, 'search by job number or customer name finds the trips that carry the job');
check(substr_count($ids(['foreman' => (string) $joe]), 'LT') === 13, 'filter by foreman');
check($ids(['from' => '2026-09-10', 'to' => '2026-09-12']) === 'LT-12,LT-11,LT-10', 'filter by the date the trip begins');
check(substr_count($ids(['q' => '%']), 'LT') === 0 && substr_count($ids(['q' => '_']), 'LT') === 0, 'LIKE wildcards in the search are literal');
check(substr_count($ids(['from' => 'garbage', 'to' => '2026-02-30']), 'LT') === 27, 'a garbage date filter is ignored, not an error');
check(call('GET', '/trips', [], ['foreman' => ['x'], 'q' => ['y'], 'page' => ['z']])->status() === 200, 'array-valued query parameters do not crash the list');
$fp = plain(call('GET', '/foremen')->body());
check(str_contains($fp, 'Joe') && str_contains($fp, '13') && str_contains($fp, '14') && str_contains($fp, '$130.00') && str_contains($fp, '$140.00'), 'the foreman page shows each foreman\'s trip count and total expenses');
check(count((new TripRepository())->forJob($j2)) === 9, 'a job knows which trips it is on');

section('Trips: money is hidden from people without trips.financials');
$rid = make_role('Trip clerk', ['dashboard.view', 'jobs.view', 'trips.view', 'trips.manage']);
$clerk = make_user('Trip clerk', 'clerk@example.com');
fresh_session();
sign_in($clerk);
$page = call('GET', '/trips')->body();
check(!str_contains($page, 'Total expenses') && !str_contains($page, '$10.00'), 'the list has no expense column for a clerk');
$tid = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'LT-03'");
$show = call('GET', "/trips/{$tid}")->body();
check(str_contains($show, 'LT-03') && !str_contains($show, 'Diesel') && !str_contains($show, '10.00') && !str_contains($show, 'Totals'), 'the trip page has no expense columns or totals');
check(!str_contains(call('GET', "/trips/{$tid}/edit")->body(), 'name="rows[0][diesel]"') && !str_contains(call('GET', '/trips/create')->body(), 'name="rows[0][diesel]"'), 'the form has no expense inputs');
$svc = (new TripService())->find($tid);
check($svc !== null && !array_key_exists('total_expenses', $svc) && $svc['totals'] === null && !array_key_exists('diesel', $svc['rows'][0]), 'the service strips the figures before any template sees them');
$rowsBefore = (new TripRepository())->rows($tid);
$loaded = (string) Database::fetchValue('SELECT updated_at FROM trips WHERE id = ?', [$tid]);
call('POST', "/trips/{$tid}", ['trip_number' => 'LT-03', 'foreman_id' => (string) Database::fetchValue('SELECT foreman_id FROM trips WHERE id = ?', [$tid]), 'collected_by' => 'Changed by clerk', 'loaded_at' => $loaded,
    'rows' => [['id' => (string) $rowsBefore[0]['id'], 'job_number' => $rowsBefore[0]['job_number'], 'remark' => 'clerk note', 'diesel' => '9999']]]);
$after = (new TripRepository())->rows($tid);
check(Database::fetchValue('SELECT collected_by FROM trips WHERE id = ?', [$tid]) === 'Changed by clerk' && $after[0]['remark'] === 'clerk note', 'a clerk can change the non-money fields');
check(n($after[0]['diesel']) === 10.0, 'editing a trip never wipes the hidden expenses, and a forged diesel value is ignored');
call('POST', '/trips', ['trip_number' => 'CLERK-NEW', 'foreman_id' => (string) $joe, 'rows' => [['job_number' => 'TJ-13', 'diesel' => '500', 'commission' => '80']]]);
$cr = (new TripRepository())->rows((int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'CLERK-NEW'"));
check(count($cr) === 1 && $cr[0]['diesel'] === null && $cr[0]['commission'] === null, 'a forged money field on a new trip is ignored');
check(call('GET', '/foremen')->status() === 403, 'a clerk without foremen.manage cannot open the foremen page');
fresh_session();
sign_in(make_user('Trip clerk', 'clerk2@example.com'));
check(call('POST', "/trips/{$tid}/delete")->status() === 302, 'trips.manage includes deleting');
fresh_session();
$viewer = make_role('Trip viewer', ['trips.view']);
sign_in(make_user('Trip viewer', 'viewer@example.com'));
check(call('GET', '/trips')->status() === 200 && call('GET', '/trips/create')->status() === 403 && call('POST', '/trips', $post)->status() === 403 && call('GET', '/trips/' . (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'LT-04'") . '/edit')->status() === 403, 'a viewer can look but not create or edit');
$show = call('GET', '/trips/' . (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'LT-04'"))->body();
check(!str_contains($show, '/edit') && !str_contains($show, '/delete'), '...and sees no Edit or Delete buttons');

check(!str_contains(call('GET', '/trips/create')->body(), 'style="') && !str_contains(call('GET', '/foremen')->body(), 'style="') && !str_contains(call('GET', '/trips')->body(), 'style="'), 'the trip pages use no inline styles (the Content Security Policy would block them)');

section('Trips: menu and permissions');
fresh_session();
sign_in($mgr);
$nav = plain((string) preg_replace('#.*<aside(.*?)</aside>.*#s', '$1', call('GET', '/account')->body()));
check(str_contains($nav, 'Trips') && str_contains($nav, 'Foremen'), 'a Manager sees Trips and Foremen in the menu');
fresh_session();
sign_in($stf);
$nav = plain((string) preg_replace('#.*<aside(.*?)</aside>.*#s', '$1', call('GET', '/account')->body()));
check(!str_contains($nav, 'Trips') && !str_contains($nav, 'Foremen'), 'Staff does not');
foreach (['trips.view', 'trips.manage', 'trips.financials', 'trips.export', 'foremen.manage'] as $perm) {
    $has = static fn (string $role): bool => (int) Database::fetchValue('SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.name = ? AND p.name = ?', [$role, $perm]) === 1;
    check($has('Admin') && $has('Manager') && !$has('Staff'), "{$perm}: Admin and Manager have it, Staff does not");
}
Clock::fake(null);

// ================================================================ roadmap phase 7: foreman report and CSV export

section('Trip report by foreman and CSV export');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$mgr = make_user('Manager', 'r-mgr@example.com');
$stf = make_user('Staff', 'r-stf@example.com');
$fr = new \App\Repositories\ForemanRepository();
$joe = $fr->create(['name' => 'Joe', 'phone' => null]);
$sam = $fr->create(['name' => 'Sam', 'phone' => null]);
$idle = $fr->create(['name' => 'Idle', 'phone' => null]);
$ja = mk_job(['job_number' => 'R-1', 'customer_first_name' => 'John', 'customer_last_name' => 'smith', 'from_location' => 'GA']);
$jb = mk_job(['job_number' => 'R-2', 'customer_first_name' => 'Mia', 'customer_last_name' => 'Lee', 'from_location' => 'FL']);
$repo = new TripRepository();
$mkRow = static function (int $jobId, string $num, string $cust, array $over = []): array {
    return $over + ['job_id' => $jobId, 'job_number' => $num, 'customer_name' => $cust, 'job_date' => '2026-10-02', 'from_location' => 'GA', 'bh' => null,
        'diesel' => null, 'hotels' => null, 'helper' => null, 'misc' => null, 'commission' => null, 'extra' => null, 'remark' => null];
};
$hdr = static fn (string $n, int $f, ?string $b): array => ['trip_number' => $n, 'foreman_id' => $f, 'begins_on' => $b, 'ends_on' => null, 'collected_by' => 'Col', 'notes' => null];
$repo->create($hdr('RP-1', $joe, '2026-10-01'), [$mkRow($ja, 'R-1', 'John smith', ['diesel' => '0.29', 'hotels' => '10', 'remark' => '=HYPERLINK("x")']), $mkRow($jb, 'R-2', 'Mia Lee', ['diesel' => '0.57', 'commission' => '100'])], null);
$repo->create($hdr('RP-2', $joe, '2026-10-15'), [$mkRow($ja, 'R-1', 'John smith', ['diesel' => '5', 'extra' => '1.15'])], null);
$repo->create($hdr('RP-3', $sam, '2026-09-10'), [$mkRow($jb, 'R-2', 'Mia Lee', ['misc' => '7.50', 'helper' => '2.30'])], null);
$repo->create($hdr('RP-4', $sam, null), [$mkRow($ja, 'R-1', 'John smith', ['diesel' => '1000'])], null);
$dead = $repo->create($hdr('RP-DEAD', $joe, '2026-10-02'), [$mkRow($ja, 'R-1', 'John smith', ['diesel' => '9999'])], null);
$repo->softDelete($dead, null);

sign_in($mgr);
$svc = new TripService();
$rep = $svc->foremanReport(TripCriteria::fromRequest(\App\Core\Request::make('GET', '/trips/report', [], [])));
$byName = array_column($rep['rows'], null, 'name');
check(array_keys($byName) === ['Joe', 'Sam'], 'the report lists foremen who have trips (Idle has none, so is left out)');
check($byName['Joe']['trips'] === 2 && $byName['Joe']['jobs'] === 3 && $byName['Sam']['trips'] === 2 && $byName['Sam']['jobs'] === 2, 'trip and job counts per foreman (the deleted trip is not counted)');
check($byName['Joe']['diesel'] === '5.86' && $byName['Joe']['hotels'] === '10.00' && $byName['Joe']['commission'] === '100.00' && $byName['Joe']['extra'] === '1.15' && $byName['Joe']['total'] === '117.01', 'each column is summed per foreman without losing a cent (0.29 + 0.57 + 5 = 5.86)');
check($byName['Sam']['misc'] === '7.50' && $byName['Sam']['helper'] === '2.30' && $byName['Sam']['diesel'] === '1000.00', 'Sam\'s columns');
check($rep['totals']['trips'] === 4 && $rep['totals']['jobs'] === 5 && $rep['totals']['diesel'] === '1005.86' && $rep['totals']['total'] === '1126.81', 'grand totals add the foremen up exactly (117.01 + 1009.80 = 1126.81)');
$q = static fn (array $query): array => $svc->foremanReport(TripCriteria::fromRequest(\App\Core\Request::make('GET', '/trips/report', [], $query)));
check(array_column($q(['from' => '2026-10-01', 'to' => '2026-10-31'])['rows'], 'name') === ['Joe'] && $q(['from' => '2026-10-01'])['totals']['trips'] === 2, 'the period filters trips by the day they begin; a trip with no begin date is left out of a period');
check(array_column($q(['foreman' => (string) $sam])['rows'], 'name') === ['Sam'] && $q(['foreman' => (string) $sam])['totals']['trips'] === 2, 'the foreman filter');
check($q(['from' => '2030-01-01'])['rows'] === [] && $q(['foreman' => ['x'], 'from' => ['y']])['totals']['trips'] === 4, 'an empty period gives no rows; array parameters are ignored');
$page = call('GET', '/trips/report');
$pb = plain($page->body());
check($page->status() === 200 && str_contains($pb, 'Joe') && str_contains($pb, 'Sam') && !preg_match('/foreman=\\d+[^>]*>Idle</', $page->body()) && str_contains($pb, '$117.01') && str_contains($pb, '$1,126.81') && str_contains($pb, 'Export CSV'), 'the report page shows the lines, the totals and an Export button');
check(str_contains(plain(call('GET', '/trips/report', [], ['from' => '2030-01-01'])->body()), 'No trips in this period'), 'an empty period shows a friendly message');
check(str_contains(call('GET', '/trips')->body(), 'Export CSV') && str_contains(call('GET', '/trips')->body(), '/trips/report'), 'the trip list links to the report and has an Export button');

// CSV of the trip list
$csv = call('GET', '/trips/export');
$text = $csv->content();
$lines = array_map('str_getcsv', array_filter(explode("\n", substr($text, 3))));
check($csv->status() === 200 && str_starts_with($text, "\xEF\xBB\xBF") && str_contains((string) $csv->getHeader('Content-Type'), 'text/csv') && str_contains((string) $csv->getHeader('Content-Disposition'), 'attachment; filename="trips-'), 'the export is a UTF-8 CSV with a BOM, sent as a download');
check($lines[0] === ['Trip Number', 'Foreman', 'Trip Begins', 'Trip Ends', 'Collected By', 'Job #', 'Customer Name', 'Date', 'From', 'BH', 'Diesel', 'Hotels', 'Helper', 'Misc', 'Commission', 'Extra', 'Remark'], 'the headings follow the client\'s sheet');
check(count($lines) === 1 + 5, 'one line per job row (5), not per trip; the deleted trip is absent');
$first = array_column(array_slice($lines, 1), null, 4 - 4 + 0);
check($lines[1][0] === 'RP-4' || $lines[1][0] === 'RP-2', 'newest trips first');
$rp1 = array_values(array_filter($lines, static fn (array $l): bool => $l[0] === 'RP-1'));
check(count($rp1) === 2 && $rp1[0][1] === 'Joe' && $rp1[0][2] === '2026-10-01' && $rp1[0][4] === 'Col' && $rp1[1][1] === 'Joe' && $rp1[0][5] === 'R-1' && $rp1[0][6] === 'John smith' && $rp1[0][10] === '0.29' && $rp1[0][11] === '10.00' && $rp1[1][14] === '100.00', 'the trip details are repeated on each of its job lines; amounts have two decimals');
check($rp1[0][16] === "'=HYPERLINK(\"x\")", 'a remark that looks like a formula is neutralised (CSV injection)');
check($rp1[1][11] === '' && $rp1[0][12] === '', 'an empty amount stays empty (not 0.00)');
$filtered = array_filter(explode("\n", substr(call('GET', '/trips/export', [], ['foreman' => (string) $sam])->content(), 3)));
check(count($filtered) === 1 + 2 && !str_contains(implode("\n", $filtered), 'RP-1'), 'the export follows the same filters as the list');
check(count(array_filter(explode("\n", substr(call('GET', '/trips/export', [], ['q' => 'Mia'])->content(), 3)))) === 1 + 3, 'and the search (customer Mia is on two trips, so all three of their job lines come out)');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'trips_exported' ORDER BY id DESC LIMIT 1");
check(str_contains($aud, '"rows":3') && str_contains($aud, 'csv'), 'every export is audited with the number of rows and the filters');

// CSV of the report
$rc = call('GET', '/trips/report/export');
$rl = array_map('str_getcsv', array_filter(explode("\n", substr($rc->content(), 3))));
check($rc->status() === 200 && $rl[0] === ['Foreman', 'Trips', 'Jobs', 'Diesel', 'Hotels', 'Helper', 'Misc', 'Commission', 'Extra', 'Total Expenses'] && $rl[1][0] === 'Joe' && $rl[1][9] === '117.01' && end($rl)[0] === 'Total' && end($rl)[9] === '1126.81', 'the report CSV has a line per foreman and a Total line');
check(str_contains((string) $rc->getHeader('Content-Disposition'), 'trips-by-foreman-'), '...named for what it is');

// people without the money or the export permission
fresh_session();
make_role('Clerk no money', ['trips.view', 'trips.manage', 'trips.export']);
sign_in(make_user('Clerk no money', 'rc@example.com'));
$cc = call('GET', '/trips/export')->content();
check(!str_contains($cc, 'Diesel') && !str_contains($cc, 'Commission') && !str_contains($cc, '100.00') && str_contains($cc, 'Remark'), 'the export has no expense columns for a user without trips.financials');
$rcc = call('GET', '/trips/report/export')->content();
check(str_starts_with(substr($rcc, 3), 'Foreman,Trips,Jobs') && !str_contains($rcc, 'Diesel') && !str_contains($rcc, 'Total Expenses') && str_contains($rcc, 'Joe,2,3'), 'the report CSV has only counts for that user');
$pp = plain(call('GET', '/trips/report')->body());
check(str_contains($pp, 'Joe') && !str_contains($pp, 'Diesel') && !str_contains($pp, '$') , 'the report page has counts only');
fresh_session();
make_role('Trip viewer 2', ['trips.view']);
sign_in(make_user('Trip viewer 2', 'tv2@example.com'));
check(call('GET', '/trips/report')->status() === 200 && call('GET', '/trips/export')->status() === 403 && call('GET', '/trips/report/export')->status() === 403, 'trips.view alone shows the report but cannot export');
check(!str_contains(call('GET', '/trips')->body(), 'Export CSV') && !str_contains(call('GET', '/trips/report')->body(), 'Export CSV'), '...and sees no Export buttons');
fresh_session();
sign_in($stf);
check(call('GET', '/trips/report')->status() === 403 && call('GET', '/trips/export')->status() === 403 && call('GET', '/trips/report/export')->status() === 403, 'Staff cannot open the report or either export');
fresh_session();
check(call('GET', '/trips/report')->status() === 302 && call('GET', '/trips/export')->status() === 302, 'signed-out visitors are sent to sign in');
Clock::fake(null);

// ================================================================ roadmap phase 8: trips on the job page, add to a trip, dashboard

section('Integration: trips on Job Details, add a job to a trip, dashboard');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$mgr = make_user('Manager', 'i-mgr@example.com');
$stf = make_user('Staff', 'i-stf@example.com');
$fr = new \App\Repositories\ForemanRepository();
$joe = $fr->create(['name' => 'Joe', 'phone' => null]);
$ja = mk_job(['job_number' => 'I-1', 'customer_first_name' => 'John', 'customer_last_name' => 'smith', 'pickup_date' => '2026-10-02', 'from_location' => 'GA']);
$jb = mk_job(['job_number' => 'I-2', 'customer_first_name' => 'Mia', 'customer_last_name' => 'Lee', 'pickup_date' => null, 'from_location' => 'FL']);
$jc = mk_job(['job_number' => 'I-3']);
$repo = new TripRepository();
$row = static fn (int $jid, string $num, string $cust, array $o = []): array => $o + ['job_id' => $jid, 'job_number' => $num, 'customer_name' => $cust, 'job_date' => null, 'from_location' => 'GA', 'bh' => null,
    'diesel' => null, 'hotels' => null, 'helper' => null, 'misc' => null, 'commission' => null, 'extra' => null, 'remark' => null];
$hdr = static fn (string $n, ?string $b): array => ['trip_number' => $n, 'foreman_id' => $joe, 'begins_on' => $b, 'ends_on' => null, 'collected_by' => null, 'notes' => null];
$tA = $repo->create($hdr('IT-A', '2026-10-10'), [$row($ja, 'I-1', 'John smith', ['diesel' => '40', 'hotels' => '10.50'])], null);
$tB = $repo->create($hdr('IT-B', '2026-10-12'), [$row($jc, 'I-3', 'Ada Smith', ['diesel' => '5'])], null);
$tOld = $repo->create($hdr('IT-OLD', '2026-09-01'), [$row($jc, 'I-3', 'Ada Smith', ['diesel' => '999'])], null);

sign_in($mgr);
$page = call('GET', "/jobs/{$ja}");
$pb = $page->body();
$pt = plain($pb);
check(str_contains($pt, 'Foreman trips') && str_contains($pb, 'href="/trips/' . $tA . '"') && str_contains($pt, 'IT-A') && str_contains($pt, 'Joe'), 'Job Details lists the trips the job is on, linked, with the foreman');
check(!str_contains($pb, '>IT-A</option>') && str_contains($pb, '>IT-B &middot; Joe</option>') || str_contains($pb, 'IT-B · Joe'), '"Add to a trip" offers the other trips but not the one it is already on');
check(str_contains($pb, '/trips/create?job=I-1'), 'and a "New trip with this job" link');
check(str_contains(plain(call('GET', "/jobs/{$jb}")->body()), 'This job is not on any trip yet'), 'a job on no trip says so');

$before = (int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$tB]);
Database::execute('UPDATE trips SET updated_at = ? WHERE id = ?', ['2026-10-23 08:00:00', $tB]);
$r = call('POST', "/jobs/{$ja}/trip", ['trip' => (string) $tB]);
$rows = $repo->rows($tB);
check($r->status() === 302 && count($rows) === $before + 1 && $rows[1]['job_number'] === 'I-1' && $rows[1]['customer_name'] === 'John smith' && $rows[1]['from_location'] === 'GA' && $rows[1]['job_date'] === '2026-10-02', 'adding appends a row at the end with the job\'s number, customer, From and pickup date');
check($rows[1]['diesel'] === null && $rows[1]['commission'] === null && (int) $rows[1]['sort_order'] === 1, '...and no expenses (they are entered on the trip)');
check(Database::fetchValue('SELECT updated_at FROM trips WHERE id = ?', [$tB]) !== '2026-10-23 08:00:00', 'the trip counts as changed, so an edit form opened earlier gets the conflict warning');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'trip_updated' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$tB]);
check(str_contains($aud, 'I-1'), 'it is audited');
check(count($repo->forJob($ja)) === 2 && !str_contains(call('GET', "/jobs/{$ja}")->body(), '>IT-B &middot; Joe</option>'), 'the job now shows both trips and IT-B is no longer offered');
call('POST', "/jobs/{$ja}/trip", ['trip' => (string) $tB]);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ? AND job_id = ?', [$tB, $ja]) === 1 && isset($_SESSION['_flash_next']['alert']), 'adding the same job again changes nothing and says so');
call('POST', "/jobs/{$ja}/trip", ['trip' => '']);
call('POST', "/jobs/{$ja}/trip", ['trip' => '99999']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE job_id = ?', [$ja]) === 2, 'no trip chosen, or an unknown trip, changes nothing');
check(call('POST', '/jobs/99999/trip', ['trip' => (string) $tA])->status() === 404, 'an unknown job is a 404');
$gone = mk_job(['job_number' => 'I-GONE']);
(new JobRepository())->softDelete($gone, null);
check(call('POST', "/jobs/{$gone}/trip", ['trip' => (string) $tA])->status() === 404, 'a deleted job cannot be added');
$tDel = $repo->create($hdr('IT-DEL', '2026-10-14'), [$row($jb, 'I-2', 'Mia Lee')], null);
Database::execute('UPDATE trips SET deleted_at = ? WHERE id = ?', ['2026-10-20 00:00:00', $tDel]);
call('POST', "/jobs/{$jc}/trip", ['trip' => (string) $tDel]);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ? AND job_id = ?', [$tDel, $jc]) === 0, 'a deleted trip cannot be added to');
check(!str_contains(call('GET', "/jobs/{$ja}")->body(), 'IT-DEL') && !str_contains(call('GET', "/jobs/{$jb}")->body(), 'IT-DEL'), 'and a deleted trip is neither offered nor listed on a job');

// prefill
$f = call('GET', '/trips/create', [], ['job' => 'I-2'])->body();
check(preg_match('/name="rows\[0\]\[job_number\]"[^>]*value="I-2"/', $f) === 1 && preg_match('/name="rows\[1\]\[job_number\]"[^>]*value=""/', $f) === 1, '"New trip with this job" puts the job number in the first row');
check(str_contains(call('GET', '/trips/create', [], ['job' => str_repeat('x', 200)])->body(), 'value="' . str_repeat('x', 40) . '"') && !str_contains(call('GET', '/trips/create', [], ['job' => str_repeat('x', 200)])->body(), str_repeat('x', 41)) && call('GET', '/trips/create', [], ['job' => ['a']])->status() === 200, 'an over-long or malformed job parameter is harmless');
check(!str_contains(call('GET', '/trips/create', [], ['job' => '"><script>alert(1)</script>'])->body(), '<script>alert(1)'), 'and is escaped');

// deleting a job that is on a trip
(new JobRepository())->softDelete($jc, null);
$show = call('GET', "/trips/{$tB}")->body();
check(str_contains(plain($show), 'I-3') && str_contains($show, 'job deleted') && !str_contains($show, 'href="/jobs/' . $jc . '"'), 'a trip still shows a deleted job, marked, without a dead link');
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE job_id = ?', [$jc]) === 2, 'deleting a job never deletes trip rows');

// dashboard
$d = plain(call('GET', '/dashboard')->body());
check(str_contains($d, 'Foreman trips') && str_contains($d, 'last 30 days'), 'the dashboard has a trips card');
$fig = (new TripService())->dashboardFigures();
check($fig['trips'] === 2 && $fig['jobs'] === 3 && $fig['expenses'] === '55.50', 'it counts trips that began in the last 30 days only (IT-OLD, 52 days ago, is out): 2 trips, 3 job lines, expenses 40 + 10.50 + 5');
check(str_contains($d, '$55.50'), '...and shows the expenses to a Manager');
fresh_session();
make_role('Trip clerk 3', ['dashboard.view', 'jobs.view', 'trips.view']);
sign_in(make_user('Trip clerk 3', 'tc3@example.com'));
$d = plain(call('GET', '/dashboard')->body());
check(str_contains($d, 'Foreman trips') && !str_contains($d, '$55.50') && (new TripService())->dashboardFigures()['expenses'] === null, 'without trips.financials the card has counts but no expenses');
check(!str_contains(call('GET', "/jobs/{$ja}")->body(), 'Add to a trip') && !str_contains(call('GET', "/jobs/{$ja}")->body(), 'New trip with this job'), 'a viewer sees the trips on a job but has no Add button');
check(call('POST', "/jobs/{$ja}/trip", ['trip' => (string) $tB])->status() === 403, 'and cannot add (403)');
fresh_session();
sign_in($stf);
$sb = call('GET', "/jobs/{$ja}")->body();
check(!str_contains($sb, 'Foreman trips') && !str_contains(plain(call('GET', '/dashboard')->body()), 'Foreman trips') && call('POST', "/jobs/{$ja}/trip", ['trip' => (string) $tB])->status() === 403, 'Staff sees no trips panel, no dashboard card and cannot add');
Clock::fake(null);

// ================================================================ roadmap phase 9: security checks for the trip module

section('Trips: security (XSS, SQL injection, CSRF, limits)');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$mgr = make_user('Manager', 's-mgr@example.com');
$fr = new \App\Repositories\ForemanRepository();
$xss = '<script>alert(1)</script>';
$foreman = $fr->create(['name' => 'Joe ' . $xss, 'phone' => null]);
$sj = mk_job(['job_number' => 'S-1', 'customer_first_name' => 'A' . $xss, 'customer_last_name' => 'B', 'from_location' => '"><img src=x onerror=alert(2)>']);
sign_in($mgr);
$r = call('POST', '/trips', ['trip_number' => 'SEC-1', 'foreman_id' => (string) $foreman, 'collected_by' => $xss, 'notes' => $xss,
    'rows' => [['job_number' => 'S-1', 'bh' => $xss, 'remark' => $xss, 'diesel' => '1']]]);
$tid = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'SEC-1'");
check($r->status() === 302 && $tid > 0, 'a trip full of markup is accepted as plain text');
$pages = [call('GET', "/trips/{$tid}")->body(), call('GET', "/trips/{$tid}/edit")->body(), call('GET', '/trips')->body(), call('GET', '/trips/report')->body(), call('GET', '/foremen')->body(), call('GET', '/trips/create')->body(), call('GET', "/jobs/{$sj}")->body()];
$leak = false;
foreach ($pages as $html) {
    $leak = $leak || str_contains($html, '<script>alert(1)') || str_contains($html, '<img src=x');
}
check(!$leak, 'trip, foreman, customer and From values are escaped on every page that shows them (trip, edit, list, report, foremen, new trip, job)');
check(str_contains($pages[0], '&lt;script&gt;alert(1)&lt;/script&gt;'), '...as visible text');
$csv = call('GET', '/trips/export')->content();
check(str_contains($csv, '<script>') && !str_contains($csv, "\n<script>"), 'a CSV is data, not HTML; its cells just carry the text');

$before = [(int) Database::fetchValue('SELECT COUNT(*) FROM trips'), (int) Database::fetchValue('SELECT COUNT(*) FROM jobs'), (int) Database::fetchValue('SELECT COUNT(*) FROM foremen')];
foreach (["' OR '1'='1", "'; DROP TABLE trips; --", '" OR 1=1 --', "\\' UNION SELECT password_hash FROM users --", '%\' AND SLEEP(5) AND \'%'] as $evil) {
    foreach ([['q' => $evil], ['foreman' => $evil], ['from' => $evil], ['page' => $evil]] as $query) {
        if (call('GET', '/trips', [], $query)->status() !== 200 || call('GET', '/trips/report', [], $query)->status() !== 200 || call('GET', '/trips/export', [], $query)->status() !== 200) {
            check(false, 'hostile query ' . json_encode($query) . ' broke a trip page');
        }
    }
    call('POST', '/trips', ['trip_number' => $evil, 'foreman_id' => $evil, 'rows' => [['job_number' => $evil, 'diesel' => $evil]]]);
    call('POST', '/foremen', ['name' => $evil . 'x', 'phone' => $evil]);
}
$after = [(int) Database::fetchValue('SELECT COUNT(*) FROM trips'), (int) Database::fetchValue('SELECT COUNT(*) FROM jobs'), (int) Database::fetchValue('SELECT COUNT(*) FROM foremen')];
check($after[0] === $before[0] && $after[1] === $before[1], 'SQL injection strings in every search, filter and form field create no trip and change no job, and break no page');
call('POST', '/foremen', ['name' => "'; DROP TABLE trips; --x", 'phone' => '']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM foremen WHERE name = ?', ["'; DROP TABLE trips; --x"]) === 1 && (int) Database::fetchValue('SELECT COUNT(*) FROM trips') >= 1, '...a foreman name that looks like SQL is stored as plain text, and the trips table is still there');

// CSRF on every state-changing trip route
$post = [['/trips', ['trip_number' => 'CSRF-1']], ["/trips/{$tid}", []], ["/trips/{$tid}/delete", []], ['/foremen', ['name' => 'Csrf']], ["/foremen/{$foreman}", ['name' => 'Csrf2']], ["/foremen/{$foreman}/toggle", []], ["/jobs/{$sj}/trip", ['trip' => (string) $tid]]];
$bad = 0;
foreach ($post as [$path, $data]) {
    next_request();
    $_GET = [];
    \App\Core\Auth::reset();
    $res = real_router()->dispatch(\App\Core\Request::make('POST', $path, $data + ['_csrf' => 'forged'], [], [], []));
    $bad += $res->status() === 403 ? 0 : 1;
}
check($bad === 0 && Database::fetchValue('SELECT deleted_at FROM trips WHERE id = ?', [$tid]) === null && (int) Database::fetchValue("SELECT COUNT(*) FROM foremen WHERE name LIKE 'Csrf%'") === 0, 'every trip and foreman POST route refuses a forged token and changes nothing');

// limits
$many = [];
for ($i = 0; $i < 61; $i++) {
    $many[] = ['job_number' => 'S-1'];
}
$p = (new TripService())->prepare(['trip_number' => 'LIM-1', 'foreman_id' => (string) $foreman, 'rows' => $many]);
check(isset($p['errors']['rows']) && str_contains(implode(' ', $p['errors']['rows']), 'at most 60'), 'more than 60 job rows on one trip is refused with a message (PHP would silently drop the rest of a bigger form)');
$huge = array_fill(0, 500, ['job_number' => 'S-1']);
$t0 = microtime(true);
$p = (new TripService())->prepare(['trip_number' => 'LIM-2', 'foreman_id' => (string) $foreman, 'rows' => $huge]);
check(isset($p['errors']['rows']) && microtime(true) - $t0 < 1.0, 'a body with hundreds of rows is refused before any row is validated (fast)');
$sixty = [];
for ($i = 0; $i < 60; $i++) {
    $sixty[] = $i === 0 ? ['job_number' => 'S-1'] : [];
}
check((new TripService())->prepare(['trip_number' => 'LIM-3', 'foreman_id' => (string) $foreman, 'rows' => $sixty])['errors'] === [], 'blank spare rows do not count towards the limit');
$longs = (new TripService())->prepare(['trip_number' => str_repeat('A', 41), 'foreman_id' => (string) $foreman, 'notes' => str_repeat('é', 5001), 'rows' => [['job_number' => str_repeat('9', 41)]]]);
check(isset($longs['errors']['trip_number']) && isset($longs['errors']['notes']) && isset($longs['errors']['rows.0.job_number']), 'over-long values are refused');
$utf = (new TripService())->prepare(['trip_number' => 'UTF-1', 'foreman_id' => (string) $foreman, 'collected_by' => 'Zoë 北京 😀', 'rows' => [['job_number' => 'S-1', 'remark' => 'ça va 😀']]]);
check($utf['errors'] === [] && $utf['header']['collected_by'] === 'Zoë 北京 😀', 'unicode text is accepted and kept');
Clock::fake(null);

// ================================================================ version 1.3.1: visible deployment, defaults, role sync

section('Deployment: version in the footer, client defaults migration, default role sync');
$dir = sys_get_temp_dir() . '/jt-mig11-' . bin2hex(random_bytes(4));
mkdir($dir);
foreach (glob(BASE_PATH . '/database/migrations/0{0[1-9],10}_*.sql', GLOB_BRACE) as $f) {
    copy($f, $dir . '/' . basename($f));
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Database::setConnection($pdo);
(new Migrator($dir))->migrate();
$now = '2026-01-01 00:00:00';
foreach ([['section_basis', 'first_available'], ['storage_fee_basis', 'first_available'], ['storage_fee_mode', 'once'], ['ready_threshold_days', '45']] as [$k, $v]) {
    $pdo->exec("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('{$k}', '{$v}', '{$now}')");
}
$pdo->exec("INSERT INTO jobs (job_number, customer_first_name, customer_last_name, first_available_date, from_location, to_location, status, cf, balance, storage_fee, storage_fee_override, created_at, updated_at) VALUES ('M11', 'A', 'B', '2026-01-01', 'X', 'Y', 'booked', 900, 50, 12.5, 12.5, '{$now}', '{$now}')");
copy(BASE_PATH . '/database/migrations/011_client_defaults_pickup_monthly.sql', $dir . '/011_client_defaults_pickup_monthly.sql');
check((new Migrator($dir))->migrate() === ['011_client_defaults_pickup_monthly.sql'], 'only migration 011 runs');
$set = array_column($pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(), 'setting_value', 'setting_key');
check($set['section_basis'] === 'pickup' && $set['storage_fee_basis'] === 'pickup' && $set['storage_fee_mode'] === 'monthly', 'settings that held the old defaults move to the pickup date and monthly charging');
check($set['ready_threshold_days'] === '45', 'other settings are untouched');
$job = $pdo->query("SELECT * FROM jobs WHERE job_number = 'M11'")->fetch();
check((float) $job['storage_fee_override'] === 12.5 && (float) $job['storage_fee'] === 12.5 && (float) $job['balance'] === 50.0, 'no job and no manually entered fee is touched');
$pdo->exec("UPDATE settings SET setting_value = 'loaded' WHERE setting_key = 'storage_fee_basis'");
$pdo->exec("DELETE FROM migrations WHERE filename = '011_client_defaults_pickup_monthly.sql'");
(new Migrator($dir))->migrate();
check($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'storage_fee_basis'")->fetchColumn() === 'loaded', 'a value an administrator chose on purpose (loaded date) is left alone');
check($pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'nonexistent'")->fetchColumn() == 0 && (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 4, 'no setting row is created (seeding does that)');
Database::setConnection(null);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);

fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'v-admin@example.com');
$mgr = make_user('Manager', 'v-mgr@example.com');
sign_in($admin);
$foot = plain(call('GET', '/dashboard')->body());
check(str_contains($foot, 'version ' . app_version()), 'the footer shows the version of the code that is running');
check(app_build() === null || preg_match('/^[0-9a-f]{7}$/', (string) app_build()) === 1, 'the build id is a short commit id, or nothing when not deployed from a clone');
fresh_session();
sign_in($mgr);
check(!str_contains(plain(call('GET', '/dashboard')->body()), ' build '), 'only an administrator is shown the build id');

$seeder = new \App\Database\Seeder();
check($seeder->missingDefaults() === [], 'a fresh install: every default role holds its defaults');
$mgrId = (int) Database::fetchValue("SELECT id FROM roles WHERE name = 'Manager'");
$staffId = (int) Database::fetchValue("SELECT id FROM roles WHERE name = 'Staff'");
foreach (['trips.view', 'trips.export', 'settings.storage_fee'] as $k) {
    Database::execute('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = (SELECT id FROM permissions WHERE name = ?)', [$mgrId, $k]);
}
Database::execute('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = ?', [$staffId, 'users.manage']);
Database::execute('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = (SELECT id FROM permissions WHERE name = ?)', [$mgrId, 'jobs.import']);
$missing = $seeder->missingDefaults();
check(array_keys($missing) === ['Manager'] && in_array('trips.view', $missing['Manager'], true) && in_array('trips.export', $missing['Manager'], true) && in_array('settings.storage_fee', $missing['Manager'], true), 'a Manager created before newer permissions existed is reported as lacking them');
$rows = EnvironmentCheck::run();
$warn = array_values(array_filter($rows, static fn (array $r): bool => str_contains($r['label'], 'Default roles')));
check($warn !== [] && $warn[0]['level'] === 'warn' && str_contains($warn[0]['detail'], 'sync-roles'), 'php cli/doctor.php warns and says which command fixes it');
$granted = $seeder->syncDefaultRoles();
check(count($granted['Manager']) === 4 && $seeder->missingDefaults() === [] && count($seeder->syncDefaultRoles()) === 0, 'syncing grants exactly the missing defaults, and running it again changes nothing');
check((int) Database::fetchValue('SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? AND p.name = ?', [$staffId, 'users.manage']) === 1, 'it never removes anything: an extra permission ticked on Staff stays');
check((int) Database::fetchValue('SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? AND p.name = ?', [$staffId, 'trips.view']) === 0, '...and Staff does not get Manager\'s permissions');
Clock::fake(null);
