<?php

declare(strict_types=1);

/**
 * v1.15.0 (print phase 3): Labor, Pads and Paid on the statement (all optional, default 0).
 * Total Due = Subtotal - CF Pay - Labor - Pads + Paid. Included by tests/run.php (after phase31.php).
 */

use App\Core\Clock;
use App\Services\CarrierStatement;

section('Statement: Labor, Pads and Paid');
foreach ([['', 0, ''], ['0', 0, '0'], ['25', 2500, '25'], ['25.5', 2550, '25.5'], ['-100', -10000, '-100'], [' 12.34 ', 1234, '12.34'], ['9999999.99', 999999999, '9999999.99']] as [$in, $cents, $value]) {
    $r = CarrierStatement::parseMoney($in, 'Labor cost');
    check($r['error'] === null && $r['cents'] === $cents, 'amount "' . $in . '" is accepted as ' . $cents . ' cents');
}
foreach (['abc', '1.234', '10000000', '--5', '1e3', '$5', '5,00', '+5'] as $bad) {
    check(CarrierStatement::parseMoney($bad, 'Pads')['error'] !== null, 'amount "' . $bad . '" is refused');
}
check(CarrierStatement::parseMoney(['5'], 'Paid')['error'] === null && CarrierStatement::parseMoney(['5'], 'Paid')['cents'] === 0, 'something that is not text counts as empty');

fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$admin = make_user('Admin', 'p32-admin@example.com');
sign_in($admin);
$mk = static fn (string $n, array $o = []): int => mk_job($o + ['job_number' => $n, 'status' => 'in_transit', 'carrier_name' => 'KEN', 'driver_name' => 'Dan', 'cf' => '900', 'carrier_rate' => '2.50', 'balance' => '2275.74', 'loaded_date' => '2026-11-05']);
$mk('L-1');
$page = static fn (array $q) => plain(call('GET', '/jobs/statement', [], $q + ['go' => '1', 'carrier' => 'KEN'])->body());

$none = $page([]);
check(str_contains($none, 'TOTAL DUE $25.74') && !str_contains($none, 'Labor cost −') && !str_contains($none, 'Pads −') && !str_contains($none, 'Paid +'), 'with nothing typed the sheet is as before (2,275.74 - 2,250 = 25.74) and no extra rows show');
check(str_contains(call('GET', '/jobs/statement')->body(), 'name="labor"') && str_contains(call('GET', '/jobs/statement')->body(), 'name="pads"') && str_contains(call('GET', '/jobs/statement')->body(), 'name="paid"'), 'the form has Labor cost, Pads and Paid');

$lab = $page(['labor' => '10', 'pads' => '5.50']);
check(str_contains($lab, 'Labor cost − $10.00') && str_contains($lab, 'Pads − $5.50') && str_contains($lab, 'TOTAL DUE $10.24'), 'Labor and Pads are subtracted: 25.74 - 10 - 5.50 = 10.24');
$paid = $page(['paid' => '4']);
check(str_contains($paid, 'Paid + $4.00') && str_contains($paid, 'TOTAL DUE $29.74'), 'Paid is added: 25.74 + 4 = 29.74');
$neg = $page(['paid' => '-20']);
check(str_contains($neg, 'Paid − $20.00') && str_contains($neg, 'TOTAL DUE $5.74'), 'a negative Paid takes money off: 25.74 - 20 = 5.74');
$all = $page(['labor' => '10', 'pads' => '5', 'paid' => '-3']);
check(str_contains($all, 'TOTAL DUE $7.74'), 'all three together: 25.74 - 10 - 5 - 3 = 7.74');
$cred = $page(['labor' => '100']);
check(str_contains($cred, 'TOTAL DUE -$74.26'), 'Total Due can go negative (shown with a minus)');
$raw = call('GET', '/jobs/statement', [], ['go' => '1', 'carrier' => 'KEN', 'labor' => '10'])->body();
check(preg_match('~name="labor"[^>]*value="10"|value="10"[^>]*name="labor"~', $raw) === 1, 'the typed amount stays in the box');

foreach (['labor' => 'Labor cost', 'pads' => 'Pads', 'paid' => 'Paid'] as $field => $label) {
    $bad = $page([$field => 'abc']);
    check(str_contains($bad, $label . ' must be an amount') && !str_contains($bad, 'TOTAL DUE'), $label . ' "abc" is refused and no sheet is shown');
}
check(!str_contains(call('GET', '/jobs/statement', [], ['go' => '1', 'carrier' => 'KEN', 'labor' => '<script>x</script>'])->body(), '<script>x</script>'), 'an amount typed as markup is never output raw');

$csv = call('GET', '/jobs/statement', [], ['go' => '1', 'carrier' => 'KEN', 'labor' => '10', 'pads' => '5', 'paid' => '-3', 'format' => 'csv'])->content();
check(str_contains($csv, 'Labor cost (subtracted)') && str_contains($csv, 'Pads (subtracted)') && str_contains($csv, 'Paid (added; a minus takes money off)') && str_contains($csv, '"TOTAL DUE",') && str_contains($csv, ',7.74,'), 'the CSV has the three lines and the new Total Due');
$csv0 = call('GET', '/jobs/statement', [], ['go' => '1', 'carrier' => 'KEN', 'format' => 'csv'])->content();
check(!str_contains($csv0, 'Labor cost (subtracted)') && str_contains($csv0, '25.74'), 'with nothing typed the CSV has no extra lines');
check(str_contains($raw, 'labor=10') && str_contains($raw, 'format=csv'), 'the CSV link keeps the typed amounts');
