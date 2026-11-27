<?php

declare(strict_types=1);

/**
 * v1.17.0 (invoice phase 4b): company invoices (CI): a company-only statement is saved as a CI, a job can be on one open DI and one
 * open CI, and the company invoices of a driver invoice can be generated from it. Included by tests/run.php (after phase34.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Services\InvoiceService;

section('Company invoices');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$admin = make_user('Admin', 'p35-admin@example.com');
sign_in($admin);

$mk = static fn (string $n, array $o = []): int => mk_job($o + ['job_number' => $n, 'status' => 'in_transit', 'carrier_name' => 'KEN', 'driver_name' => 'Dan', 'broker' => 'Prime', 'cf' => '100', 'carrier_rate' => '2.00', 'balance' => '500.00', 'loaded_date' => '2026-11-05']);
$k1 = $mk('K-1');                                                                                  // fee 200, bal 500
$k2 = $mk('K-2', ['cf' => '50', 'carrier_rate' => '3.00', 'balance' => '100.00']);                 // fee 150, bal 100
$k3 = $mk('K-3', ['broker' => 'Next Level', 'cf' => '10', 'carrier_rate' => '5.00', 'balance' => '80.00']); // fee 50, bal 80
$k4 = $mk('K-4', ['broker' => null, 'cf' => '10', 'carrier_rate' => '1.00', 'balance' => '20.00']);          // fee 10, bal 20
$k5 = $mk('K-5', ['carrier_name' => 'OTHER', 'driver_name' => 'Zed', 'cf' => '20', 'carrier_rate' => '2.00', 'balance' => '100.00']); // Prime, fee 40, bal 100
$byNumber = static function (string $number): ?array {
    return Database::fetchOne('SELECT * FROM invoices WHERE type = ? AND seq = ?', [substr($number, 0, 2), (int) substr($number, 3)]);
};
$jobsOf = static function (string $number) use ($byNumber): array {
    $inv = $byNumber($number);
    return $inv === null ? [] : array_column(InvoiceService::lines((int) $inv['id']), 'job_number');
};

check(InvoiceService::typeFor('KEN', '', '') === 'DI' && InvoiceService::typeFor('', 'Dan', 'Prime') === 'DI' && InvoiceService::typeFor('', '', 'Prime') === 'CI' && InvoiceService::typeFor('', '', '') === '', 'a carrier or driver statement is a driver invoice, a company-only one a company invoice');

// 1. the driver invoice first (carrier KEN: K-1 .. K-4)
call('POST', '/invoices', ['carrier' => 'KEN', 'remarks' => 'Week 46']);
$di = $byNumber('DI-1');
check($di !== null && $jobsOf('DI-1') === ['K-1', 'K-2', 'K-3', 'K-4'], 'DI-1 holds the four jobs of carrier KEN');

// 2. a company-only statement is saved as a company invoice, and the jobs already on the DI are not blocked
check(str_contains(plain(call('GET', '/jobs/statement', [], ['go' => '1', 'company' => 'Prime'])->body()), 'company invoice (CI-1, CI-2, ...)'), 'the statement page offers to save a company statement as a company invoice');
call('POST', '/invoices', ['company' => 'Prime', 'invoice_date' => '2026-11-16']);
$ci1 = $byNumber('CI-1');
check($ci1 !== null && $ci1['type'] === 'CI' && $ci1['broker'] === 'Prime' && $ci1['carrier'] === null && $jobsOf('CI-1') === ['K-1', 'K-2', 'K-5'], 'a company statement makes CI-1 with that company\'s jobs from every carrier (K-1, K-2 are also on DI-1, which is allowed)');
// subtotal 500 + 100 + 100 = 700 ; fee 200 + 150 + 40 = 390 ; total 310
check((float) $ci1['subtotal'] === 700.0 && (float) $ci1['carrier_fee'] === 390.0 && (float) $ci1['total'] === 310.0, 'the company invoice uses the same hishab (700 - 390 = 310)');
$page = plain(call('GET', '/invoices/' . $ci1['id'])->body());
check(str_contains($page, 'Company Invoice') && str_contains($page, 'Invoice #: CI-1') && str_contains($page, 'TOTAL DUE $310.00') && !str_contains($page, 'Generate company invoices'), 'the CI page says Company Invoice, shows its total and has no Generate button');
$st = plain(call('GET', '/jobs/statement', [], ['go' => '1', 'company' => 'Prime'])->body());
check(str_contains($st, 'On DI-1, CI-1') && str_contains($st, '3 jobs are already on a saved invoice'), 'the statement marks jobs with both numbers, and counts the ones already on a company invoice');
call('POST', '/invoices', ['company' => 'Prime']);
check(InvoiceService::search([], 100, 0)['total'] === 2, 'saving the company statement again leaves its jobs out, so nothing new is made');
$st2 = Database::fetchValue("SELECT COUNT(*) FROM invoices WHERE type = 'CI'");
check((int) $st2 === 1, 'there is still one company invoice');
call('POST', '/invoices', ['company' => 'Prime', 'carrier' => 'KEN']);
check($byNumber('DI-2') === null && (int) Database::fetchValue("SELECT COUNT(*) FROM invoices WHERE type = 'DI'") === 1, 'company + carrier is a driver invoice, and its jobs are already on DI-1 so it is refused');

// 3. generate the company invoices from DI-1
$diId = (int) $di['id'];
$r = call('POST', "/invoices/{$diId}/company-invoices");
$ci2 = $byNumber('CI-2');
$ci3 = $byNumber('CI-3');
check($ci2 !== null && $ci3 !== null && $byNumber('CI-4') === null, 'generating from DI-1 makes one company invoice per company (CI-2, CI-3); the job with no company is left out');
check($jobsOf('CI-2') === ['K-1', 'K-2'] && $ci2['broker'] === 'Prime' && $jobsOf('CI-3') === ['K-3'] && $ci3['broker'] === 'Next Level', 'each company invoice holds that company\'s lines from the driver invoice');
// CI-2: bal 600, fee 350 -> 250 ; CI-3: bal 80, fee 50 -> 30
check((float) $ci2['total'] === 250.0 && (float) $ci3['total'] === 30.0 && (float) $ci2['labor'] === 0.0 && (float) $ci2['pads'] === 0.0 && (float) $ci2['paid_amount'] === 0.0, 'their totals are worked out from the saved lines (250 and 30), with Labor, Pads and Paid at 0');
check((int) $ci2['source_invoice_id'] === $diId && $ci2['remarks'] === 'Week 46' && $ci2['invoice_date'] === $di['invoice_date'] && $ci2['carrier'] === 'KEN' && $ci2['driver'] === null, 'they remember the driver invoice, and copy its remarks, date and carrier (DI-1 was for the carrier, so no driver)');
$diPage = plain(call('GET', "/invoices/{$diId}")->body());
check(str_contains($diPage, 'CI-2') && str_contains($diPage, 'CI-3') && str_contains($diPage, 'To make them again, void these company invoices first.') && !str_contains($diPage, 'Generate company invoices'), 'the driver invoice lists its company invoices and no longer offers to generate them');
check(str_contains(plain(call('GET', '/invoices/' . $ci2['id'])->body()), 'Made from DI-1'), 'a generated company invoice links back to its driver invoice');
check((int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'invoice_companies_made'") === 1 && (int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'invoice_created'") === 4, 'the generation is audited, and each company invoice is audited as created');

// 4. not twice, until they are voided
call('POST', "/invoices/{$diId}/company-invoices");
check($byNumber('CI-4') === null, 'generating again while the company invoices are open is refused');
call('POST', '/invoices/' . $ci2['id'] . '/void', ['reason' => 'redo']);
call('POST', "/invoices/{$diId}/company-invoices");
check($byNumber('CI-4') === null, '...also while one of them is still open');
call('POST', '/invoices/' . $ci3['id'] . '/void', ['reason' => 'redo']);
call('POST', "/invoices/{$diId}/company-invoices");
check($byNumber('CI-4') !== null && $byNumber('CI-5') !== null && $byNumber('CI-6') === null, 'once they are all voided it can be done again, with new numbers (CI-4, CI-5)');

// 5. other refusals
$none = $mk('K-6', ['carrier_name' => 'NOCO', 'driver_name' => 'Ned', 'broker' => null]);
call('POST', '/invoices', ['carrier' => 'NOCO']);
$noco = $byNumber('DI-2');
check($noco !== null, 'a driver invoice of jobs with no company is saved');
call('POST', '/invoices/' . $noco['id'] . '/company-invoices');
check((int) Database::fetchValue("SELECT COUNT(*) FROM invoices WHERE type = 'CI'") === 5, 'a driver invoice whose jobs have no company makes no company invoice');
check(str_contains(plain(call('GET', '/invoices/' . $noco['id'])->body()), 'Generate company invoices'), '...and still offers the button');
call('POST', '/invoices/' . $ci1['id'] . '/company-invoices');
check((int) Database::fetchValue("SELECT COUNT(*) FROM invoices WHERE type = 'CI'") === 5, 'a company invoice cannot be split again');
call('POST', '/invoices/' . $noco['id'] . '/void', ['reason' => 'test']);
call('POST', '/invoices/' . $noco['id'] . '/company-invoices');
check(call('POST', '/invoices/999999/company-invoices')->status() === 404, 'an invoice that does not exist is a 404');

// 6. company invoices are invoices like the others
call('POST', '/invoices/' . $ci1['id'], ['invoice_date' => '2026-11-16', 'labor' => '10', 'pads' => '', 'paid_amount' => '', 'remarks' => 'Prime run']);
check((float) $byNumber('CI-1')['total'] === 300.0, 'a company invoice can be changed (310 - 10 = 300)');
call('POST', '/invoices/' . $ci1['id'] . '/paid', ['paid_on' => '2026-11-16']);
check(InvoiceService::status($byNumber('CI-1')) === 'paid', 'and marked paid');

// 7. list and search
$all = plain(call('GET', '/invoices')->body());
check(str_contains($all, 'Company Invoice') && str_contains($all, 'Driver Invoice') && str_contains($all, 'CI-1') && str_contains($all, 'DI-1'), 'the list shows both kinds, with a Type column');
check(str_contains(plain(call('GET', '/invoices', [], ['type' => 'CI'])->body()), 'Invoices: 5') && str_contains(plain(call('GET', '/invoices', [], ['type' => 'DI'])->body()), 'Invoices: 2'), 'the Type filter separates company invoices (5) from driver invoices (2)');
check(str_contains(plain(call('GET', '/invoices', [], ['q' => 'CI-3'])->body()), 'Invoices: 1') && str_contains(plain(call('GET', '/invoices', [], ['q' => 'next level'])->body()), 'Invoices: 2'), 'searching finds a company invoice by its number, or by its company (CI-3, voided, and CI-5)');
check(str_contains(plain(call('GET', '/invoices', [], ['type' => 'XX'])->body()), 'Invoices: 7'), 'an unknown type filter is ignored');

// 8. who may
$staff = make_user('Staff', 'p35-staff@example.com');
sign_in($staff);
check(call('POST', '/invoices/' . $noco['id'] . '/company-invoices')->status() === 403 && call('POST', '/invoices', ['company' => 'Prime'])->status() === 403, 'Staff cannot make company invoices');
sign_in($admin);
