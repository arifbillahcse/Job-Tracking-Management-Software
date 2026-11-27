<?php

declare(strict_types=1);

/**
 * v1.14.0 (company statement): the statement can be for a company (the job's broker), alone or with a carrier / driver.
 * Included by tests/run.php (after phase30.php).
 */

use App\Core\Clock;
use App\Core\Database;

section('Company statement');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$admin = make_user('Admin', 'p31-admin@example.com');
sign_in($admin);

$mk = static fn (string $n, array $o = []): int => mk_job($o + ['job_number' => $n, 'status' => 'in_transit', 'carrier_name' => 'KEN', 'driver_name' => 'Dan', 'broker' => 'Prime', 'cf' => '100', 'carrier_rate' => '2.00', 'balance' => '500.00', 'loaded_date' => '2026-11-05']);
$mk('C-1');
$mk('C-2', ['carrier_name' => 'DINOCA', 'driver_name' => 'Eve', 'cf' => '50', 'carrier_rate' => '3.00', 'balance' => '100.00', 'status' => 'delivered']);
$mk('C-3', ['broker' => 'Next Level', 'balance' => '999.00']);
$mk('C-4', ['broker' => null, 'balance' => '777.00']);
$mk('C-5', ['broker' => 'Prime', 'status' => 'booked']);
$page = static fn (array $q) => plain(call('GET', '/jobs/statement', [], $q + ['go' => '1'])->body());

check(str_contains(plain(call('GET', '/jobs/statement')->body()), 'Any company') && str_contains(call('GET', '/jobs/statement')->body(), 'value="Prime"') && str_contains(call('GET', '/jobs/statement')->body(), 'value="Next Level"'), 'the form has a Company drop-down listing the companies of jobs that can be on a statement');
check(!str_contains(call('GET', '/jobs/statement')->body(), 'value="">Any company</option><option value="Prime">Prime</option><option value="Prime"'), 'a company is listed once');

$co = $page(['company' => 'Prime']);
check(str_contains($co, 'Company Statement') && str_contains($co, 'C-1') && str_contains($co, 'C-2') && !str_contains($co, 'C-3') && !str_contains($co, 'C-4') && !str_contains($co, 'C-5'), 'a company statement lists that company\'s Stage / In Transit / Delivered jobs, from every carrier');
// C-1: 100 x 2 = 200 ; C-2: 50 x 3 = 150 ; balances 500 + 100 = 600 ; due = 250
check(str_contains($co, '$350.00') && str_contains($co, '$600.00') && str_contains($co, 'TOTAL DUE $250.00'), 'same hishab: Bal. Due total - CF Pay total (600 - 350 = 250)');
check(str_contains($co, 'Carrier / Driver') && str_contains($co, 'KEN') && str_contains($co, 'DINOCA') && str_contains($co, 'Dan') && str_contains($co, 'Eve'), 'each row says which carrier and driver took the job');
check(str_contains($page(['company' => 'prime']), 'C-1'), 'the company is matched ignoring letter case');

$both = $page(['company' => 'Prime', 'carrier' => 'KEN']);
check(str_contains($both, 'C-1') && !str_contains($both, 'C-2') && str_contains($both, 'Carrier Statement') && str_contains($both, 'Company: Prime'), 'company + carrier narrow it down, titled Carrier Statement, with the company named');
$drv = $page(['company' => 'Prime', 'driver' => 'Eve']);
check(str_contains($drv, 'C-2') && !str_contains($drv, 'C-1') && str_contains($drv, 'Driver Statement') && str_contains($drv, 'Company: Prime'), 'company + driver: Driver Statement');
$all3 = $page(['company' => 'Prime', 'carrier' => 'KEN', 'driver' => 'Dan']);
check(str_contains($all3, 'C-1') && !str_contains($all3, 'Carrier / Driver'), 'with a carrier and a driver chosen the Carrier / Driver column is not needed');

check(str_contains($page([]), 'Choose a carrier, a driver or a company.'), 'with nothing chosen it asks for one');
check(str_contains($page(['company' => 'Nobody']), 'No jobs match'), 'a company with no jobs shows an empty statement');
check(!str_contains(call('GET', '/jobs/statement', [], ['go' => '1', 'company' => '<script>x</script>'])->body(), '<script>x</script>'), 'a company typed as markup is never output raw');

$csv = call('GET', '/jobs/statement', [], ['go' => '1', 'company' => 'Prime', 'format' => 'csv']);
$text = $csv->content();
check(str_contains($text, 'Remarks,Company,Carrier,Driver') && str_contains($text, ',Prime,KEN,Dan') && str_contains($text, ',Prime,DINOCA,Eve') && str_contains($text, '250.00'), 'the CSV has Company, Carrier and Driver columns and the same total');
check(str_contains((string) $csv->getHeader('Content-Disposition'), 'Prime'), 'the file name carries the company');
