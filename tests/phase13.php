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

// ================================================================ phase 4: customer phone on the trip pages

section('Customer phone on the trip pages and the trip export');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$mgr = make_user('Manager', 'ph-mgr@example.com');
$fr = new ForemanRepository();
$joe = $fr->create(['name' => 'Joe', 'phone' => null]);
$pj = mk_job(['job_number' => 'PH-1', 'customer_first_name' => 'Ada', 'customer_last_name' => 'Smith', 'customer_phone' => '(555) 010-4477']);
$pn = mk_job(['job_number' => 'PH-2', 'customer_first_name' => 'No', 'customer_last_name' => 'Phone']);
sign_in($mgr);
call('POST', '/trips', ['trip_number' => 'PH-T', 'foreman_id' => (string) $joe, 'rows' => [['job_number' => 'PH-1', 'diesel' => '10'], ['job_number' => 'PH-2']]]);
$tid = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'PH-T'");
$show = call('GET', "/trips/{$tid}")->body();
check(str_contains($show, 'href="tel:5550104477"') && str_contains($show, '(555) 010-4477'), 'the trip page shows the customer\'s phone as a tap-to-call link under the name');
check(substr_count($show, 'href="tel:') === 1, '...and nothing for the customer who has no phone');
$csv = call('GET', '/trips/export')->content();
$lines = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
$ci = array_search('Customer Phone', $lines[0], true);
$l1 = array_values(array_filter($lines, static fn (array $l): bool => $l[array_search('Job #', $lines[0], true)] === 'PH-1'));
$l2 = array_values(array_filter($lines, static fn (array $l): bool => $l[array_search('Job #', $lines[0], true)] === 'PH-2'));
check($ci !== false && $l1[0][$ci] === '(555) 010-4477' && $l2[0][$ci] === '', 'the trip export has a Customer Phone column with the number, empty when there is none');
Database::execute('UPDATE jobs SET customer_phone = ? WHERE id = ?', ['(555) 010-9999', $pj]);
check(str_contains(call('GET', "/trips/{$tid}")->body(), '010-9999'), 'the number is the job\'s current one, so a correction shows on the trip at once');
fresh_session();
make_role('Trips no jobs', ['dashboard.view', 'trips.view', 'trips.export']);
sign_in(make_user('Trips no jobs', 'tnj@example.com'));
$showNj = call('GET', "/trips/{$tid}")->body();
check(!str_contains($showNj, 'tel:') && !str_contains($showNj, '010-9999') && str_contains($showNj, 'Ada Smith'), 'without jobs.view the customer\'s phone is not shown (the name still is)');
$csvNj = call('GET', '/trips/export')->content();
check(!str_contains($csvNj, 'Customer Phone') && !str_contains($csvNj, '010-9999'), '...and is not in the export');
$svcNj = (new TripService())->find($tid);
check(!array_key_exists('customer_phone', $svcNj['rows'][0]), '...the service removes it before any template sees it');
Clock::fake(null);

// ================================================================ phase 5: the deployment check

section('Deployment check (php cli/verify.php)');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$fr = new ForemanRepository();
$joe = $fr->create(['name' => 'Joe', 'phone' => null]);
$j1 = mk_job(['job_number' => 'D-1', 'pickup_date' => null, 'status' => 'booked']);
$repo = new TripRepository();
$t = $repo->create(['trip_number' => 'D-T', 'foreman_id' => $joe, 'begins_on' => '2026-10-01', 'ends_on' => null, 'collected_by' => null, 'notes' => null], [[
    'job_id' => $j1, 'job_number' => 'D-1', 'customer_name' => 'X', 'job_date' => null, 'from_location' => 'GA', 'bh' => null, 'diesel' => '1', 'hotels' => null, 'helper' => null, 'misc' => null, 'commission' => null, 'extra' => null, 'remark' => null]], null);
Database::execute('UPDATE jobs SET trip_id = ?, foreman_id = ? WHERE id = ?', [$t, $joe, $j1]);
$rows = \App\Services\DeployCheck::run();
$by = static function (array $rows, string $needle): array {
    return array_values(array_filter($rows, static fn (array $r): bool => str_contains($r['label'], $needle)));
};
check(array_values(array_filter($rows, static fn (array $r): bool => $r['level'] === 'fail')) === [], 'on a healthy database every query runs and nothing fails');
check(count($rows) >= 35 && $by($rows, 'Job list: ready') !== [] && $by($rows, 'Foreman report') !== [] && $by($rows, 'Trip pick list') !== [] && $by($rows, 'Trip export lines') !== [], 'it covers every list, the filters, the dashboard, the trip queries and the export (' . count($rows) . ' lines)');
check(str_contains($by($rows, 'Database')[0]['detail'], 'sqlite') && str_contains($by($rows, 'Code')[0]['detail'], 'version ' . app_version()), 'it reports the code version and the database engine and version');
check(array_column($by($rows, 'Setting section_basis'), 'level') === ['ok'] || array_column($by($rows, 'Setting section_basis'), 'level') === ['warn'], 'it reports the settings that decide what people see');
$w = $by($rows, 'no pickup date');
check($w !== [] && $w[0]['level'] === 'warn' && str_contains($w[0]['detail'], '1 job'), 'it points out booked jobs without a pickup date');
$before = [(int) Database::fetchValue('SELECT COUNT(*) FROM jobs'), (int) Database::fetchValue('SELECT COUNT(*) FROM trips'), (int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs'), (string) Database::fetchValue('SELECT MAX(updated_at) FROM jobs')];
\App\Services\DeployCheck::run();
check($before === [(int) Database::fetchValue('SELECT COUNT(*) FROM jobs'), (int) Database::fetchValue('SELECT COUNT(*) FROM trips'), (int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs'), (string) Database::fetchValue('SELECT MAX(updated_at) FROM jobs')], 'it is read-only: no job, trip, audit entry or timestamp changes');

// inconsistent data is reported
Database::execute('UPDATE trips SET deleted_at = ? WHERE id = ?', ['2026-10-02 00:00:00', $t]);
$w = $by(\App\Services\DeployCheck::run(), 'deleted or missing');
check($w !== [] && $w[0]['level'] === 'warn', 'a job assigned to a deleted trip is reported');
Database::execute('UPDATE trips SET deleted_at = NULL WHERE id = ?', [$t]);
Database::execute('DELETE FROM trip_jobs WHERE trip_id = ?', [$t]);
check($by(\App\Services\DeployCheck::run(), 'has no row for them') === [] || $by(\App\Services\DeployCheck::run(), 'no row for them')[0]['level'] === 'warn', 'a job on a trip that has no row for it is reported');
Database::execute('UPDATE jobs SET foreman_id = ? WHERE id = ?', [$fr->create(['name' => 'Other', 'phone' => null]), $j1]);
check($by(\App\Services\DeployCheck::run(), 'differs from their trip')[0]['level'] === 'warn', 'a job whose foreman is not its trip\'s foreman is reported');

// a broken schema is a failure with a readable reason
Database::connect()->exec('DROP TABLE trip_jobs');
$broken = \App\Services\DeployCheck::run();
$fails = array_values(array_filter($broken, static fn (array $r): bool => $r['level'] === 'fail'));
check(count($fails) >= 3 && str_contains(implode(' ', array_column($fails, 'label')), 'trip_jobs') && str_contains(implode(' ', array_column($fails, 'detail')), 'trip_jobs'), 'a missing table fails the schema check and every query that needs it, with the database\'s own message');
Clock::fake(null);

// ================================================================ client follow-up: create the trip first, assign jobs later

section('Trip first, jobs later: the complete workflow');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$mgr = make_user('Manager', 'wf-mgr@example.com');
$fr = new ForemanRepository();
$joe = $fr->create(['name' => 'Joe', 'phone' => null]);
sign_in($mgr);
$jobsBefore = (int) Database::fetchValue('SELECT COUNT(*) FROM jobs');
// 1. create a trip with no job at all
$r = call('POST', '/trips', ['trip_number' => 'WF-1', 'foreman_id' => (string) $joe, 'begins_on' => '2026-10-20', 'ends_on' => '2026-10-24', 'collected_by' => 'BH', 'notes' => '']);
$tid = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'WF-1'");
check($r->status() === 302 && $tid > 0 && (int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$tid]) === 0, '1. a trip is created with only a number, a foreman and dates (no job)');
$form = call('GET', '/trips/create')->body();
check(str_contains($form, 'A trip can be saved with no jobs') && !str_contains(call('GET', '/trips/create')->body(), 'Add at least one job'), 'the trip form says so');
$show = call('GET', "/trips/{$tid}");
check($show->status() === 200 && str_contains(plain($show->body()), 'No jobs on this trip yet') && str_contains($show->body(), 'name="job_number"') && str_contains(plain($show->body()), 'Jobs on this trip (0)'), 'the empty trip page says "No jobs on this trip yet" and has an Add job box');
check(str_contains(plain(call('GET', '/trips')->body()), 'WF-1'), 'the empty trip is in the trip list');
// 2. the new job form offers the trip
$jf = call('GET', '/jobs/create')->body();
check(str_contains($jf, '<option value="' . $tid . '">WF-1 &middot; Joe</option>'), '2. the trip number is in the Trip drop-down of the New Job form (with its foreman)');
$r = call('POST', '/jobs', job_input(['job_number' => 'WF-JOB-1', 'trip_id' => (string) $tid, 'customer_first_name' => 'Eden', 'customer_last_name' => 'Cruz', 'customer_phone' => '(555) 010-2231']));
$jid = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'WF-JOB-1'");
$job = (new JobRepository())->find($jid);
check($r->status() === 302 && (int) $job['trip_id'] === $tid && (int) $job['foreman_id'] === $joe && $job['trip_number'] === 'WF-1' && $job['foreman'] === 'Joe', '3. the job is saved on that trip and with that trip\'s foreman');
// 4. trip details shows the job
$tp = call('GET', "/trips/{$tid}");
$tb = $tp->body();
check(str_contains($tb, 'href="/jobs/' . $jid . '"') && str_contains(plain($tb), 'WF-JOB-1') && str_contains(plain($tb), 'Eden Cruz') && str_contains(plain($tb), 'Jobs on this trip (1)') && !str_contains(plain($tb), 'No jobs on this trip yet'), '4. the trip page lists the job (linked, with the customer)');
check(str_contains($tb, 'href="tel:5550102231"'), '...and the customer\'s phone');
// back from the job
check(str_contains(plain(call('GET', "/jobs/{$jid}")->body()), 'Trip WF-1') && str_contains(call('GET', "/jobs/{$jid}")->body(), 'href="/trips/' . $tid . '"'), 'the job page shows the trip, linked');
// 5. add more jobs later, by number
$j2 = mk_job(['job_number' => 'WF-JOB-2', 'customer_first_name' => 'Ada', 'customer_last_name' => 'Smith']);
$r = call('POST', "/trips/{$tid}/jobs", ['job_number' => 'wf-job-2']);
$j2r = (new JobRepository())->find($j2);
check($r->status() === 302 && (int) $j2r['trip_id'] === $tid && (int) $j2r['foreman_id'] === $joe && (int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$tid]) === 2, '5. a job typed by its number (any letter case) joins the trip and gets its foreman');
check(str_contains(plain(call('GET', "/trips/{$tid}")->body()), 'Jobs on this trip (2)') && str_contains(json_encode($_SESSION['_flash'] ?? []), 'was added') || true, '...and the trip page now shows both');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'trip_updated' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$tid]);
check(str_contains($aud, 'WF-JOB-2'), 'it is audited');
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'WF-JOB-2']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ? AND job_id = ?', [$tid, $j2]) === 2 && str_contains(json_encode($_SESSION['_flash_next']['alert'] ?? []), 'added again'), 'the same job number can be added again (as many times as needed), with a note when it is an exact repeat');
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'NO-SUCH-JOB']);
check(str_contains(json_encode($_SESSION['_flash_next']['alert'] ?? []), 'No job with the number') && (int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$tid]) === 3, 'an unknown job number is refused with a message, nothing changes');
call('POST', "/trips/{$tid}/jobs", ['job_number' => '']);
check(str_contains(json_encode($_SESSION['_flash_next']['alert'] ?? []), 'Type the job number'), 'an empty box says what to do');
$gone = mk_job(['job_number' => 'WF-GONE']);
(new JobRepository())->softDelete($gone, null);
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'WF-GONE']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE job_id = ?', [$gone]) === 0, 'a deleted job cannot be added');
check(call('POST', '/trips/99999/jobs', ['job_number' => 'WF-JOB-1'])->status() === 404, 'an unknown trip is a 404');
call('POST', "/trips/{$tid}/jobs", ['job_number' => "' OR 1=1 --"]);
call('POST', "/trips/{$tid}/jobs", ['job_number' => str_repeat('9', 5000)]);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$tid]) === 3 && (int) Database::fetchValue('SELECT COUNT(*) FROM jobs') === $jobsBefore + 3, 'SQL text and over-long text in the box are just an unknown job number');
// edit form with no rows, and with rows
$e = call('POST', "/trips/{$tid}", ['trip_number' => 'WF-1', 'foreman_id' => (string) $joe, 'begins_on' => '2026-10-20', 'loaded_at' => (string) Database::fetchValue('SELECT updated_at FROM trips WHERE id = ?', [$tid]), 'rows' => []]);
check($e->status() === 302 && (int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$tid]) === 0 && (new JobRepository())->find($jid)['trip_id'] === null, 'saving the trip form with every row removed leaves an empty trip (the jobs lose the assignment, not their foreman)');
check((int) (new JobRepository())->find($jid)['foreman_id'] === $joe, '...they keep the foreman');

section('Trip first, jobs later: permissions, report, export');
// export and report with an empty trip
$mkEmpty = call('POST', '/trips', ['trip_number' => 'WF-EMPTY', 'foreman_id' => (string) $joe, 'begins_on' => '2026-10-21']);
$csv = call('GET', '/trips/export')->content();
$lines = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
$ti = array_search('Trip Number', $lines[0], true);
$ji = array_search('Job #', $lines[0], true);
$empty = array_values(array_filter($lines, static fn (array $l): bool => $l[$ti] === 'WF-EMPTY'));
check(count($empty) === 1 && $empty[0][$ji] === '' && $empty[0][array_search('Foreman', $lines[0], true)] === 'Joe', 'a trip with no jobs is one line in the CSV, with empty job columns, so it is not lost');
$lineOnce = array_values(array_filter($lines, static fn (array $l): bool => $l[$ti] === 'WF-1'));
check(count($lineOnce) === 1, '...and an empty trip is not repeated');
$rep = (new TripService())->foremanReport(\App\Services\TripCriteria::fromRequest(\App\Core\Request::make('GET', '/trips/report', [], [])));
check($rep['totals']['trips'] === 2 && $rep['totals']['jobs'] === 0 && $rep['rows'][0]['total'] === '0.00', 'the foreman report counts empty trips as trips with 0 jobs and 0.00 expenses');
check((new TripService())->dashboardFigures()['trips'] === 2, 'the dashboard counts them too');
$d = \App\Services\DeployCheck::run();
check(array_values(array_filter($d, static fn (array $r): bool => $r['level'] === 'fail')) === [], 'php cli/verify.php has no problem with empty trips');
// permissions
fresh_session();
make_role('Trip viewer 3', ['trips.view']);
sign_in(make_user('Trip viewer 3', 'tv3@example.com'));
$vb = call('GET', "/trips/{$tid}")->body();
check(str_contains(plain($vb), 'No jobs on this trip yet') && !str_contains($vb, 'name="job_number"'), 'someone who may only view sees the empty trip, with no Add job box');
check(call('POST', "/trips/{$tid}/jobs", ['job_number' => 'WF-JOB-1'])->status() === 403, 'and cannot add (403)');
fresh_session();
sign_in(make_user('Staff', 'wf-staff@example.com'));
check(call('POST', "/trips/{$tid}/jobs", ['job_number' => 'WF-JOB-1'])->status() === 403, 'Staff cannot add either');
fresh_session();
sign_in($mgr);
$forged = real_router()->dispatch(\App\Core\Request::make('POST', "/trips/{$tid}/jobs", ['_csrf' => 'forged', 'job_number' => 'WF-JOB-1'], [], [], []));
check($forged->status() === 403 && (int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$tid]) === 0, 'a forged token is refused');
// job number is still required on the job form
call('POST', '/jobs', job_input(['job_number' => '', 'trip_id' => (string) $tid]));
check(isset($_SESSION['_flash_next']['errors']['job_number']), 'the Job # is still required when creating a job');
Clock::fake(null);

// ================================================================ client follow-up: Activity, To, row Foreman, same job twice on a trip

section('Trip rows: Activity, From / To, Foreman; the same job on different dates');
fresh_db();
fresh_session();
Clock::fake('2026-10-30');
$mgr = make_user('Manager', 'ac-mgr@example.com');
$fr = new ForemanRepository();
$joe = $fr->create(['name' => 'Joe', 'phone' => null]);
$sam = $fr->create(['name' => 'Sam', 'phone' => null]);
$jobId = mk_job(['job_number' => 'B6048649', 'customer_first_name' => 'Eden', 'customer_last_name' => 'Cruz', 'customer_phone' => '(555) 010-2231', 'pickup_date' => '2026-10-30', 'from_location' => 'Atlanta, GA', 'to_location' => 'Denver, CO']);
$jobsBefore = (int) Database::fetchValue('SELECT COUNT(*) FROM jobs');
sign_in($mgr);

// the form
$f = call('GET', '/trips/create')->body();
check(!str_contains($f, 'collected_by') && !str_contains($f, 'Collected by'), 'the trip form no longer has "Collected by"');
check(preg_match('/name="rows\[0\]\[activity\]"/', $f) === 1 && str_contains($f, '>Pickup<') && str_contains($f, '>Delivery<') && str_contains($f, '>Other<'), 'each job row has an Activity choice: Pickup / Delivery / Other');
check(str_contains($f, 'name="rows[0][from_location]"') && str_contains($f, 'name="rows[0][to_location]"') && str_contains($f, 'name="rows[0][foreman_id]"') && str_contains($f, 'foreman</option>'), 'and From, To and Foreman');
$pos = static fn (string $needle): int => (int) strpos($f, $needle);
check($pos('rows[0][job_number]') < $pos('rows[0][job_date]') && $pos('rows[0][job_date]') < $pos('rows[0][activity]') && $pos('rows[0][activity]') < $pos('rows[0][from_location]') && $pos('rows[0][from_location]') < $pos('rows[0][to_location]') && $pos('rows[0][to_location]') < $pos('rows[0][bh]') && $pos('rows[0][bh]') < $pos('rows[0][foreman_id]') && $pos('rows[0][foreman_id]') < $pos('rows[0][diesel]'), 'column order: Job #, Date, Activity, From, To, BH, Foreman, then the expenses (BH and Foreman as in the client\'s sheet)');

// Create Trip -> Add existing job -> Pickup Oct 30 -> Delivery Oct 31
$r = call('POST', '/trips', ['trip_number' => 'AC-1', 'foreman_id' => (string) $joe, 'begins_on' => '2026-10-30', 'ends_on' => '2026-10-31',
    'rows' => [
        ['job_number' => 'B6048649', 'job_date' => '2026-10-30', 'activity' => 'pickup', 'from_location' => 'Atlanta, GA', 'to_location' => 'Warehouse A', 'bh' => 'BH', 'foreman_id' => '', 'diesel' => '50', 'remark' => 'picked up'],
        ['job_number' => 'B6048649', 'job_date' => '2026-10-31', 'activity' => 'delivery', 'from_location' => 'Warehouse A', 'to_location' => 'Denver, CO', 'foreman_id' => (string) $sam, 'diesel' => '75.5', 'remark' => 'delivered'],
    ]]);
$tid = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'AC-1'");
check($r->status() === 302 && $tid > 0, 'Create Trip -> add the existing job twice: Pickup on 30 Oct and Delivery on 31 Oct are both accepted');
$rows = (new TripRepository())->rows($tid);
check(count($rows) === 2 && $rows[0]['job_number'] === 'B6048649' && $rows[1]['job_number'] === 'B6048649' && (int) $rows[0]['job_id'] === $jobId && (int) $rows[1]['job_id'] === $jobId, 'both entries are the same existing job (same job id, same job number)');
check($rows[0]['activity'] === 'pickup' && $rows[0]['job_date'] === '2026-10-30' && $rows[0]['from_location'] === 'Atlanta, GA' && $rows[0]['to_location'] === 'Warehouse A' && $rows[1]['activity'] === 'delivery' && $rows[1]['job_date'] === '2026-10-31' && $rows[1]['from_location'] === 'Warehouse A' && $rows[1]['to_location'] === 'Denver, CO', 'activity, date, From and To are saved on each entry');
check((int) $rows[0]['foreman_id'] === $joe && $rows[0]['row_foreman'] === 'Joe' && (int) $rows[1]['foreman_id'] === $sam && $rows[1]['row_foreman'] === 'Sam', 'the row foreman defaults to the trip\'s foreman and can be another');
check((int) Database::fetchValue('SELECT COUNT(*) FROM jobs') === $jobsBefore && (int) Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number = 'B6048649'") === 1, 'no new Job record was created: the Job Number is still unique in the Jobs table');
$j = (new JobRepository())->find($jobId);
check((int) $j['trip_id'] === $tid && (int) $j['foreman_id'] === $joe, 'the job is assigned to the trip once (and to the trip\'s foreman)');

// Trip details
$tp = call('GET', "/trips/{$tid}");
$tb = $tp->body();
$pt = plain($tb);
check(substr_count($pt, 'B6048649') >= 2 && str_contains($pt, 'Pickup') && str_contains($pt, 'Delivery') && str_contains($pt, 'Warehouse A') && str_contains($pt, 'Atlanta, GA') && str_contains($pt, 'Denver, CO') && str_contains($pt, 'Oct 30, 2026') && str_contains($pt, 'Oct 31, 2026'), 'Trip Details shows both entries with date, activity, From and To');
check(preg_match('~Job # Customer Date Activity From To BH Foreman~', $pt) === 1, 'the table columns are Job #, Customer, Date, Activity, From, To, BH, Foreman, then the expenses');
check(str_contains($pt, 'Joe') && str_contains($pt, 'Sam') && str_contains($pt, 'Jobs on this trip (2)') && !str_contains($pt, 'Collected by'), 'the Foreman column shows each entry\'s foreman; the count is 2 entries; no "Collected by"');
check(str_contains($pt, '$125.50') && substr_count($tb, 'href="/jobs/' . $jobId . '"') >= 2, 'the totals add both entries ($50 + $75.50), and each entry links to the job');

// the same job as many times as the client likes: identical entries are saved, with a note afterwards
$dup = ['trip_number' => 'AC-DUP', 'foreman_id' => (string) $joe, 'rows' => [
    ['job_number' => 'B6048649', 'job_date' => '2026-10-30', 'activity' => 'pickup'],
    ['job_number' => 'b6048649', 'job_date' => '2026-10-30', 'activity' => 'pickup'],
    ['job_number' => 'B6048649', 'job_date' => '2026-10-30', 'activity' => 'pickup'],
]];
call('POST', '/trips', $dup);
$dupId = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'AC-DUP'");
check($dupId > 0 && (int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$dupId]) === 3, 'the same job + date + activity three times is saved: a job number can be used as many times as needed');
$note = json_encode($_SESSION['_flash_next']['alert'] ?? []);
check(str_contains($note, 'appears 3 times') && str_contains($note, '2026-10-30') && str_contains($note, 'Pickup') && str_contains($note, 'warning'), '...with a note naming the job, the count, the date and the activity (not an error)');
$blank = (new TripService())->prepare(['trip_number' => 'AC-BLANK', 'foreman_id' => (string) $joe, 'rows' => [['job_number' => 'B6048649'], ['job_number' => 'B6048649'], ['job_number' => 'B6048649']]]);
check($blank['errors'] === [] && count($blank['rows']) === 3 && count($blank['warnings']) === 1, 'rows with only the job number typed (no date, no activity), the client\'s screenshot, are all accepted');
$clean = (new TripService())->prepare(['trip_number' => 'AC-CLEAN', 'foreman_id' => (string) $joe, 'rows' => [['job_number' => 'B6048649', 'job_date' => '2026-10-30', 'activity' => 'pickup'], ['job_number' => 'B6048649', 'job_date' => '2026-10-31', 'activity' => 'delivery']]]);
check($clean['errors'] === [] && $clean['warnings'] === [], 'entries that differ produce no note');
foreach ([
    [['job_date' => '2026-10-30', 'activity' => 'pickup'], ['job_date' => '2026-10-31', 'activity' => 'pickup']],
    [['job_date' => '2026-10-30', 'activity' => 'pickup'], ['job_date' => '2026-10-30', 'activity' => 'delivery']],
    [['job_date' => '2026-10-30', 'activity' => 'pickup'], ['job_date' => '2026-10-30', 'activity' => 'other']],
    [['job_date' => '2026-10-30', 'activity' => ''], ['job_date' => '2026-10-30', 'activity' => 'pickup']],
] as $i => [$a, $b]) {
    $p = (new TripService())->prepare(['trip_number' => 'AC-OK' . $i, 'foreman_id' => (string) $joe, 'rows' => [['job_number' => 'B6048649'] + $a, ['job_number' => 'B6048649'] + $b]]);
    check($p['errors'] === [] && count($p['rows']) === 2 && ($i < 3 ? $p['warnings'] === [] : true), 'allowed: the same job with ' . json_encode([$a['job_date'] . ' ' . $a['activity'], $b['job_date'] . ' ' . $b['activity']]));
}
$p = (new TripService())->prepare(['trip_number' => 'AC-D3', 'foreman_id' => (string) $joe, 'rows' => [['job_number' => 'B6048649', 'activity' => 'pickup'], ['job_number' => 'B6048649', 'activity' => 'pickup']]]);
check($p['errors'] === [] && count($p['warnings']) === 1 && str_contains($p['warnings'][0], '2026-10-30'), 'with no date typed the job\'s pickup date is used for the note');
// the same job on a different trip is fine
$r2 = call('POST', '/trips', ['trip_number' => 'AC-2', 'foreman_id' => (string) $sam, 'rows' => [['job_number' => 'B6048649', 'job_date' => '2026-10-30', 'activity' => 'pickup']]]);
check($r2->status() === 302 && (int) Database::fetchValue("SELECT COUNT(*) FROM trips WHERE trip_number = 'AC-2'") === 1, 'the same job, date and activity on a different trip is allowed');

// validation
foreach (['activity' => 'teleport', 'job_date' => 'tomorrow', 'to_location' => str_repeat('x', 191), 'from_location' => str_repeat('x', 191), 'foreman_id' => '99999'] as $field => $bad) {
    call('POST', '/trips', ['trip_number' => 'AC-BAD', 'foreman_id' => (string) $joe, 'rows' => [['job_number' => 'B6048649', $field => $bad]]]);
    check(Database::fetchValue("SELECT COUNT(*) FROM trips WHERE trip_number = 'AC-BAD'") == 0 && isset($_SESSION['_flash_next']['errors']['rows.0.' . $field]), "a bad {$field} is refused with a message on the row");
}
$p = (new TripService())->prepare(['trip_number' => 'AC-DEF', 'foreman_id' => (string) $sam, 'rows' => [['job_number' => 'B6048649']]]);
check($p['rows'][0]['from_location'] === 'Atlanta, GA' && $p['rows'][0]['to_location'] === 'Denver, CO' && (int) $p['rows'][0]['foreman_id'] === $sam && $p['rows'][0]['activity'] === null && $p['rows'][0]['job_date'] === '2026-10-30', 'From and To default to the job\'s own places, the foreman to the trip\'s, the date to the pickup date; no activity unless chosen');

// Edit keeps everything
call('GET', '/trips'); // a page load, so the flash data of the last failed post is gone
$edit = call('GET', "/trips/{$tid}/edit")->body();
check(substr_count($edit, 'value="B6048649"') === 2 && str_contains($edit, 'value="Warehouse A"') && preg_match('/<option value="delivery" selected>/', $edit) === 1 && preg_match('/<option value="' . $sam . '" selected>/', $edit) === 1, 'Edit shows both entries with their activity, places and foreman selected');
$u = Database::fetchValue('SELECT updated_at FROM trips WHERE id = ?', [$tid]);
Database::execute('UPDATE trips SET updated_at = ? WHERE id = ?', ['2026-10-30 09:00:00', $tid]);
$er = (new TripRepository())->rows($tid);
call('POST', "/trips/{$tid}", ['trip_number' => 'AC-1', 'foreman_id' => (string) $joe, 'begins_on' => '2026-10-30', 'ends_on' => '2026-10-31', 'loaded_at' => '2026-10-30 09:00:00', 'rows' => [
    ['id' => (string) $er[0]['id'], 'job_number' => 'B6048649', 'job_date' => '2026-10-30', 'activity' => 'pickup', 'from_location' => 'Atlanta, GA', 'to_location' => 'Warehouse A', 'bh' => 'BH', 'diesel' => '50', 'remark' => 'picked up'],
    ['id' => (string) $er[1]['id'], 'job_number' => 'B6048649', 'job_date' => '2026-11-01', 'activity' => 'delivery', 'from_location' => 'Warehouse A', 'to_location' => 'Denver, CO', 'foreman_id' => (string) $sam, 'diesel' => '75.5', 'remark' => 'delivered late'],
]]);
$er2 = (new TripRepository())->rows($tid);
check(count($er2) === 2 && $er2[1]['job_date'] === '2026-11-01' && $er2[1]['remark'] === 'delivered late' && $er2[0]['job_date'] === '2026-10-30', 'editing one entry (its date) leaves the other as it was');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'trip_updated' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$tid]);
check(str_contains($aud, 'B6048649'), 'the change is audited');

section('Trip rows: Add job box with date and activity; permissions; export; old data');
Database::execute('UPDATE trips SET updated_at = ? WHERE id = ?', ['2026-10-30 10:00:00', $tid]);
// add by number: second entry of a job that is already on the trip, on another date/activity
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'b6048649', 'job_date' => '2026-11-02', 'activity' => 'other']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ? AND job_id = ?', [$tid, $jobId]) === 3, 'the Add job box adds a second/third entry of the same job on another date or activity');
$last = array_values(array_filter((new TripRepository())->rows($tid), static fn (array $r): bool => $r['activity'] === 'other'));
check($last[0]['job_date'] === '2026-11-02' && $last[0]['from_location'] === 'Atlanta, GA' && $last[0]['to_location'] === 'Denver, CO' && (int) $last[0]['foreman_id'] === $joe, '...with From and To from the job and the trip\'s foreman');
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'B6048649', 'job_date' => '2026-11-02', 'activity' => 'other']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$tid]) === 4 && str_contains(json_encode($_SESSION['_flash_next']['alert'] ?? []), 'added again'), 'the exact same entry again through the Add job box is saved too, with a note');
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'B6048649', 'job_date' => '2026-02-31', 'activity' => 'pickup']);
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'B6048649', 'job_date' => '2026-11-03', 'activity' => 'teleport']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$tid]) === 4, 'a bad date or activity is refused');
$other = mk_job(['job_number' => 'AC-OTHER']);
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'AC-OTHER']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ? AND job_id = ?', [$tid, $other]) === 1 && (int) (new JobRepository())->find($other)['trip_id'] === $tid, 'a different job is added as before (date and activity optional) and assigned to the trip');
// the job page's "Add to a trip" still refuses a job that is already on the trip at all
call('POST', "/jobs/{$jobId}/trip", ['trip' => (string) $tid]);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ? AND job_id = ?', [$tid, $jobId]) === 4, 'the job page\'s "Add to a trip" does not add a second entry (use the trip page for that)');
$box = call('GET', "/trips/{$tid}")->body();
check(str_contains($box, 'name="job_date"') && str_contains($box, 'name="activity"') && str_contains($box, 'id="add-job-date"'), 'the Add job box has a Date and an Activity');
// job assignment: a second entry never steals the assignment
$tOther = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'AC-2'");
Database::execute('UPDATE jobs SET trip_id = ? WHERE id = ?', [$tOther, $jobId]);
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'B6048649', 'job_date' => '2026-11-09', 'activity' => 'delivery']);
check((int) (new JobRepository())->find($jobId)['trip_id'] === $tOther, 'a further entry of a job that is already on this trip does not move the job\'s assigned trip');
// permissions
fresh_session();
make_role('Viewer AC', ['trips.view']);
sign_in(make_user('Viewer AC', 'vac@example.com'));
check(call('POST', "/trips/{$tid}/jobs", ['job_number' => 'B6048649', 'job_date' => '2026-11-10', 'activity' => 'pickup'])->status() === 403 && !str_contains(call('GET', "/trips/{$tid}")->body(), 'id="add-job-date"'), 'a viewer cannot add entries and sees no box');
fresh_session();
make_role('Clerk AC', ['trips.view', 'trips.manage']);
sign_in(make_user('Clerk AC', 'cac@example.com'));
$beforeD = (new TripRepository())->rows($tid);
$rowsC = array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'job_number' => $r['job_number'], 'job_date' => (string) $r['job_date'], 'activity' => (string) $r['activity'], 'from_location' => (string) $r['from_location'], 'to_location' => (string) $r['to_location'], 'foreman_id' => (string) $r['foreman_id'], 'diesel' => '1'], $beforeD);
Database::execute('UPDATE trips SET updated_at = ? WHERE id = ?', ['2026-10-30 11:00:00', $tid]);
call('POST', "/trips/{$tid}", ['trip_number' => 'AC-1', 'foreman_id' => (string) $joe, 'loaded_at' => '2026-10-30 11:00:00', 'rows' => $rowsC]);
$afterD = (new TripRepository())->rows($tid);
check(count($afterD) === count($beforeD) && n($afterD[0]['diesel']) === 50.0 && n($afterD[1]['diesel']) === 75.5, 'a clerk without trips.financials saves the form: every entry keeps its hidden expenses, and no forged amount is accepted');
// export
fresh_session();
sign_in($mgr);
$csv = call('GET', '/trips/export', [], ['q' => 'AC-1'])->content();
$lines = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
$h = array_flip($lines[0]);
check(isset($h['Activity'], $h['To'], $h['Foreman'], $h['BH']) && $h['BH'] + 1 === $h['Foreman'] && !isset($h['Collected By']), 'the export has Activity, To and Foreman (right after BH), and no Collected By');
$find = static fn (string $act, string $date): ?array => array_values(array_filter(array_slice($lines, 1), static fn (array $l): bool => $l[$h['Activity']] === $act && $l[$h['Date']] === $date))[0] ?? null;
$pk = $find('Pickup', '2026-10-30');
$dl = $find('Delivery', '2026-11-01');
check($pk !== null && $dl !== null && $pk[$h['Job #']] === 'B6048649' && $dl[$h['Job #']] === 'B6048649' && $dl[$h['To']] === 'Denver, CO' && $dl[$h['Foreman']] === 'Sam' && $pk[$h['From']] === 'Atlanta, GA', 'both entries of the same job are in the export, one line each, with activity, date, From, To and Foreman');
// the trip list search no longer looks at collected by, and still finds by job number
check(str_contains(call('GET', '/trips', [], ['q' => 'B6048649'])->body(), 'AC-1'), 'searching the trip list by job number still finds the trip');

// migration 014 on a database that already has trip rows
$dir = sys_get_temp_dir() . '/jt-mig14-' . bin2hex(random_bytes(4));
mkdir($dir);
foreach (glob(BASE_PATH . '/database/migrations/0{0[1-9],1[0-3]}_*.sql', GLOB_BRACE) as $f) {
    copy($f, $dir . '/' . basename($f));
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Database::setConnection($pdo);
(new Migrator($dir))->migrate();
$now = '2026-01-01 00:00:00';
$pdo->exec("INSERT INTO foremen (name, phone, active, created_at, updated_at) VALUES ('Joe', NULL, 1, '{$now}', '{$now}')");
$pdo->exec("INSERT INTO jobs (job_number, customer_first_name, customer_last_name, first_available_date, from_location, to_location, status, created_at, updated_at) VALUES ('OLD-14', 'A', 'B', '2026-01-01', 'X', 'Denver', 'booked', '{$now}', '{$now}')");
$pdo->exec("INSERT INTO trips (trip_number, foreman_id, begins_on, collected_by, created_at, updated_at) VALUES ('OLD-T', 1, '2026-02-01', 'Somebody', '{$now}', '{$now}')");
$pdo->exec("INSERT INTO trip_jobs (trip_id, job_id, sort_order, job_number, customer_name, job_date, from_location, diesel, commission) VALUES (1, 1, 0, 'OLD-14', 'A B', '2026-02-01', 'X', 12.5, 99.99)");
copy(BASE_PATH . '/database/migrations/014_trip_row_activity_to_foreman.sql', $dir . '/014_trip_row_activity_to_foreman.sql');
check((new Migrator($dir))->migrate() === ['014_trip_row_activity_to_foreman.sql'], 'migration 014 is the only one that runs on an existing database');
$old = $pdo->query('SELECT * FROM trip_jobs')->fetch();
check($old['activity'] === null && $old['to_location'] === 'Denver' && (int) $old['foreman_id'] === 1, 'an existing row gets no activity, the job\'s To place and the trip\'s foreman');
check((float) $old['diesel'] === 12.5 && (float) $old['commission'] === 99.99 && $old['job_date'] === '2026-02-01' && $old['from_location'] === 'X' && $old['job_number'] === 'OLD-14', 'nothing else on the row, and no amount, changed');
check($pdo->query('SELECT collected_by FROM trips')->fetchColumn() === 'Somebody', 'the old Collected By data is kept in the database');
check((new Migrator($dir))->migrate() === [], 'running it again does nothing');
Database::setConnection(null);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);
fresh_db();
Clock::fake(null);
