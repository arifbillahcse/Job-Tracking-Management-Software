<?php

declare(strict_types=1);

/**
 * v1.11.0: the carrier / driver statement (CF Pay = rate x actual CF, Total Due = Bal. Due total - CF Pay).
 * Included by tests/run.php (after phase27.php).
 */

use App\Core\Clock;
use App\Services\CarrierStatement;

section('Statement: date input');
check(CarrierStatement::parseDate('2026-02-30', 'From date')['error'] !== null && CarrierStatement::parseDate('11/05/2026', 'From date')['error'] !== null, 'impossible and oddly written dates are refused');
check(CarrierStatement::parseDate('', 'To date') === ['value' => '', 'error' => null], 'an empty date means no limit');
check(CarrierStatement::parseDate(['x'], 'To date')['value'] === '', 'a date that is not text is ignored');

section('Statement: the sheet');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$admin = make_user('Admin', 'p28-admin@example.com');
sign_in($admin);
$mk = static fn (string $n, array $o = []): int => mk_job($o + ['job_number' => $n, 'status' => 'in_transit', 'carrier_name' => 'KEN', 'driver_name' => 'Dan', 'cf' => '900', 'carrier_rate' => '2.50', 'balance' => '2275.74', 'customer_phone' => '555-0100', 'notes' => 'fragile', 'loaded_date' => '2026-11-05', 'pickup_date' => '2026-11-04']);
$mk('S-1');
$mk('S-2', ['cf' => '450.5', 'carrier_rate' => '3.00', 'balance' => '100.00', 'status' => 'delivered', 'loaded_date' => '2026-10-20', 'pickup_date' => '2026-10-19']);
$mk('S-3', ['status' => 'staged', 'cf' => null, 'balance' => '50.00', 'loaded_date' => null, 'pickup_date' => '2026-11-12', 'driver_name' => 'Eve']);
$mk('S-8', ['cf' => '100', 'carrier_rate' => null, 'balance' => '10.00', 'driver_name' => 'Fay']);
$mk('S-4', ['carrier_name' => 'DINOCA', 'balance' => '999.00']);
$mk('S-5', ['status' => 'booked', 'balance' => '777.00']);
$gone = $mk('S-6', ['balance' => '888.00']);
(new App\Repositories\JobRepository())->softDelete($gone, null);

$page = static fn (array $q) => call('GET', '/jobs/statement', [], $q + ['go' => '1']);
$body = static fn (array $q): string => plain($page($q)->body());

$one = $body(['carrier' => 'KEN']);
check(str_contains($one, 'S-1') && str_contains($one, 'S-2') && str_contains($one, 'S-3') && str_contains($one, 'S-8') && !str_contains($one, 'S-4') && !str_contains($one, 'S-5') && !str_contains($one, 'S-6'), 'the carrier\'s jobs in Stage, In Transit and Delivered are listed; other carriers, booked and deleted jobs are not');
check(str_contains($one, 'Total jobs: 4'), 'the job count is shown');
// S-1: 900 x 2.50 = 2250.00 ; S-2: 450.5 x 3.00 = 1351.50 (each job has its own rate) ; S-3 no CF ; S-8 no rate
check(str_contains($one, '$2,250.00') && str_contains($one, '$1,351.50') && str_contains($one, '$2.50') && str_contains($one, '$3.00'), 'CF Pay = the job\'s own rate x actual CF, and each row shows its own rate');
// fee total 3601.50 ; balances 2275.74 + 100 + 50 + 10 = 2435.74 ; due = -1165.76
check(str_contains($one, 'TOTALS') && str_contains($one, '$3,601.50') && str_contains($one, '$2,435.74'), 'the totals add the CF Pay and the Bal. Due');
check(str_contains($one, 'TOTAL DUE -$1,165.76'), 'Total Due = Bal. Due total - CF Pay total (a negative one shows as -$1,165.76)');
check(str_contains($one, '1 job has no carrier rate.') && str_contains($one, '1 job has no actual CF.') && str_contains($one, 'Total Due is not final'), 'a job with no rate and a job with no actual CF are flagged, and Total Due is called not final');
check(!str_contains($one, 'Original') && !str_contains($one, 'name="rate"'), 'there is no Original Bal. column and no fixed rate box');

$narrow = $body(['carrier' => 'KEN', 'driver' => 'Dan', 'from' => '2026-11-01', 'to' => '2026-11-30']);
check(str_contains($narrow, 'S-1') && !str_contains($narrow, 'S-2') && !str_contains($narrow, 'S-3') && !str_contains($narrow, 'S-8'), 'carrier + driver + dates narrow it down (the loaded date, or the pickup date before loading)');

mk_job(['job_number' => 'S-7', 'status' => 'delivered', 'carrier_name' => 'ONE', 'driver_name' => 'Zed', 'cf' => '900', 'carrier_rate' => '2.50', 'balance' => '2275.74', 'first_available_date' => '2026-11-01', 'loaded_date' => '2026-11-05']);
$ex = $body(['carrier' => 'ONE']);
check(str_contains($ex, 'TOTAL DUE $25.74'), 'the client\'s own sample: $2,275.74 - 900 CF x $2.50 = $25.74');

$byDriver = $body(['driver' => 'Eve']);
check(str_contains($byDriver, 'S-3') && !str_contains($byDriver, 'S-1'), 'a statement can be for a driver alone');

section('Statement: refusals and permissions');
check(str_contains($body([]), 'Choose a carrier, a driver or a company.'), 'with no carrier and no driver it asks for one');
check(str_contains($body(['carrier' => 'KEN', 'from' => '2026-12-01', 'to' => '2026-11-01']), 'From date is after the To date'), 'dates the wrong way round are refused');
check(!str_contains(plain(call('GET', '/jobs/statement')->body()), 'Total jobs'), 'the page without a request shows only the form');
check(str_contains(call('GET', '/jobs/statement', [], ['go' => '1', 'carrier' => '<script>x</script>'])->body(), '&lt;script&gt;') || !str_contains(call('GET', '/jobs/statement', [], ['go' => '1', 'carrier' => '<script>x</script>'])->body(), '<script>x</script>'), 'a carrier typed as markup is never output raw');

$csv = call('GET', '/jobs/statement', [], ['go' => '1', 'carrier' => 'KEN', 'format' => 'csv']);
$text = $csv->content();
check(str_contains((string) $csv->getHeader('Content-Type'), 'text/csv') && str_contains($text, '"Job #",Status,Customer') && str_contains($text, 'S-1') && str_contains($text, '-1165.76') && str_contains($text, ',3.00,1351.50,'), 'the CSV has the same rows and a negative total as a plain number');
check((int) \App\Core\Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'statement_exported'") === 1, 'a CSV download is audited');

$staff = make_user('Staff', 'p28-staff@example.com');
sign_in($staff);
check($page(['carrier' => 'KEN'])->status() === 403, 'Staff cannot see money, so the statement is forbidden');
check(!str_contains(call('GET', '/dashboard')->body(), 'Carrier Statement'), 'and the menu does not show it');
sign_in($admin);
check(str_contains(call('GET', '/dashboard')->body(), 'Carrier Statement'), 'the menu shows Carrier Statement to people who may see money');
