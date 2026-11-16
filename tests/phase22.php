<?php

declare(strict_types=1);

/**
 * v1.8.0 phase 1: pickup time, miles and the four checks (Confirmed, E-Signed, Job Docs, Bill of Lading) on a job. Migration 019.
 * Included by tests/run.php (after phase21.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Database\Migrator;
use App\Repositories\JobRepository;
use App\Services\Import\JobFields;

section('Migration 019 on an existing database');
$dir = sys_get_temp_dir() . '/jt-mig19-' . bin2hex(random_bytes(4));
mkdir($dir);
foreach (glob(BASE_PATH . '/database/migrations/0{0[1-9],1[0-8]}_*.sql', GLOB_BRACE) as $f) {
    copy($f, $dir . '/' . basename($f));
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Database::setConnection($pdo);
(new Migrator($dir))->migrate();
$pdo->exec("INSERT INTO jobs (job_number, customer_first_name, customer_last_name, first_available_date, from_location, to_location, status, created_at, updated_at) VALUES ('OLD-1', 'A', 'B', '2026-01-01', 'X', 'Y', 'booked', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
copy(BASE_PATH . '/database/migrations/019_job_pickup_details.sql', $dir . '/019_job_pickup_details.sql');
check((new Migrator($dir))->migrate() === ['019_job_pickup_details.sql'], 'only migration 019 runs on an existing database');
$old = $pdo->query("SELECT pickup_time, miles, confirmed, e_signed, job_docs, bill_of_lading FROM jobs WHERE job_number = 'OLD-1'")->fetch();
check($old['pickup_time'] === null && $old['miles'] === null && (int) $old['confirmed'] === 0 && (int) $old['e_signed'] === 0 && (int) $old['job_docs'] === 0 && (int) $old['bill_of_lading'] === 0, 'existing jobs keep their data: no time, no miles, nothing ticked');
check((new Migrator($dir))->migrate() === [], 'running it again does nothing');
Database::setConnection(null);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);

section('Pickup time, miles and the four checks');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$mgr = make_user('Manager', 'p22-mgr@example.com');
sign_in($mgr);
$form = call('GET', '/jobs/create')->body();
check(str_contains($form, 'name="pickup_time"') && str_contains($form, 'name="miles"') && str_contains($form, 'name="confirmed"') && str_contains($form, 'name="e_signed"') && str_contains($form, 'name="job_docs"') && str_contains($form, 'name="bill_of_lading"') && substr_count($form, 'type="checkbox"') >= 4, 'the job form has Pickup time, Miles and the four checkboxes');
call('POST', '/jobs', job_input(['job_number' => 'PD-1', 'pickup_time' => '08-10AM', 'miles' => '593.5', 'confirmed' => '1', 'job_docs' => '1']));
call('POST', '/jobs', job_input(['job_number' => 'PD-2']));
$row = static fn (string $n): array => Database::fetchAll('SELECT pickup_time, miles, confirmed, e_signed, job_docs, bill_of_lading FROM jobs WHERE job_number = ?', [$n])[0] ?? [];
$a = $row('PD-1');
check($a['pickup_time'] === '08-10AM' && (float) $a['miles'] === 593.5 && (int) $a['confirmed'] === 1 && (int) $a['e_signed'] === 0 && (int) $a['job_docs'] === 1 && (int) $a['bill_of_lading'] === 0, 'the values are saved, and only the ticked boxes are 1');
$b = $row('PD-2');
check($b['pickup_time'] === null && $b['miles'] === null && (int) $b['confirmed'] + (int) $b['e_signed'] + (int) $b['job_docs'] + (int) $b['bill_of_lading'] === 0, 'a job with nothing typed has no time, no miles and no ticks');
$id = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'PD-1'");
$edit = call('GET', "/jobs/{$id}/edit")->body();
check(preg_match('~id="confirmed"[^>]*checked~', $edit) === 1 && preg_match('~id="job_docs"[^>]*checked~', $edit) === 1 && preg_match('~id="e_signed"[^>]*checked~', $edit) === 0 && str_contains($edit, 'value="08-10AM"') && str_contains($edit, 'value="593.5"'), 'Edit shows the saved time and miles and ticks exactly the saved checks');
$job = (new JobRepository())->find($id);
call('POST', "/jobs/{$id}", job_input(['job_number' => 'PD-1', 'pickup_time' => '12-2PM', 'miles' => '', 'e_signed' => '1', 'bill_of_lading' => '1', 'loaded_at' => (string) $job['updated_at']]));
$a = $row('PD-1');
check($a['pickup_time'] === '12-2PM' && $a['miles'] === null && (int) $a['confirmed'] === 0 && (int) $a['e_signed'] === 1 && (int) $a['job_docs'] === 0 && (int) $a['bill_of_lading'] === 1, 'editing changes them: unticking clears a check, clearing miles empties it');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'job_updated' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$id]);
check(str_contains($aud, 'pickup_time') && str_contains($aud, 'e_signed'), 'the change is in the audit log');
$page = plain(call('GET', "/jobs/{$id}")->body());
check(str_contains($page, 'Pickup time 12-2PM') && preg_match('~Miles\s*—~', $page) === 1 && str_contains($page, '✓ E-Signed') && str_contains($page, '✓ Bill of Lading') && str_contains($page, '☐ Confirmed') && str_contains($page, '☐ Job Docs'), 'Job details show the time, the miles and which checks are ticked');
foreach ([['miles', '-5'], ['miles', 'abc'], ['miles', '100000'], ['miles', '1.234'], ['pickup_time', str_repeat('x', 41)], ['confirmed', '2'], ['confirmed', 'yes']] as [$field, $value]) {
    $before = (int) Database::fetchValue('SELECT COUNT(*) FROM jobs');
    call('POST', '/jobs', job_input(['job_number' => 'PD-BAD', $field => $value]));
    check((int) Database::fetchValue('SELECT COUNT(*) FROM jobs') === $before, "{$field} = " . substr($value, 0, 12) . ' is refused');
}
check(isset(JobFields::all()['pickup_time'], JobFields::all()['miles']) && JobFields::label('miles') === 'Miles', 'import and export know Pickup Time and Miles');
$csv = (new \App\Services\ExportService())->csv(\App\Services\JobCriteria::forBucket('all'), true)->content();
check(str_contains($csv, 'Pickup Time') && str_contains($csv, '12-2PM'), 'the CSV export carries the pickup time');
// a user who may only view jobs cannot change them
make_role('ViewOnly22', ['jobs.view']);
sign_in(make_user('ViewOnly22', 'p22-view@example.com'));
$job = (new JobRepository())->find($id);
call('POST', "/jobs/{$id}", job_input(['job_number' => 'PD-1', 'pickup_time' => 'HACK', 'confirmed' => '1', 'loaded_at' => (string) $job['updated_at']]));
check($row('PD-1')['pickup_time'] === '12-2PM' && (int) $row('PD-1')['confirmed'] === 0, 'a view-only user cannot change them (403)');
