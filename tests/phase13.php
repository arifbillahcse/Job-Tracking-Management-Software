<?php

declare(strict_types=1);

/**
 * Client follow-up (version 1.3.2): a Foreman and a Trip Number assigned to every job, kept in step with the trip module.
 * Migration 012, the assignment rules, job form / details / import / export, and the two-way sync with trips.
 * Included by tests/run.php (after phase12.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Database\Migrator;
use App\Repositories\ForemanRepository;
use App\Repositories\JobRepository;
use App\Repositories\TripRepository;
use App\Services\ExportService;
use App\Services\ImportService;
use App\Services\JobCriteria;
use App\Services\TripService;

section('Job assignment: migration 012 maps what is already there');
$dir = sys_get_temp_dir() . '/jt-mig12-' . bin2hex(random_bytes(4));
mkdir($dir);
foreach (glob(BASE_PATH . '/database/migrations/0{0[1-9],10,11}_*.sql', GLOB_BRACE) as $f) {
    copy($f, $dir . '/' . basename($f));
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Database::setConnection($pdo);
(new Migrator($dir))->migrate();
$now = '2026-01-01 00:00:00';
$pdo->exec("INSERT INTO foremen (name, phone, active, created_at, updated_at) VALUES ('Joe', NULL, 1, '{$now}', '{$now}')");
$ins = $pdo->prepare("INSERT INTO jobs (job_number, customer_first_name, customer_last_name, first_available_date, from_location, to_location, status, foreman_name, created_at, updated_at) VALUES (?, 'A', 'B', '2026-01-01', 'X', 'Y', 'booked', ?, '{$now}', '{$now}')");
foreach ([['J-JOE', 'Joe'], ['J-SAM', ' Sam '], ['J-SAM2', 'Sam'], ['J-NONE', null], ['J-BLANK', '  '], ['J-ONTRIP', null], ['J-TWO', 'Old Name']] as $r) {
    $ins->execute($r);
}
$jid = static fn (string $n): int => (int) $pdo->query("SELECT id FROM jobs WHERE job_number = '{$n}'")->fetchColumn();
$pdo->exec("INSERT INTO foremen (name, phone, active, created_at, updated_at) VALUES ('Tripman', NULL, 1, '{$now}', '{$now}')");
$tm = (int) $pdo->query("SELECT id FROM foremen WHERE name = 'Tripman'")->fetchColumn();
foreach ([['OLD-T', '2026-02-01', null], ['NEW-T', '2026-05-01', null], ['DEAD-T', '2026-09-01', '2026-09-02 00:00:00']] as [$n, $b, $d]) {
    $pdo->exec("INSERT INTO trips (trip_number, foreman_id, begins_on, deleted_at, created_at, updated_at) VALUES ('{$n}', {$tm}, '{$b}', " . ($d === null ? 'NULL' : "'{$d}'") . ", '{$now}', '{$now}')");
}
$tid = static fn (string $n): int => (int) $pdo->query("SELECT id FROM trips WHERE trip_number = '{$n}'")->fetchColumn();
foreach ([['J-ONTRIP', 'OLD-T'], ['J-ONTRIP', 'NEW-T'], ['J-ONTRIP', 'DEAD-T'], ['J-TWO', 'DEAD-T']] as [$j, $t]) {
    $pdo->exec('INSERT INTO trip_jobs (trip_id, job_id, sort_order, job_number, customer_name, diesel) VALUES (' . $tid($t) . ', ' . $jid($j) . ", 0, '{$j}', 'A B', 12.5)");
}
copy(BASE_PATH . '/database/migrations/012_job_foreman_and_trip.sql', $dir . '/012_job_foreman_and_trip.sql');
check((new Migrator($dir))->migrate() === ['012_job_foreman_and_trip.sql'], 'only migration 012 runs on an existing database');
$names = $pdo->query('SELECT name FROM foremen ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
check($names === ['Joe', 'Old Name', 'Sam', 'Tripman'], 'every foreman name typed on a job becomes a foreman once (Joe is not duplicated, " Sam " and "Sam" are one)');
$fid = static fn (string $n): ?int => ($v = $pdo->query("SELECT foreman_id FROM jobs WHERE job_number = '{$n}'")->fetchColumn()) === null ? null : (int) $v;
$fname = static fn (string $n): ?string => ($v = $pdo->query("SELECT name FROM foremen WHERE id = " . (int) $fid($n))->fetchColumn()) === false ? null : (string) $v;
check($fname('J-JOE') === 'Joe' && $fname('J-SAM') === 'Sam' && $fid('J-SAM') === $fid('J-SAM2') && $fid('J-NONE') === null && $fid('J-BLANK') === null, 'jobs point at their foreman; jobs without a name stay without one');
$tripOf = static fn (string $n): ?int => ($v = $pdo->query("SELECT trip_id FROM jobs WHERE job_number = '{$n}'")->fetchColumn()) === null ? null : (int) $v;
check($tripOf('J-ONTRIP') === $tid('NEW-T') && $fid('J-ONTRIP') === $tm, 'a job already on trips gets the newest live trip, and that trip\'s foreman');
check($tripOf('J-TWO') === null && $fname('J-TWO') === 'Old Name', 'a deleted trip is not assigned, and the job keeps its own foreman');
check((int) $pdo->query('SELECT COUNT(*) FROM trip_jobs')->fetchColumn() === 4 && (float) $pdo->query('SELECT SUM(diesel) FROM trip_jobs')->fetchColumn() === 50.0, 'no trip row and no expense is changed');
check($pdo->query("SELECT foreman_name FROM jobs WHERE job_number = 'J-SAM'")->fetchColumn() === ' Sam ', 'the old free-text column is kept as it was');
check((new Migrator($dir))->migrate() === [], 'running it again does nothing');
Database::setConnection(null);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);

section('Job assignment: the rules');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'a-admin@example.com');
$mgr = make_user('Manager', 'a-mgr@example.com');
$stf = make_user('Staff', 'a-stf@example.com');
$fr = new ForemanRepository();
$joe = $fr->create(['name' => 'Joe', 'phone' => null]);
$sam = $fr->create(['name' => 'Sam', 'phone' => null]);
$old = $fr->create(['name' => 'Retired', 'phone' => null]);
$fr->setActive($old, false);
$repo = new TripRepository();
$row = static fn (int $jid, string $num, array $o = []): array => $o + ['job_id' => $jid, 'job_number' => $num, 'customer_name' => 'X Y', 'job_date' => null, 'from_location' => 'GA', 'bh' => null,
    'diesel' => null, 'hotels' => null, 'helper' => null, 'misc' => null, 'commission' => null, 'extra' => null, 'remark' => null];
$hdr = static fn (string $n, int $f, ?string $b = null): array => ['trip_number' => $n, 'foreman_id' => $f, 'begins_on' => $b, 'ends_on' => null, 'collected_by' => null, 'notes' => null];
$seedJob = mk_job(['job_number' => 'SEED']);
$t1 = $repo->create($hdr('AS-1', $joe, '2026-10-01'), [$row($seedJob, 'SEED')], null);
$t2 = $repo->create($hdr('AS-2', $sam, '2026-10-05'), [$row($seedJob, 'SEED')], null);
sign_in($mgr);
$svc = new TripService();
$a = $svc->resolveAssignment('', '');
check($a['foreman_id'] === null && $a['trip_id'] === null && $a['errors'] === [], 'nothing chosen: no foreman, no trip');
$a = $svc->resolveAssignment((string) $sam, '');
check($a['foreman_id'] === (string) $sam && $a['trip_id'] === null && $a['errors'] === [], 'a foreman alone is accepted');
$a = $svc->resolveAssignment('', (string) $t1);
check($a['trip_id'] === (string) $t1 && $a['foreman_id'] === (string) $joe && $a['note'] === null, 'a trip brings its own foreman');
$a = $svc->resolveAssignment((string) $sam, (string) $t1);
check($a['foreman_id'] === (string) $joe && str_contains((string) $a['note'], 'Joe') && str_contains((string) $a['note'], 'AS-1'), 'a trip beats a different foreman, and says so');
check(isset($svc->resolveAssignment('', '99999')['errors']['trip_id']), 'an unknown trip is an error');
$repo->softDelete($t2, null);
check(isset($svc->resolveAssignment('', (string) $t2)['errors']['trip_id']), 'a deleted trip is an error');
check(isset($svc->resolveAssignment('99999', '')['errors']['foreman_id']), 'an unknown foreman is an error');
check(isset($svc->resolveAssignment((string) $old, '')['errors']['foreman_id']) && $svc->resolveAssignment((string) $old, '', ['foreman_id' => $old])['errors'] === [], 'an inactive foreman cannot be newly chosen, but may stay on a job that already has him');
check($svc->resolveAssignment('abc', '-5')['foreman_id'] === null && $svc->resolveAssignment('0', '0')['trip_id'] === null, 'non-numeric or zero values count as nothing chosen');

section('Job assignment: form, details, lists, audit');
fresh_session();
sign_in($mgr);
$t2 = $repo->create($hdr('AS-3', $sam, '2026-10-06'), [$row($seedJob, 'SEED')], null);
$jobA = mk_job(['job_number' => 'A-1', 'customer_first_name' => 'Ada', 'customer_last_name' => 'Smith', 'pickup_date' => '2026-10-02', 'from_location' => 'GA']);
$f = call('GET', '/jobs/create')->content();
check(preg_match('/<select[^>]*name="trip_id"/', $f) === 1 && preg_match('/<select[^>]*name="foreman_id"/', $f) === 1 && str_contains($f, '>AS-1 &middot; Joe<') && str_contains($f, '>Sam<') && !str_contains($f, '>Retired'), 'Add Job has Trip and Foreman choices (trip numbers with their foreman; inactive foremen are not offered)');
$r = call('POST', '/jobs', job_input(['job_number' => 'A-NEW', 'trip_id' => (string) $t1, 'foreman_id' => (string) $sam, 'customer_first_name' => 'Nia', 'customer_last_name' => 'Lee']));
$newId = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'A-NEW'");
$nj = (new JobRepository())->find($newId);
check($r->status() === 302 && (int) $nj['trip_id'] === $t1 && (int) $nj['foreman_id'] === $joe && $nj['foreman'] === 'Joe' && $nj['trip_number'] === 'AS-1', 'a new job assigned to a trip gets the trip and the trip\'s foreman (the chosen Sam is overridden)');
check(str_contains(json_encode($_SESSION['_flash_next']['alert'] ?? []), 'Joe'), '...and the message says the foreman came from the trip');
$tr = $repo->rows($t1);
$mine = array_values(array_filter($tr, static fn (array $x): bool => $x['job_number'] === 'A-NEW'));
check(count($mine) === 1 && $mine[0]['customer_name'] === 'Nia Lee' && $mine[0]['diesel'] === null, 'the job also appears on the trip, with no expenses');
$page = call('GET', "/jobs/{$newId}")->body();
check(str_contains(plain($page), 'Foreman Joe') && str_contains($page, 'href="/trips/' . $t1 . '"') && str_contains(plain($page), 'Trip AS-1'), 'Job Details shows the foreman and the trip (linked)');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'job_created' ORDER BY id DESC LIMIT 1");
check(str_contains($aud, '"trip_id"') && str_contains($aud, '"foreman_id"'), 'creation is audited with the assignment');

$edit = call('GET', "/jobs/{$newId}/edit")->content();
check(preg_match('/<option value="' . $t1 . '" selected>/', $edit) === 1, 'Edit shows the current trip selected');
// move to another trip: old row (with an expense) stays
Database::execute('UPDATE trip_jobs SET diesel = 77.5 WHERE trip_id = ? AND job_id = ?', [$t1, $newId]);
$u = Database::fetchValue('SELECT updated_at FROM jobs WHERE id = ?', [$newId]);
Database::execute('UPDATE jobs SET updated_at = ? WHERE id = ?', ['2026-10-23 09:00:00', $newId]);
call('POST', "/jobs/{$newId}", job_input(['job_number' => 'A-NEW', 'trip_id' => (string) $t2, 'loaded_at' => '2026-10-23 09:00:00', 'customer_first_name' => 'Nia', 'customer_last_name' => 'Lee']));
$nj = (new JobRepository())->find($newId);
check((int) $nj['trip_id'] === $t2 && (int) $nj['foreman_id'] === $sam, 'moving the job to another trip changes its trip and foreman');
check(count(array_filter($repo->rows($t2), static fn (array $x): bool => $x['job_number'] === 'A-NEW')) === 1, '...and adds it to the new trip');
$oldRow = array_values(array_filter($repo->rows($t1), static fn (array $x): bool => $x['job_number'] === 'A-NEW'));
check(count($oldRow) === 1 && n($oldRow[0]['diesel']) === 77.5, 'its row on the old trip, with the expense, is left alone');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'job_updated' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$newId]);
check(str_contains($aud, '"trip_id":"' . $t2 . '"') && str_contains($aud, '"foreman_id":"' . $sam . '"'), 'the move is audited');
// clear the trip, keep a foreman
Database::execute('UPDATE jobs SET updated_at = ? WHERE id = ?', ['2026-10-23 09:00:05', $newId]);
call('POST', "/jobs/{$newId}", job_input(['job_number' => 'A-NEW', 'trip_id' => '', 'foreman_id' => (string) $joe, 'loaded_at' => '2026-10-23 09:00:05', 'customer_first_name' => 'Nia', 'customer_last_name' => 'Lee']));
$nj = (new JobRepository())->find($newId);
check($nj['trip_id'] === null && (int) $nj['foreman_id'] === $joe && count($repo->rows($t2)) === 2, 'with no trip the chosen foreman is used; clearing the trip removes no trip row');
$bad = call('POST', '/jobs', job_input(['job_number' => 'A-BAD', 'trip_id' => '99999']));
check(Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number = 'A-BAD'") == 0 && isset($_SESSION['_flash_next']['errors']['trip_id']), 'an unknown trip on the form is refused with a message');
call('POST', '/jobs', job_input(['job_number' => 'A-BAD2', 'foreman_id' => (string) $old]));
check(Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number = 'A-BAD2'") == 0 && isset($_SESSION['_flash_next']['errors']['foreman_id']), 'an inactive foreman on the form is refused');
call('POST', '/jobs', job_input(['job_number' => 'A-BAD3', 'foreman_id' => '1; DROP TABLE jobs']));
check(Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number = 'A-BAD3'") == 0, 'a non-numeric foreman is refused');

call('POST', '/jobs', job_input(['job_number' => 'A-ONE', 'trip_id' => (string) $t1, 'customer_first_name' => 'Searchable', 'customer_last_name' => 'Person']));
$byTrip = call('GET', '/jobs', [], ['q' => 'AS-1'])->body();
$byForeman = call('GET', '/jobs', [], ['q' => 'Joe'])->body();
check(str_contains($byTrip, 'A-ONE') && str_contains($byForeman, 'A-ONE'), 'the job list search finds a job by its trip number or its foreman');
check(preg_match_all('#class="jt-jobno"[^>]*>A-ONE<#', $byTrip) === 1, 'a job on a trip with several rows still appears once in the list');
$cnt = (int) Database::fetchValue('SELECT COUNT(*) FROM jobs WHERE deleted_at IS NULL');
check(strpos(plain(call('GET', '/jobs')->body()), $cnt . ' job') !== false, 'the list total is unchanged by the join (' . $cnt . ' jobs)');

section('Job assignment: only people who manage trips can change it');
fresh_session();
sign_in($stf);
$sj = mk_job(['job_number' => 'S-ASSIGN']);
Database::execute('UPDATE jobs SET trip_id = ?, foreman_id = ?, updated_at = ? WHERE id = ?', [$t1, $joe, '2026-10-23 10:00:00', $sj]);
$f = call('GET', "/jobs/{$sj}/edit")->content();
check(!str_contains($f, 'name="trip_id"') && !str_contains($f, 'name="foreman_id"') && str_contains(plain($f), 'Joe') && str_contains(plain($f), 'trip AS-1'), 'Staff sees the foreman and trip on the form as text, with no choice');
call('POST', "/jobs/{$sj}", job_input(['job_number' => 'S-ASSIGN', 'trip_id' => (string) $t2, 'foreman_id' => (string) $sam, 'loaded_at' => '2026-10-23 10:00:00']));
$sjr = (new JobRepository())->find($sj);
check((int) $sjr['trip_id'] === $t1 && (int) $sjr['foreman_id'] === $joe, 'a forged trip or foreman in a Staff request is ignored on edit');
call('POST', '/jobs', job_input(['job_number' => 'S-FORGED', 'trip_id' => (string) $t1, 'foreman_id' => (string) $sam]));
$sf = Database::fetchOne("SELECT trip_id, foreman_id FROM jobs WHERE job_number = 'S-FORGED'");
check($sf !== null && $sf['trip_id'] === null && $sf['foreman_id'] === null && count(array_filter($repo->rows($t1), static fn (array $x): bool => $x['job_number'] === 'S-FORGED')) === 0, '...and on create (no assignment, no trip row)');
check(str_contains(plain(call('GET', "/jobs/{$sj}")->body()), 'Foreman Joe'), 'Staff can see the foreman on Job Details');

section('Job assignment: kept in step with the trip pages');
fresh_session();
sign_in($mgr);
$p1 = mk_job(['job_number' => 'P-1']);
$p2 = mk_job(['job_number' => 'P-2']);
$p3 = mk_job(['job_number' => 'P-3']);
$jr = static fn (int $id): array => (array) (new JobRepository())->find($id);
call('POST', '/trips', ['trip_number' => 'SYNC-1', 'foreman_id' => (string) $joe, 'rows' => [['job_number' => 'P-1'], ['job_number' => 'P-2']]]);
$ts = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'SYNC-1'");
check((int) $jr($p1)['trip_id'] === $ts && (int) $jr($p2)['foreman_id'] === $joe && $jr($p1)['trip_number'] === 'SYNC-1', 'a job put on a new trip is assigned to it and to its foreman');
call('POST', "/trips/{$ts}", ['trip_number' => 'SYNC-1', 'foreman_id' => (string) $sam, 'loaded_at' => (string) Database::fetchValue('SELECT updated_at FROM trips WHERE id = ?', [$ts]), 'rows' => [
    ['id' => (string) $repo->rows($ts)[0]['id'], 'job_number' => 'P-1'], ['job_number' => 'P-3']]]);
check((int) $jr($p1)['foreman_id'] === $sam && (int) $jr($p3)['trip_id'] === $ts && (int) $jr($p3)['foreman_id'] === $sam, 'changing the trip\'s foreman moves the jobs that stay on it; a job added is assigned');
check($jr($p2)['trip_id'] === null && (int) $jr($p2)['foreman_id'] === $joe, 'a job taken off the trip loses the trip, and keeps its foreman');
// a job assigned elsewhere is not stolen by a re-save of an older trip
Database::execute('UPDATE jobs SET trip_id = ? WHERE id = ?', [$t1, $p1]);
Database::execute('UPDATE trips SET updated_at = ? WHERE id = ?', ['2026-10-23 11:00:00', $ts]);
call('POST', "/trips/{$ts}", ['trip_number' => 'SYNC-1', 'foreman_id' => (string) $sam, 'loaded_at' => '2026-10-23 11:00:00', 'collected_by' => 'x', 'rows' => [
    ['id' => (string) $repo->rows($ts)[0]['id'], 'job_number' => 'P-1'], ['job_number' => 'P-3']]]);
check((int) $jr($p1)['trip_id'] === $t1, 'saving an older trip does not take a job away from the trip it is now assigned to');
// add from the job page
call('POST', "/jobs/{$p2}/trip", ['trip' => (string) $ts]);
check((int) $jr($p2)['trip_id'] === $ts && (int) $jr($p2)['foreman_id'] === $sam, '"Add to a trip" on a job assigns it to that trip and its foreman');
// delete the trip
call('POST', "/trips/{$ts}/delete");
check($jr($p2)['trip_id'] === null && $jr($p3)['trip_id'] === null && (int) $jr($p3)['foreman_id'] === $sam && $jr($p3)['trip_number'] === null, 'deleting a trip clears the trip on its jobs; they keep their foreman');
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$ts]) === 3, '...and the trip\'s rows are kept');

section('Job assignment: import and export');
fresh_session();
sign_in($mgr);
$tImp = $repo->create($hdr('IMP-T', $sam, '2026-10-08'), [$row($seedJob, 'SEED')], null);
$svc = new ImportService();
$head = ['Job #', 'Customer First Name', 'Customer Last Name', '1st Available Date', 'From', 'To', 'Foreman', 'Trip Number'];
$stage = static function (array $rows) use ($svc, $mgr): array {
    $st = $svc->stage(upload_of(tmp_file(csv_text($rows)), 'a.csv'), (int) $mgr['id']);
    return [$st, Database::fetchOne('SELECT valid_rows, invalid_rows FROM import_batches WHERE id = ?', [$st['batch']])];
};
[$st, $b] = $stage([$head,
    ['IA-1', 'A', 'B', '2026-12-01', 'X', 'Y', 'joe', ''],
    ['IA-2', 'A', 'B', '2026-12-01', 'X', 'Y', '', 'imp-t'],
    ['IA-3', 'A', 'B', '2026-12-01', 'X', 'Y', 'Sam', 'IMP-T'],
    ['IA-4', 'A', 'B', '2026-12-01', 'X', 'Y', 'Nobody', ''],
    ['IA-5', 'A', 'B', '2026-12-01', 'X', 'Y', '', 'NO-SUCH-TRIP'],
    ['IA-6', 'A', 'B', '2026-12-01', 'X', 'Y', 'Joe', 'IMP-T'],
    ['IA-7', 'A', 'B', '2026-12-01', 'X', 'Y', 'Retired', '']]);
check((int) $b['valid_rows'] === 3 && (int) $b['invalid_rows'] === 4, 'import: foreman by name and trip by number are checked (3 good rows, 4 refused)');
$msgs = implode(' | ', array_column(Database::fetchAll('SELECT messages FROM import_errors WHERE batch_id = ?', [$st['batch']]), 'messages'));
check(str_contains($msgs, 'not on the Foremen page') && str_contains($msgs, 'not an existing trip') && str_contains($msgs, 'not the foreman of trip IMP-T') && str_contains($msgs, 'inactive'), 'each problem has its own message');
$svc->commit((int) $st['batch'], (int) $mgr['id']);
$ia = static fn (string $n): array => (array) Database::fetchOne('SELECT * FROM jobs WHERE job_number = ?', [$n]);
check((int) $ia('IA-1')['foreman_id'] === $joe && $ia('IA-1')['trip_id'] === null, 'a foreman by name (any letter case) is assigned');
check((int) $ia('IA-2')['trip_id'] === $tImp && (int) $ia('IA-2')['foreman_id'] === $sam, 'a trip by number assigns the job and takes the trip\'s foreman');
check(count(array_filter($repo->rows($tImp), static fn (array $x): bool => in_array($x['job_number'], ['IA-2', 'IA-3'], true))) === 2, 'imported jobs on a trip appear on that trip');
$csv = (new ExportService())->csv(JobCriteria::forBucket('all'), true)->content();
$lines = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
$hi = array_search('Foreman', $lines[0], true);
$ti = array_search('Trip Number', $lines[0], true);
$ex = array_values(array_filter($lines, static fn (array $l): bool => $l[0] === 'IA-2'));
check($hi !== false && $ti !== false && $ex[0][$hi] === 'Sam' && $ex[0][$ti] === 'IMP-T', 'the export has the foreman and trip number of each job');
fresh_session();
sign_in($stf);
$st = $svc->stage(upload_of(tmp_file(csv_text([$head, ['IA-S', 'A', 'B', '2026-12-01', 'X', 'Y', 'Joe', 'IMP-T']])), 'b.csv'), (int) $stf['id']);
$svc->commit((int) $st['batch'], (int) $stf['id']);
$is = (array) Database::fetchOne("SELECT trip_id, foreman_id FROM jobs WHERE job_number = 'IA-S'");
check($is !== [] && $is['trip_id'] === null && $is['foreman_id'] === null, 'without trips.manage the Foreman and Trip Number columns are ignored (the job is still imported)');
Clock::fake(null);

// ================================================================ phase 3: columns, filters and sorting on the job lists

section('Foreman and trip on the job lists: column, filters, sorting');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$mgr = make_user('Manager', 'l-mgr@example.com');
$fr = new ForemanRepository();
$joe = $fr->create(['name' => 'Joe', 'phone' => null]);
$sam = $fr->create(['name' => 'Sam', 'phone' => null]);
$ret = $fr->create(['name' => 'Retired', 'phone' => null]);
$repo = new TripRepository();
$seed = mk_job(['job_number' => 'L-SEED']);
$mkTrip = static fn (string $n, int $f): int => $repo->create(['trip_number' => $n, 'foreman_id' => $f, 'begins_on' => '2026-10-01', 'ends_on' => null, 'collected_by' => null, 'notes' => null], [[
    'job_id' => $seed, 'job_number' => 'L-SEED', 'customer_name' => 'X', 'job_date' => null, 'from_location' => 'GA', 'bh' => null, 'diesel' => null, 'hotels' => null, 'helper' => null, 'misc' => null, 'commission' => null, 'extra' => null, 'remark' => null]], null);
$tj = $mkTrip('LT-JOE', $joe);
$ts = $mkTrip('LT-SAM', $sam);
$fr->setActive($ret, false);
mk_job(['job_number' => 'L-READY', 'first_available_date' => '2026-11-01', 'foreman_id' => $joe, 'trip_id' => $tj]);
mk_job(['job_number' => 'L-LATER', 'first_available_date' => '2027-06-01', 'foreman_id' => $sam, 'trip_id' => $ts]);
mk_job(['job_number' => 'L-TRANSIT', 'status' => 'in_transit', 'foreman_id' => $joe, 'carrier_name' => 'C', 'driver_name' => 'D', 'driver_phone' => '5550001111']);
mk_job(['job_number' => 'L-DONE', 'status' => 'delivered', 'delivered_at' => '2026-10-10 00:00:00', 'foreman_id' => $ret]);
mk_job(['job_number' => 'L-NONE', 'first_available_date' => '2026-11-02']);
sign_in($mgr);
foreach (['/jobs', '/jobs/ready', '/jobs/more-than-30', '/jobs/in-transit', '/jobs/delivered'] as $path) {
    $h = plain(call('GET', $path)->body());
    check(str_contains($h, 'Foreman / trip'), "{$path} has a Foreman / trip column");
}
$ready = call('GET', '/jobs/ready')->body();
check(str_contains(plain($ready), 'Joe') && preg_match('#<a href="/trips/' . $tj . '">LT-JOE</a>#', $ready) === 1, 'the cell shows the foreman and the trip number, linked to the trip');
check(str_contains(plain(call('GET', '/jobs/in-transit')->body()), 'Joe') && str_contains(plain(call('GET', '/jobs/delivered')->body()), 'Retired'), 'in transit and delivered rows show theirs too (an inactive foreman still shows on an old job)');
$all = call('GET', '/jobs')->body();
check(preg_match('#class="jt-subtle"><a href="/trips/#', $all) === 1 && substr_count($all, '—') >= 1, 'a job with no foreman shows a dash');

$list = static function (array $q, string $path = '/jobs'): array {
    $body = call('GET', $path, [], $q)->body();
    preg_match_all('#class="jt-jobno"[^>]*>(L-[A-Z]+)<#', $body, $m);
    $found = array_values(array_unique($m[1]));
    sort($found);
    return $found;
};
check($list(['foreman' => (string) $joe]) === ['L-READY', 'L-TRANSIT'], 'filter by foreman');
check($list(['foreman' => 'none']) === ['L-NONE', 'L-SEED'], 'filter "No foreman assigned"');
check($list(['foreman' => (string) $ret]) === ['L-DONE'], 'an inactive foreman can still be filtered');
check($list(['trip' => 'LT-SAM']) === ['L-LATER'] && $list(['trip' => 'lt-']) === ['L-LATER', 'L-READY'], 'filter by trip number (part of it, any letter case)');
check($list(['foreman' => (string) $joe, 'trip' => 'LT-JOE']) === ['L-READY'] && $list(['foreman' => (string) $sam, 'trip' => 'LT-JOE']) === [], 'foreman and trip filters combine');
check($list(['foreman' => (string) $joe, 'q' => 'READY']) === ['L-READY'], 'they combine with the search box');
check($list(['foreman' => (string) $joe], '/jobs/ready') === ['L-READY'] && $list(['foreman' => (string) $joe], '/jobs/in-transit') === ['L-TRANSIT'], 'they work inside each section');
check($list(['trip' => '%']) === [] && $list(['trip' => '_']) === [] && $list(['trip' => "' OR 1=1 --"]) === [], 'LIKE wildcards and SQL in the trip filter are plain text');
check($list(['foreman' => 'abc']) !== [] && $list(['foreman' => '-7']) === $list([]) && $list(['foreman' => ['x'], 'trip' => ['y']]) === $list([]) && $list(['foreman' => '999999']) === [], 'a malformed foreman is ignored (not an error); an unknown one finds nothing');
check($list(['foreman' => (string) $joe, 'status' => 'delivered']) === [], 'with the status filter');

$body = call('GET', '/jobs', [], ['foreman' => (string) $joe, 'trip' => 'LT'])->body();
check(str_contains($body, '<option value="' . $joe . '" selected>') && str_contains($body, 'name="trip" value="LT"') && preg_match('#<details[^>]*open#', $body) === 1, 'the filter form shows the chosen values and opens the "More filters" panel');
check(str_contains($body, 'foreman=' . $joe) && str_contains($body, 'trip=LT'), 'sort links keep the filters');
check(str_contains(call('GET', '/jobs')->body(), 'No foreman assigned') && str_contains(call('GET', '/jobs')->body(), '>Retired (inactive)<'), 'the Foreman filter offers none, every foreman, and marks inactive ones');
$srt = static function (string $dir) use ($list): array {
    $body = call('GET', '/jobs', [], ['sort' => 'assign', 'dir' => $dir])->body();
    preg_match_all('#class="jt-jobno"[^>]*>(L-[A-Z]+)<#', $body, $m);
    return array_values(array_unique($m[1]));
};
$asc = $srt('asc');
$desc = $srt('desc');
check($asc[0] !== $desc[0] && array_search('L-READY', $asc, true) < array_search('L-LATER', $asc, true) && array_search('L-LATER', $desc, true) < array_search('L-READY', $desc, true), 'sorting by Foreman / trip works both ways (Joe before Sam)');
check(call('GET', '/jobs', [], ['sort' => 'foreman'])->status() === 200 && call('GET', '/jobs', [], ['sort' => 'trip', 'dir' => 'desc'])->status() === 200, 'the foreman and trip sort keys work too');

// export follows the same filters
$csv = call('GET', '/jobs/export/all', [], ['foreman' => (string) $joe, 'format' => 'csv'])->content();
$lines = array_filter(explode("\n", substr($csv, 3)));
check(count($lines) === 3 && str_contains($csv, 'L-READY') && str_contains($csv, 'L-TRANSIT') && !str_contains($csv, 'L-LATER'), 'the export follows the foreman filter');
check(count(array_filter(explode("\n", substr(call('GET', '/jobs/export/all', [], ['trip' => 'LT-SAM', 'format' => 'csv'])->content(), 3)))) === 2, '...and the trip filter');
// counts are right
check(strpos(plain(call('GET', '/jobs', [], ['foreman' => (string) $joe])->body()), '2 jobs') !== false, 'the result count matches the filter');

// people who cannot see trips get the trip number as plain text
fresh_session();
make_role('Jobs only', ['dashboard.view', 'jobs.view']);
sign_in(make_user('Jobs only', 'jo@example.com'));
$jo = call('GET', '/jobs/ready')->body();
check(str_contains($jo, 'LT-JOE') && !str_contains($jo, 'href="/trips/') && str_contains(plain($jo), 'Joe'), 'without trips.view the foreman and the trip number are shown as text, with no link');
check($list(['foreman' => (string) $joe]) === ['L-READY', 'L-TRANSIT'], 'and they can filter by foreman');
Clock::fake(null);
