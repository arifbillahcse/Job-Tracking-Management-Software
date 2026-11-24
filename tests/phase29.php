<?php

declare(strict_types=1);

/**
 * v1.12.0 (rate phase 1): a carrier rate per CF on each job, for the Carrier Statement. Included by tests/run.php (after phase28.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Repositories\JobRepository;

section('Carrier rate on the job');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$admin = make_user('Admin', 'p29-admin@example.com');
sign_in($admin);
$rate = static function (int $id): ?string {
    $v = Database::fetchValue('SELECT carrier_rate FROM jobs WHERE id = ?', [$id]);
    return $v === null || $v === false ? null : number_format((float) $v, 2, '.', ''); // SQLite hands back a number, MySQL a string
};

// 1. create with a rate
$r = call('POST', '/jobs', job_input(['job_number' => 'RT-1', 'carrier_rate' => '2.5']));
$id = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'RT-1'");
check($id > 0 && $rate($id) === '2.50', 'a job is created with its carrier rate, stored with 2 decimals');
check(str_contains(plain(call('GET', "/jobs/{$id}")->body()), 'Carrier rate $2.50 per CF'), 'the job page shows the rate');
check(preg_match('~<input[^>]*name="carrier_rate"[^>]*value="2\.5(0)?"|<input[^>]*value="2\.5(0)?"[^>]*name="carrier_rate"~', call('GET', "/jobs/{$id}/edit")->body()) === 1, 'the edit form has the rate field with the saved value');

// 2. a job without a rate keeps it empty
call('POST', '/jobs', job_input(['job_number' => 'RT-2']));
$id2 = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'RT-2'");
check($id2 > 0 && $rate($id2) === null, 'a job saved without a rate has none (not 0)');
check(str_contains(plain(call('GET', "/jobs/{$id2}")->body()), 'Carrier rate —'), '...and the page shows a dash');

// 3. edit the rate, then clear it
$edit = static function (int $jobId, string $number, array $extra) {
    $job = (new JobRepository())->find($jobId);
    return call('POST', "/jobs/{$jobId}", job_input(['job_number' => $number, 'loaded_at' => (string) $job['updated_at']] + $extra));
};
$edit($id, 'RT-1', ['carrier_rate' => '3.75']);
check($rate($id) === '3.75', 'the rate can be changed on an existing job');
$edit($id, 'RT-1', ['carrier_rate' => '']);
check($rate($id) === null, 'clearing the field removes the rate');
$edit($id, 'RT-1', ['carrier_rate' => '2.50']);
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'job_updated' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$id]);
check(str_contains($aud, 'carrier_rate'), 'a rate change is audited');

// 4. validation
foreach (['-1', 'abc', '2.555', '10000', '1e3', '$2.50'] as $bad) {
    $edit($id, 'RT-1', ['carrier_rate' => $bad]);
    check($rate($id) === '2.50', 'rate "' . $bad . '" is refused and the saved one stays');
}
$edit($id, 'RT-1', ['carrier_rate' => '0']);
check($rate($id) === '0.00', 'a rate of 0 is allowed (free)');
$edit($id, 'RT-1', ['carrier_rate' => '2.50']);

// 5. people who may not see money
$staff = make_user('Staff', 'p29-staff@example.com');
sign_in($staff);
check(!str_contains(call('GET', "/jobs/{$id}/edit")->body(), 'name="carrier_rate"') && !str_contains(plain(call('GET', "/jobs/{$id}")->body()), 'Carrier rate'), 'Staff see no rate field and no rate on the job page');
$job = (new JobRepository())->find($id);
call('POST', "/jobs/{$id}", job_input(['job_number' => 'RT-1', 'loaded_at' => (string) $job['updated_at'], 'carrier_rate' => '9.99']));
check($rate($id) === '2.50', 'Staff cannot change the rate by posting it');
sign_in($admin);
