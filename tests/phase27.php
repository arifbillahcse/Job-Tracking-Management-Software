<?php

declare(strict_types=1);

/**
 * v1.11.0: the Stage step (Booked > Stage > In Transit > Delivered). The carrier and driver are chosen when the job is staged.
 * Included by tests/run.php (after phase26.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Repositories\JobRepository;
use App\Services\JobBucket;
use App\Services\JobCriteria;

section('Stage: the new step before In Transit');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$admin = make_user('Admin', 'p27-admin@example.com');
sign_in($admin);
$mk = static fn (string $n, array $o = []): int => mk_job($o + ['job_number' => $n, 'pickup_date' => '2026-11-10', 'first_available_date' => '2026-11-10', 'status' => 'booked', 'customer_first_name' => 'Ada', 'customer_last_name' => 'Lovelace']);
$status = static fn (int $id, array $post): mixed => call('POST', "/jobs/{$id}/status", $post);
$state = static fn (int $id): array => Database::fetchAll('SELECT status, carrier_name, driver_name, driver_phone, loaded_date, in_transit_at FROM jobs WHERE id = ?', [$id])[0];
$names = static function (string $bucket): array {
    return array_map(static fn (array $j): string => (string) $j['job_number'], (new \App\Services\JobService())->search(JobCriteria::forBucket($bucket), 100, 0));
};

// 1. carrier list empty: Stage is one click, then In Transit is one click
$a = $mk('ST-1');
$status($a, ['to' => 'staged']);
check($state($a)['status'] === 'staged' && $state($a)['loaded_date'] === null && $state($a)['in_transit_at'] === null, 'with no carrier list, Move to Stage is one click and saves no loaded date');
check(in_array('ST-1', $names(JobBucket::STAGE), true) && !in_array('ST-1', $names(JobBucket::READY), true) && !in_array('ST-1', $names(JobBucket::LATER), true), 'a staged job is in the Stage list, not in Ready or More Than 30 Days');
check(in_array('ST-1', $names(JobBucket::BOOKED), true), 'a staged job still counts in Booked Jobs (not delivered yet)');
$status($a, ['to' => 'in_transit']);
check($state($a)['status'] === 'in_transit' && $state($a)['loaded_date'] === '2026-11-16', 'Move to In Transit from Stage is one click and saves today as the loaded date');

// 2. a booked job can still go straight to In Transit
$b = $mk('ST-2');
$status($b, ['to' => 'in_transit']);
check($state($b)['status'] === 'in_transit', 'a booked job can still go straight to In Transit');

// 3. with a carrier list the carrier is asked at Stage
$setCarriers = static function (string $v): void {
    call('POST', '/settings', ['carriers' => $v] + \App\Services\SettingsService::editable());
    \App\Services\SettingsService::flush();
};
$setCarriers("KEN\nDINOCA");
$c = $mk('ST-3');
$status($c, ['to' => 'staged']);
check($state($c)['status'] === 'booked', 'with a carrier list, Move to Stage without a carrier is refused');
$status($c, ['to' => 'staged', 'carrier_name' => 'nobody']);
check($state($c)['status'] === 'booked', 'a carrier that is not on the list is refused at Stage');
$status($c, ['to' => 'staged', 'carrier_name' => 'ken', 'driver_name' => 'Dan Miller', 'driver_phone' => '(555) 010-2231']);
$s = $state($c);
check($s['status'] === 'staged' && $s['carrier_name'] === 'KEN' && $s['driver_name'] === 'Dan Miller' && $s['loaded_date'] === null, 'carrier and driver are saved at Stage (list spelling), with no loaded date yet');
$page = plain(call('GET', "/jobs/{$c}")->body());
check(str_contains($page, 'Carrier KEN') && str_contains($page, 'Dan Miller'), 'the history shows the carrier and driver chosen at Stage');
check(str_contains(plain(call('GET', '/jobs/stage')->body()), 'ST-3'), 'the Stage page lists it');
$rowPage = call('GET', "/jobs/{$c}")->body();
check(!str_contains($rowPage, 'statusModal-in_transit"') && str_contains($rowPage, 'value="in_transit"'), 'a staged job that has a carrier goes to In Transit with one click (no second carrier dialog)');
$status($c, ['to' => 'in_transit']);
$s = $state($c);
check($s['status'] === 'in_transit' && $s['carrier_name'] === 'KEN' && $s['driver_name'] === 'Dan Miller' && $s['loaded_date'] === '2026-11-16', 'In Transit keeps the carrier and driver and saves the loaded date');

// 4. staged without a carrier (staged before the list had names) is asked at In Transit
$setCarriers('');
$d = $mk('ST-4');
$status($d, ['to' => 'staged']);
$setCarriers("KEN\nDINOCA");
$status($d, ['to' => 'in_transit']);
check($state($d)['status'] === 'staged', 'a staged job with no carrier is asked for one when it goes In Transit');
$status($d, ['to' => 'in_transit', 'carrier_name' => 'DINOCA']);
check($state($d)['status'] === 'in_transit' && $state($d)['carrier_name'] === 'DINOCA', '...and then moves');

// 5. back to Booked, hold, cancel
$e = $mk('ST-5');
$status($e, ['to' => 'staged', 'carrier_name' => 'KEN']);
$status($e, ['to' => 'booked']);
check($state($e)['status'] === 'booked' && $state($e)['carrier_name'] === null, 'Back to Booked clears the carrier (it stays in the history)');
$status($e, ['to' => 'staged', 'carrier_name' => 'KEN']);
$status($e, ['to' => 'on_hold', 'note' => 'customer asked']);
check($state($e)['status'] === 'on_hold', 'a staged job can be put on hold');
$f = $mk('ST-6');
$status($f, ['to' => 'staged', 'carrier_name' => 'KEN']);
$status($f, ['to' => 'delivered']);
check($state($f)['status'] === 'staged', 'a staged job cannot be marked Delivered without going In Transit first');
$status($f, ['to' => 'cancelled', 'note' => 'gone']);
check($state($f)['status'] === 'cancelled', 'a staged job can be cancelled');

// 6. editing the carrier on a staged job
$g = $mk('ST-7');
$status($g, ['to' => 'staged', 'carrier_name' => 'KEN']);
check(preg_match('~<select[^>]*name="carrier_name".*?<option value="KEN" selected>~s', call('GET', "/jobs/{$g}/edit")->body()) === 1, 'the edit form of a staged job has the carrier drop-down');

// 7. storage fee keeps running while staged
check(in_array('staged', (new ReflectionClassConstant(\App\Services\StorageFeeService::class, 'OPEN'))->getValue(), true), 'the storage fee still runs on a staged job');

// 8. list row shortcut
$h = $mk('ST-8');
$rows = call('GET', '/jobs/ready')->body();
check(preg_match('~href="/jobs/' . $h . '\?do=staged"~', $rows) === 1, 'a booked row has the Stage button (it opens the carrier dialog)');
$tabs = plain(call('GET', '/jobs')->body());
check(preg_match('~More Than 30 Days \d+ Stage \d+ In Transit~', $tabs) === 1, 'the Stage tab sits between More Than 30 Days and In Transit');
