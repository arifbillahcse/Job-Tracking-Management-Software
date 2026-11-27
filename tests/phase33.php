<?php

declare(strict_types=1);

/**
 * v1.15.1: the statement says who owes whom (Carrier owes company / Company owes carrier / Settled). Included by tests/run.php (after phase32.php).
 */

use App\Core\Clock;
use App\Services\CarrierStatement;

section('Statement: who owes whom');
check(CarrierStatement::direction('25.74') === ['text' => 'Carrier owes company', 'amount' => '25.74', 'code' => 'carrier_owes'], 'a positive Total Due is owed by the carrier');
check(CarrierStatement::direction('-3251.22') === ['text' => 'Company owes carrier', 'amount' => '3251.22', 'code' => 'company_owes'], 'a negative Total Due is owed by the company, shown without the minus');
check(CarrierStatement::direction('0.00') === ['text' => 'Settled, nothing due', 'amount' => '0.00', 'code' => 'settled'] && CarrierStatement::direction('-0.00')['code'] === 'settled', 'zero is settled');
check(CarrierStatement::direction('25.74', false)['text'] === 'Carrier owes company (not final)', 'an unfinished statement says so');

fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$admin = make_user('Admin', 'p33-admin@example.com');
sign_in($admin);
$mk = static fn (string $n, array $o = []): int => mk_job($o + ['job_number' => $n, 'status' => 'in_transit', 'carrier_name' => 'POS', 'driver_name' => 'Dan', 'cf' => '900', 'carrier_rate' => '2.50', 'balance' => '2275.74', 'loaded_date' => '2026-11-05']);
$mk('W-1');                                                                                  // 2275.74 - 2250 = 25.74
$mk('W-2', ['carrier_name' => 'NEG', 'cf' => '2300', 'balance' => '2498.78']);               // 2498.78 - 5750 = -3251.22
$mk('W-3', ['carrier_name' => 'ZERO', 'cf' => '100', 'carrier_rate' => '2.00', 'balance' => '200.00']); // 200 - 200 = 0
$mk('W-4', ['carrier_name' => 'LATE', 'carrier_rate' => null]);                              // no rate: not final
$page = static fn (string $carrier, array $q = []) => plain(call('GET', '/jobs/statement', [], $q + ['go' => '1', 'carrier' => $carrier])->body());

$pos = $page('POS');
check(str_contains($pos, 'TOTAL DUE $25.74') && str_contains($pos, 'Carrier owes company $25.74'), 'the summary box says the carrier owes the company $25.74');
check(str_contains($pos, 'Total due: $25.74 — Carrier owes company') && !str_contains($pos, '(Carrier owes company'), 'the header says it too, without brackets inside brackets');
$neg = $page('NEG');
check(str_contains($neg, 'TOTAL DUE -$3,251.22') && str_contains($neg, 'Company owes carrier $3,251.22'), 'the client\'s example -3,251.22 says the company owes the carrier $3,251.22');
$zero = $page('ZERO');
check(str_contains($zero, 'Settled, nothing due') && !str_contains($zero, 'Settled, nothing due $'), 'zero says settled, with no amount');
$late = $page('LATE');
check(str_contains($late, '(not final)') && str_contains($late, 'Total Due is not final'), 'a statement with a job that has no rate says "(not final)"');
check(str_contains($page('POS', ['labor' => '30']), 'TOTAL DUE -$4.26') && str_contains($page('POS', ['labor' => '30']), 'Company owes carrier $4.26'), 'Labor / Pads / Paid change the direction too (25.74 - 30 = -4.26)');

$csv = static fn (string $carrier): string => call('GET', '/jobs/statement', [], ['go' => '1', 'carrier' => $carrier, 'format' => 'csv'])->content();
check(str_contains($csv('POS'), '"Carrier owes company",') && str_contains($csv('POS'), ',25.74,'), 'the CSV has the line with the amount');
check(str_contains($csv('NEG'), '"Company owes carrier",') && str_contains($csv('NEG'), ',3251.22,'), 'the CSV amount has no minus');
check(str_contains($csv('ZERO'), '"Settled, nothing due",'), 'the CSV says settled for zero');
check(str_contains($csv('LATE'), '(not final)'), 'the CSV says not final');
