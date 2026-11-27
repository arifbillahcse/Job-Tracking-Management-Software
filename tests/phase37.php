<?php

declare(strict_types=1);

/**
 * v1.19.0 (drivers): the Drivers master (phone, licence) matched to the job's driver name; picking a driver fills the phone box; the
 * licence is printed on the driver statement and copied onto the invoice. Included by tests/run.php (after phase36.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Services\DriverService;
use App\Services\InvoiceService;
use App\Services\SettingsService;

section('Drivers: input rules');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$admin = make_user('Admin', 'p37-admin@example.com');
sign_in($admin);

$ok = ['name' => 'Joe Smith', 'phone' => '973-718-0688', 'license' => 'GA-1234567'];
check(DriverService::validate($ok, null)['errors'] === [] && DriverService::validate(['name' => 'Only A Name'], null)['errors'] === [], 'a normal driver is accepted, and only the name is required');
foreach ([['name', ''], ['name', '<b>x</b>'], ['name', str_repeat('x', 151)], ['phone', 'call me'], ['phone', str_repeat('1', 31)], ['license', 'GA;DROP'], ['license', str_repeat('9', 51)]] as [$key, $bad]) {
    check(isset(DriverService::validate([$key => $bad] + $ok, null)['errors'][$key]), $key . ' "' . mb_substr($bad, 0, 12) . '" is refused');
}
check(DriverService::validate(['name' => "  Joe \t  Smith \n"] + $ok, null)['values']['name'] === 'Joe Smith', 'spaces and control characters in the name are tidied');

section('Drivers: pages');
check(str_contains(plain(call('GET', '/drivers')->body()), 'No drivers yet'), 'the page starts empty');
call('POST', '/drivers', $ok);
$d = DriverService::findByName('JOE smith');
check($d !== null && $d['name'] === 'Joe Smith' && $d['license'] === 'GA-1234567' && $d['phone'] === '973-718-0688', 'a driver is added and found by name ignoring letter case');
call('POST', '/drivers', ['name' => 'JOE SMITH'] + $ok);
call('POST', '/drivers', ['name' => '']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM drivers') === 1, 'a second driver with the same name (any letter case), or an empty name, saves nothing');
call('GET', '/drivers/create'); // shows (and clears) the error left by the empty name above
$pf = call('GET', '/drivers/create', [], ['name' => 'Dan Miller'])->body();
check(str_contains($pf, 'value="Dan Miller"'), 'the add form can be prefilled with a driver name from a job');
check(call('GET', '/drivers/999999/edit')->status() === 404 && call('POST', '/drivers/999999', $ok)->status() === 404 && call('POST', '/drivers/999999/delete')->status() === 404, 'a driver that does not exist is a 404');

$mk = static fn (string $n, array $o = []): int => mk_job($o + ['job_number' => $n, 'status' => 'in_transit', 'carrier_name' => 'KEN', 'driver_name' => 'Joe Smith', 'driver_phone' => null, 'broker' => 'Prime', 'cf' => '100', 'carrier_rate' => '2.00', 'balance' => '500.00', 'loaded_date' => '2026-11-05']);
$j1 = $mk('D-1');
$j2 = $mk('D-2', ['driver_name' => 'joe smith', 'cf' => '50', 'carrier_rate' => '3.00', 'balance' => '100.00']);
$j3 = $mk('D-3', ['driver_name' => 'Dan Miller', 'driver_phone' => '555-0100']);
$list = plain(call('GET', '/drivers')->body());
check(str_contains($list, 'Joe Smith') && str_contains($list, '973-718-0688') && str_contains($list, 'GA-1234567'), 'the list shows the driver and the details');
check(DriverService::search('')[0]['job_count'] == 2, 'the job count matches the driver name ignoring letter case (2 jobs)');
check(str_contains($list, 'Drivers on jobs with no details') && str_contains($list, 'Dan Miller') && DriverService::unlinked() === [['driver' => 'Dan Miller', 'jobs' => 1]], 'drivers on jobs with no record are listed with an Add details link');
check(str_contains(plain(call('GET', '/drivers', [], ['q' => 'GA-12'])->body()), 'Joe Smith') && str_contains(plain(call('GET', '/drivers', [], ['q' => 'zzz'])->body()), 'No driver matches') && !str_contains(call('GET', '/drivers', [], ['q' => '<script>x</script>'])->body(), '<script>x</script>'), 'search finds a driver by licence, and a search typed as markup is never output raw');

// 2. picking a driver on a job
call('POST', '/drivers', ['name' => 'Zed Ray', 'phone' => '555-0199']);
$form = call('GET', '/jobs/create')->body();
check(!str_contains($form, 'driver-list'), 'a new job has no driver box (drivers are chosen when a job is staged)');
$edit = call('GET', "/jobs/{$j1}/edit")->body();
check(str_contains($edit, '<option value="Zed Ray">') && str_contains($edit, '<option value="Dan Miller">') && str_contains($edit, 'data-phone-target="driver_phone"') && str_contains($edit, 'Zed Ray&quot;:&quot;555-0199'), 'the job form offers each driver once and knows their phones');
check(substr_count(strtolower($edit), '<option value="joe smith">') === 1, 'a driver used on jobs and in the list is offered once');
call('POST', '/settings', ['carriers' => "KEN\nOTHER"] + SettingsService::editable());
SettingsService::flush();
$booked = mk_job(['job_number' => 'D-4', 'status' => 'booked', 'first_available_date' => '2026-11-10', 'pickup_date' => '2026-11-10']);
$show = call('GET', "/jobs/{$booked}")->body();
check(str_contains($show, 'list="driver-list-staged"') && str_contains($show, '<option value="Zed Ray">') && str_contains($show, 'Zed Ray&quot;:&quot;555-0199') && str_contains($show, 'data-phone-target="statusModal-staged-phone"'), 'the Move to Stage dialog offers the drivers and knows their phones');
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
check(str_contains($js, 'data-driver-phones') && str_contains($js, 'fillDriverPhone') && str_contains($js, "target.value.trim() !== ''"), 'the script fills the phone box only when it is empty');

// 3. the statement and the invoice
$st = plain(call('GET', '/jobs/statement', [], ['go' => '1', 'driver' => 'Joe Smith'])->body());
check(str_contains($st, 'Driver Statement') && str_contains($st, 'License: GA-1234567') && str_contains($st, 'Phone: 973-718-0688'), 'a driver statement shows the licence, and the phone from the driver record when the jobs have none');
check(str_contains(plain(call('GET', '/jobs/statement', [], ['go' => '1', 'driver' => 'Dan Miller'])->body()), 'Phone: 555-0100') && !str_contains(plain(call('GET', '/jobs/statement', [], ['go' => '1', 'driver' => 'Dan Miller'])->body()), 'License:'), 'a driver with no record shows the phone from the jobs and no licence');
call('POST', '/invoices', ['driver' => 'Joe Smith']);
$di = Database::fetchOne("SELECT * FROM invoices WHERE type = 'DI' AND seq = 1");
check($di !== null && $di['driver_license'] === 'GA-1234567' && $di['driver_phone'] === '973-718-0688', 'saving a driver invoice copies the licence (and the phone) onto it');
check(str_contains(plain(call('GET', '/invoices/' . $di['id'])->body()), 'License: GA-1234567'), 'the invoice shows the licence');
call('POST', '/drivers/' . $d['id'], ['name' => 'Joe Smith', 'phone' => '1', 'license' => 'NEW-999']);
check(str_contains(plain(call('GET', '/invoices/' . $di['id'])->body()), 'License: GA-1234567') && !str_contains(plain(call('GET', '/invoices/' . $di['id'])->body()), 'NEW-999'), 'changing the licence later does not change a saved invoice');
check(str_contains(plain(call('GET', '/jobs/statement', [], ['go' => '1', 'driver' => 'Joe Smith'])->body()), 'License: NEW-999'), 'but a new statement uses the new licence');
call('POST', '/invoices/' . $di['id'] . '/company-invoices');
$ci = Database::fetchOne("SELECT * FROM invoices WHERE type = 'CI' AND source_invoice_id = ?", [$di['id']]);
check($ci !== null && $ci['driver_license'] === 'GA-1234567', 'a company invoice generated from it copies the licence too');

// 4. rename and delete
call('POST', '/drivers/' . $d['id'], ['name' => 'Joseph Smith', 'phone' => '1', 'license' => 'NEW-999', 'rename_jobs' => '1']);
check(Database::fetchValue('SELECT driver_name FROM jobs WHERE id = ?', [$j1]) === 'Joseph Smith' && Database::fetchValue('SELECT driver_name FROM jobs WHERE id = ?', [$j2]) === 'Joseph Smith' && Database::fetchValue('SELECT driver_name FROM jobs WHERE id = ?', [$j3]) === 'Dan Miller', 'renaming with the box ticked changes the driver on its jobs, and only on its jobs');
call('POST', '/drivers/' . $d['id'], ['name' => 'Joe Again', 'phone' => '1', 'license' => 'NEW-999']);
check(Database::fetchValue('SELECT driver_name FROM jobs WHERE id = ?', [$j1]) === 'Joseph Smith' && in_array('Joseph Smith', array_column(DriverService::unlinked(), 'driver'), true), 'renaming with the box not ticked leaves the jobs alone');
call('POST', '/drivers', ['name' => 'Taken']);
call('POST', '/drivers/' . $d['id'], ['name' => 'TAKEN']);
check(DriverService::find((int) $d['id'])['name'] === 'Joe Again', 'renaming to a name another driver has is refused');
check(Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'driver_created'") == 3 && Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'driver_updated'") >= 3, 'adding and changing drivers is audited');
call('POST', '/drivers/' . $d['id'] . '/delete');
check(DriverService::find((int) $d['id']) === null && Database::fetchValue('SELECT driver_name FROM jobs WHERE id = ?', [$j1]) === 'Joseph Smith' && Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'driver_deleted'") == 1, 'removing a driver keeps the name on its jobs, and is audited');
check(str_contains(plain(call('GET', '/invoices/' . $di['id'])->body()), 'License: GA-1234567'), 'a saved invoice keeps its copy of the licence');

// 5. who may
$staff = make_user('Staff', 'p37-staff@example.com');
sign_in($staff);
check(call('GET', '/drivers')->status() === 403 && call('POST', '/drivers', $ok)->status() === 403 && call('POST', '/drivers/1/delete')->status() === 403, 'Staff cannot see or change drivers');
check(!str_contains(call('GET', '/dashboard')->body(), 'href="/drivers"'), 'and the menu does not list Drivers for Staff');
$manager = make_user('Manager', 'p37-mgr@example.com');
sign_in($manager);
check(call('GET', '/drivers')->status() === 200 && str_contains(call('GET', '/dashboard')->body(), 'href="/drivers"'), 'a Manager can, and the menu lists Drivers');
sign_in($admin);
