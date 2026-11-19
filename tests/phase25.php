<?php

declare(strict_types=1);

/**
 * v1.9.0 (calendar phase 4): a fixed list of carriers in Settings, and a carrier chosen from it when a job goes In Transit.
 * Included by tests/run.php (after phase24.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Repositories\JobRepository;
use App\Services\CarrierList;
use App\Services\SettingsService;

section('Carrier list: tidy parsing');
$p = CarrierList::parse("  KEN \n\ndinoca\nken\n  Big   Rig  Co \r\nKenth");
check($p['error'] === null && $p['value'] === "KEN\ndinoca\nBig Rig Co\nKenth", 'names are trimmed, spaces collapsed, blank lines dropped and the same name (any letter case) kept once');
check(CarrierList::parse('')['value'] === '' && CarrierList::parse("\n \n")['value'] === '', 'an empty list is allowed (nothing is required)');
check(CarrierList::parse(str_repeat('x', 101))['error'] !== null, 'a name over 100 characters is refused');
check(CarrierList::parse('Bad <b>')['error'] !== null && CarrierList::parse("A\x07B")['error'] !== null, 'a name with < > or a control character is refused');
check(CarrierList::parse(implode("\n", range(1, 201)))['error'] !== null && CarrierList::parse(implode("\n", range(1, 200)))['error'] === null, 'at most 200 carriers');
check(CarrierList::parse(['x'])['error'] !== null, 'something that is not text is refused');

section('Carrier list: Settings page');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$admin = make_user('Admin', 'p25-admin@example.com');
sign_in($admin);
check(str_contains(call('GET', '/settings')->body(), 'name="carriers"'), 'the Settings page has a Carriers box');
$post = static function (string $carriers) {
    return call('POST', '/settings', ['carriers' => $carriers] + SettingsService::editable());
};
$post("KEN\nDINOCA\nKenth");
check(CarrierList::all() === ['KEN', 'DINOCA', 'Kenth'] && CarrierList::required() === true, 'saving stores the list, and a carrier is then required');
check(CarrierList::find('dinoca') === 'DINOCA' && CarrierList::find(' ken ') === 'KEN' && CarrierList::find('nobody') === null, 'a name is found ignoring letter case, and gives the list\'s own spelling');
$post("KEN\n<script>");
check(CarrierList::all() === ['KEN', 'DINOCA', 'Kenth'], 'an invalid list is refused and the saved list stays');
call('POST', '/settings/storage-fee', ['storage_fee_rate' => '0.7', 'storage_fee_after_days' => '30', 'storage_fee_basis' => 'pickup', 'storage_fee_mode' => 'monthly']);
check(CarrierList::all() === ['KEN', 'DINOCA', 'Kenth'], 'the storage-fee-only page does not wipe the carrier list');
check(str_contains(plain(call('GET', '/settings')->body()), 'KEN') && str_contains(call('GET', '/settings')->body(), '>KEN'), 'the Settings page shows the saved list');

section('Move to In Transit asks for a carrier');
$mk = static fn (string $n, array $o = []): int => mk_job($o + ['job_number' => $n, 'pickup_date' => '2026-11-10', 'first_available_date' => '2026-11-10', 'status' => 'booked', 'customer_first_name' => 'Ada', 'customer_last_name' => 'Smith']);
$status = static fn (int $id, array $post): mixed => call('POST', "/jobs/{$id}/status", $post);
$state = static fn (int $id): array => Database::fetchAll('SELECT status, carrier_name, driver_name, driver_phone, loaded_date FROM jobs WHERE id = ?', [$id])[0];

// 1. list empty: one click, as before
$set = static fn (string $v) => Database::execute("UPDATE settings SET setting_value = ? WHERE setting_key = 'carriers'", [$v]);
SettingsService::flush();
$set('');
SettingsService::flush();
$j0 = $mk('CR-EMPTY');
check(CarrierList::required() === false, 'with no carriers on the list nothing is required');
$status($j0, ['to' => 'in_transit']);
check($state($j0)['status'] === 'in_transit' && $state($j0)['carrier_name'] === null, 'with an empty list Move to In Transit is still one click and needs no carrier');

// 2. list has names
$set("KEN\nDINOCA\nKenth");
SettingsService::flush();
$j1 = $mk('CR-1');
$show = call('GET', "/jobs/{$j1}")->body();
check(!preg_match('~<form[^>]*action="[^"]*/jobs/' . $j1 . '/status"[^>]*>\s*<input[^>]*name="_csrf"[^>]*>\s*<input type="hidden" name="to" value="in_transit">\s*(?:<button[^>]*data-confirm)~s', $show) && str_contains($show, 'id="statusModal-in_transit"') && str_contains($show, 'data-bs-target="#statusModal-in_transit"'), 'the job page opens a dialog for Move to In Transit instead of moving the job at once');
check(preg_match('~id="statusModal-in_transit".*?<select[^>]*name="carrier_name"[^>]*required.*?<option value="KEN">KEN</option>.*?<option value="DINOCA">DINOCA</option>.*?<option value="Kenth">Kenth</option>.*?name="driver_name".*?name="driver_phone"~s', $show) === 1, 'the dialog has a required Carrier drop-down with the three carriers, and Driver name and phone');
$status($j1, ['to' => 'in_transit']);
check($state($j1)['status'] === 'booked', 'without a carrier the job does not move');
check(preg_match('/carrier is required/i', plain(call('GET', "/jobs/{$j1}")->body())) === 1, 'and the dialog says a carrier is required');
$status($j1, ['to' => 'in_transit', 'carrier_name' => 'XYZ Haulers']);
check($state($j1)['status'] === 'booked', 'a carrier that is not on the list is refused');
check(str_contains(plain(call('GET', "/jobs/{$j1}")->body()), 'Choose a carrier from the list.'), '...with "Choose a carrier from the list."');
$status($j1, ['to' => 'in_transit', 'carrier_name' => 'KEN', 'driver_name' => 'Dan Miller', 'driver_phone' => 'abc']);
check($state($j1)['status'] === 'booked', 'an invalid driver phone is refused');
$status($j1, ['to' => 'in_transit', 'carrier_name' => 'ken', 'driver_name' => 'Dan Miller', 'driver_phone' => '(555) 010-2231', 'note' => 'left at 8']);
$s = $state($j1);
check($s['status'] === 'in_transit' && $s['carrier_name'] === 'KEN' && $s['driver_name'] === 'Dan Miller' && $s['driver_phone'] === '(555) 010-2231' && $s['loaded_date'] === '2026-11-16', 'with a listed carrier (typed in lower case) the job moves, saves "KEN" in the list\'s spelling, the driver, and today as the loaded date');
$page = plain(call('GET', "/jobs/{$j1}")->body());
check(str_contains($page, 'Carrier KEN') && str_contains($page, 'driver Dan Miller') && str_contains($page, '(555) 010-2231'), 'the history shows the carrier and the driver');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'job_status_changed' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$j1]);
check(str_contains($aud, 'KEN') && str_contains($aud, 'Dan Miller'), 'the status change is audited with the carrier');
$j2 = $mk('CR-2');
$status($j2, ['to' => 'in_transit', 'carrier_name' => 'DINOCA']);
check($state($j2)['status'] === 'in_transit' && $state($j2)['driver_name'] === null, 'the driver is optional');

// 3. the one-click button in the lists
$j3 = $mk('CR-3');
$ready = call('GET', '/jobs/ready')->body();
check(preg_match('~href="/jobs/' . $j3 . '\?do=staged"~', $ready) === 1 && !preg_match('~action="[^"]*/jobs/' . $j3 . '/status"~', $ready), 'in a list the Stage button opens the job\'s carrier dialog (no one-click post)');
$auto = call('GET', "/jobs/{$j3}", [], ['do' => 'in_transit'])->body();
check(str_contains($auto, 'id="statusModal-in_transit"'), 'the link opens the job page with the dialog available');

// 4. editing an in-transit job
$edit = call('GET', "/jobs/{$j1}/edit")->body();
check(preg_match('~<select[^>]*name="carrier_name".*?<option value="KEN" selected>~s', $edit) === 1, 'the edit form shows the carrier as a drop-down with the saved one selected');
$job = (new JobRepository())->find($j1);
call('POST', "/jobs/{$j1}", job_input(['job_number' => 'CR-1', 'carrier_name' => 'Nobody Inc', 'loaded_at' => (string) $job['updated_at']]));
check($state($j1)['carrier_name'] === 'KEN', 'changing the carrier to a name that is not on the list is refused');
$job = (new JobRepository())->find($j1);
call('POST', "/jobs/{$j1}", job_input(['job_number' => 'CR-1', 'carrier_name' => 'dinoca', 'driver_name' => 'Dan Miller', 'loaded_at' => (string) $job['updated_at']]));
check($state($j1)['carrier_name'] === 'DINOCA', 'a listed carrier can be chosen, saved in the list\'s spelling');
// a carrier the job already had (before the list) stays editable
Database::execute("UPDATE jobs SET carrier_name = 'Old Hauler' WHERE id = ?", [$j1]);
$edit = call('GET', "/jobs/{$j1}/edit")->body();
check(str_contains($edit, 'Old Hauler (not on the list)'), 'a carrier that is not on the list stays visible on the edit form');
$job = (new JobRepository())->find($j1);
call('POST', "/jobs/{$j1}", job_input(['job_number' => 'CR-1', 'carrier_name' => 'Old Hauler', 'notes' => 'kept', 'loaded_at' => (string) $job['updated_at']]));
check($state($j1)['carrier_name'] === 'Old Hauler', 'and the job can be saved with it unchanged');
// an old in-transit job with no carrier can still be edited
Database::execute("UPDATE jobs SET carrier_name = NULL, driver_name = NULL WHERE id = ?", [$j1]);
$job = (new JobRepository())->find($j1);
call('POST', "/jobs/{$j1}", job_input(['job_number' => 'CR-1', 'notes' => 'no carrier yet', 'loaded_at' => (string) $job['updated_at']]));
check(Database::fetchValue('SELECT notes FROM jobs WHERE id = ?', [$j1]) === 'no carrier yet', 'an in-transit job that has no carrier (from before the list) can still be edited');

// 5. the other status changes are untouched
$j4 = $mk('CR-4');
$status($j4, ['to' => 'on_hold', 'note' => 'customer asked']);
check($state($j4)['status'] === 'on_hold', 'Put on hold still works without a carrier');
$status($j4, ['to' => 'booked']);
$status($j4, ['to' => 'cancelled', 'note' => 'cancelled']);
check($state($j4)['status'] === 'cancelled', 'Cancel job still works');
$status($j2, ['to' => 'delivered']);
check($state($j2)['status'] === 'delivered', 'Mark as Delivered is still one click');
$status($j2, ['to' => 'in_transit', 'note' => 'reopened']);
check($state($j2)['status'] === 'in_transit' && $state($j2)['carrier_name'] === 'DINOCA', 'reopening a delivered job to In Transit needs no new carrier and keeps the one it had');
// a user who may not change status cannot do it
make_role('ViewOnly25', ['jobs.view']);
sign_in(make_user('ViewOnly25', 'p25-view@example.com'));
$j5 = $mk('CR-5');
check($status($j5, ['to' => 'in_transit', 'carrier_name' => 'KEN'])->status() === 403 && $state($j5)['status'] === 'booked', 'a view-only user cannot move a job (403)');
