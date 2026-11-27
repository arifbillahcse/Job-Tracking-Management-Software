<?php

declare(strict_types=1);

/**
 * v1.16.0 (invoice phase 4a): the Carrier Statement saved as a numbered invoice (DI-N): snapshot, one open invoice per job,
 * paid / open / void, edit, list, permissions. Included by tests/run.php (after phase33.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Services\InvoiceService;

section('Saved invoices');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$admin = make_user('Admin', 'p34-admin@example.com');
sign_in($admin);

$mk = static fn (string $n, array $o = []): int => mk_job($o + ['job_number' => $n, 'status' => 'in_transit', 'carrier_name' => 'KEN', 'driver_name' => 'Dan', 'driver_phone' => '973-718-0688', 'broker' => 'Prime', 'cf' => '900', 'carrier_rate' => '2.50', 'balance' => '2275.74', 'customer_phone' => '555-0100', 'notes' => 'fragile', 'loaded_date' => '2026-11-05']);
$j1 = $mk('I-1');                                                        // fee 2250.00, balance 2275.74
$j2 = $mk('I-2', ['cf' => '100', 'carrier_rate' => '2.00', 'balance' => '500.00']); // fee 200.00, balance 500.00
$mk('I-3', ['carrier_name' => 'OTHER', 'driver_name' => 'Zed']);
$mk('I-4', ['carrier_name' => 'INC', 'driver_name' => 'Ivy', 'carrier_rate' => null]);
$save = static fn (array $q) => call('POST', '/invoices', $q + ['carrier' => 'KEN']);
$invoiceId = static fn (string $number): int => (int) Database::fetchValue('SELECT id FROM invoices WHERE type = ? AND seq = ?', [substr($number, 0, 2), (int) substr($number, 3)]);
$count = static fn (): int => (int) Database::fetchValue('SELECT COUNT(*) FROM invoices');

// 1. refusals
$r = call('POST', '/invoices', ['company' => 'Prime']);
check($count() === 0 && str_contains(plain(call('GET', '/jobs/statement', [], ['go' => '1', 'company' => 'Prime'])->body()), 'choose a carrier or a driver'), 'a company-only statement cannot be saved yet (the page says to choose a carrier or driver)');
call('POST', '/invoices', ['carrier' => 'NOBODY']);
check($count() === 0, 'a carrier with no jobs saves nothing');
call('POST', '/invoices', ['carrier' => 'INC']);
check($count() === 0, 'jobs without a rate are refused (an invoice must not hold an unknown CF Pay)');
check(str_contains(plain(call('GET', '/jobs/statement', [], ['go' => '1', 'carrier' => 'INC'])->body()), 'Save as an invoice'), 'the statement page shows the Save box');
call('POST', '/invoices', ['carrier' => 'KEN', 'labor' => 'abc']);
check($count() === 0, 'a bad amount is refused and nothing is saved');
call('POST', '/invoices', ['carrier' => 'KEN', 'invoice_date' => '2026-13-45']);
check($count() === 0, 'a bad invoice date is refused');
call('POST', '/invoices', ['carrier' => 'KEN', 'remarks' => str_repeat('x', 2001)]);
check($count() === 0, 'remarks over 2000 characters are refused');

// 2. save
$save(['labor' => '10', 'pads' => '5', 'paid' => '-3', 'remarks' => 'Weekly <b>run</b>', 'invoice_date' => '2026-11-16']);
$id1 = $invoiceId('DI-1');
check($count() === 1 && $id1 > 0, 'saving creates invoice DI-1');
$inv = InvoiceService::find($id1);
check($inv['carrier'] === 'KEN' && $inv['type'] === 'DI' && (int) $inv['seq'] === 1 && $inv['driver'] === null && $inv['invoice_date'] === '2026-11-16', 'the invoice holds the carrier and the date');
// subtotal 2775.74 ; fee 2450.00 ; labor 10 ; pads 5 ; paid -3 ; total 2775.74 - 2450 - 10 - 5 - 3 = 307.74
check((float) $inv['subtotal'] === 2775.74 && (float) $inv['carrier_fee'] === 2450.0 && (float) $inv['labor'] === 10.0 && (float) $inv['pads'] === 5.0 && (float) $inv['paid_amount'] === -3.0 && (float) $inv['total'] === 307.74 && (float) $inv['total_cf'] === 1000.0, 'subtotal, CF Pay, labor, pads, paid and total are worked out on the server');
$lines = InvoiceService::lines($id1);
check(count($lines) === 2 && $lines[0]['job_number'] === 'I-1' && $lines[1]['job_number'] === 'I-2' && (float) $lines[0]['fee'] === 2250.0 && (float) $lines[1]['rate'] === 2.0 && $lines[0]['broker'] === 'Prime' && $lines[0]['driver'] === 'Dan', 'each job is saved as a line with its rate, fee, balance, carrier and driver');
$page = plain(call('GET', "/invoices/{$id1}")->body());
check(str_contains($page, 'Invoice #: DI-1') && str_contains($page, 'Driver Invoice') && str_contains($page, 'TOTAL DUE $307.74') && str_contains($page, 'Carrier owes company $307.74') && str_contains($page, 'Labor cost − $10.00') && str_contains($page, 'Paid − $3.00'), 'the invoice page shows the number, the totals and who owes whom');
check(str_contains(call('GET', "/invoices/{$id1}")->body(), 'Weekly &lt;b&gt;run&lt;/b&gt;') && !str_contains(call('GET', "/invoices/{$id1}")->body(), 'Weekly <b>run</b>'), 'remarks are escaped');
check(Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'invoice_created'") == 1, 'saving is audited');

// 3. a snapshot never changes
Database::execute('UPDATE jobs SET balance = 1, cf = 1, carrier_rate = 99 WHERE id = ?', [$j1]);
$again = InvoiceService::lines($id1);
check((float) $again[0]['balance'] === 2275.74 && (float) $again[0]['cf'] === 900.0 && (float) $again[0]['fee'] === 2250.0 && (float) InvoiceService::find($id1)['total'] === 307.74, 'editing the job afterwards does not change the invoice');
Database::execute('UPDATE jobs SET balance = 2275.74, cf = 900, carrier_rate = 2.50 WHERE id = ?', [$j1]);

// 4. one open invoice per job
$st = plain(call('GET', '/jobs/statement', [], ['go' => '1', 'carrier' => 'KEN'])->body());
check(str_contains($st, 'On DI-1') && str_contains($st, '2 jobs are already on a saved invoice'), 'the statement marks jobs that are already on an invoice');
$save([]);
check($count() === 1, 'saving again leaves the invoiced jobs out, so with nothing left no invoice is made');
$save(['include_invoiced' => '1']);
check($count() === 2 && InvoiceService::find($invoiceId('DI-2')) !== null && count(InvoiceService::lines($invoiceId('DI-2'))) === 2, 'ticking "also include" invoices them again (DI-2)');
$j5 = $mk('I-5', ['cf' => '10', 'carrier_rate' => '3.00', 'balance' => '40.00']);
$save([]);
$id3 = $invoiceId('DI-3');
check($count() === 3 && count(InvoiceService::lines($id3)) === 1 && InvoiceService::lines($id3)[0]['job_number'] === 'I-5' && (float) InvoiceService::find($id3)['total'] === 10.0, 'a new job alone makes DI-3 (40 - 30 = 10)');
check(InvoiceService::numbersForJobs([$j1, $j2, $j5]) === [$j1 => ['DI-1', 'DI-2'], $j2 => ['DI-1', 'DI-2'], $j5 => ['DI-3']], 'each job knows which invoices hold it');

// 5. paid, open, void
$id2 = $invoiceId('DI-2');
$r = call('POST', "/invoices/{$id2}/paid", ['paid_on' => '2026-12-31']);
check(InvoiceService::status(InvoiceService::find($id2)) === 'open', 'a paid date in the future is refused');
call('POST', "/invoices/{$id2}/paid", ['paid_on' => '2026-11-15']);
check(InvoiceService::status(InvoiceService::find($id2)) === 'paid' && InvoiceService::find($id2)['paid_on'] === '2026-11-15', 'an invoice can be marked paid on a date');
call('POST', "/invoices/{$id2}/void", ['reason' => 'mistake']);
check(InvoiceService::status(InvoiceService::find($id2)) === 'paid', 'a paid invoice cannot be voided');
call('POST', "/invoices/{$id2}/open");
check(InvoiceService::status(InvoiceService::find($id2)) === 'open' && InvoiceService::find($id2)['paid_on'] === null, 'and can be marked not paid again');
call('POST', "/invoices/{$id2}/void", ['reason' => '']);
check(InvoiceService::status(InvoiceService::find($id2)) === 'open', 'voiding needs a reason');
call('POST', "/invoices/{$id2}/void", ['reason' => 'made twice by mistake']);
check(InvoiceService::status(InvoiceService::find($id2)) === 'void', 'an invoice can be voided with a reason');
check(InvoiceService::numbersForJobs([$j1, $j2]) === [$j1 => ['DI-1'], $j2 => ['DI-1']], 'a voided invoice no longer holds its jobs');
call('POST', "/invoices/{$id2}/paid", ['paid_on' => '2026-11-15']);
call('POST', "/invoices/{$id2}", ['invoice_date' => '2026-11-01', 'labor' => '1']);
check(InvoiceService::status(InvoiceService::find($id2)) === 'void' && (float) InvoiceService::find($id2)['labor'] === 0.0, 'a voided invoice cannot be paid or changed');
call('POST', '/invoices', ['carrier' => 'KEN', 'include_invoiced' => '1']);
check($invoiceId('DI-4') > 0 && (int) Database::fetchValue("SELECT MAX(seq) FROM invoices") === 4, 'the number of a voided invoice is never used again (the next is DI-4)');
check(str_contains(plain(call('GET', "/invoices/{$id2}")->body()), 'This invoice was voided') && str_contains(plain(call('GET', "/invoices/{$id2}")->body()), 'made twice by mistake'), 'the voided invoice says why');

// 6. change an invoice
call('POST', "/invoices/{$id1}", ['invoice_date' => '2026-11-17', 'labor' => '20', 'pads' => '', 'paid_amount' => '0', 'remarks' => 'Corrected']);
$ch = InvoiceService::find($id1);
check($ch['invoice_date'] === '2026-11-17' && (float) $ch['labor'] === 20.0 && (float) $ch['pads'] === 0.0 && (float) $ch['paid_amount'] === 0.0 && $ch['remarks'] === 'Corrected' && (float) $ch['total'] === 305.74, 'the date, remarks and amounts can be changed and the total is worked out again (2775.74 - 2450 - 20 = 305.74)');
check((float) InvoiceService::find($id1)['subtotal'] === 2775.74 && count(InvoiceService::lines($id1)) === 2, 'the lines are not touched');
call('POST', "/invoices/{$id1}", ['invoice_date' => '2026-11-17', 'labor' => 'x']);
check((float) InvoiceService::find($id1)['labor'] === 20.0, 'a bad amount is refused');
check(Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'invoice_updated'") == 1 && Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'invoice_voided'") == 1 && Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'invoice_paid'") == 1, 'changes, payments and voids are audited');

// 7. the list
$list = plain(call('GET', '/invoices')->body());
check(str_contains($list, 'DI-1') && str_contains($list, 'DI-2') && str_contains($list, 'DI-3') && str_contains($list, 'DI-4') && str_contains($list, 'Invoices: 4'), 'the list shows every invoice');
$open = plain(call('GET', '/invoices', [], ['status' => 'open'])->body());
check(str_contains($open, 'DI-1') && !str_contains($open, 'DI-2 ') && str_contains($open, 'Invoices: 3'), 'the Open filter hides the voided one');
check(str_contains(plain(call('GET', '/invoices', [], ['status' => 'void'])->body()), 'Invoices: 1'), 'the Void filter shows only voided invoices');
check(str_contains(plain(call('GET', '/invoices', [], ['q' => 'DI-3'])->body()), 'Invoices: 1') && str_contains(plain(call('GET', '/invoices', [], ['q' => 'di3'])->body()), 'Invoices: 1'), 'searching by number finds one invoice');
check(str_contains(plain(call('GET', '/invoices', [], ['q' => 'ken'])->body()), 'Invoices: 4') && str_contains(plain(call('GET', '/invoices', [], ['q' => 'nobody'])->body()), 'No invoices yet'), 'searching by name works, ignoring letter case');
check(str_contains(plain(call('GET', '/invoices', [], ['from' => '2026-11-17', 'to' => '2026-11-17'])->body()), 'Invoices: 1'), 'the date filter works');
check(str_contains(plain(call('GET', '/invoices', [], ['q' => '<script>x</script>'])->body()), 'No invoices yet') && !str_contains(call('GET', '/invoices', [], ['q' => '<script>x</script>'])->body(), '<script>x</script>'), 'a search typed as markup is never output raw');

// 8. who may
$staff = make_user('Staff', 'p34-staff@example.com');
sign_in($staff);
check(call('GET', '/invoices')->status() === 403 && call('GET', "/invoices/{$id1}")->status() === 403 && call('POST', '/invoices', ['carrier' => 'KEN'])->status() === 403 && call('POST', "/invoices/{$id1}/void", ['reason' => 'x'])->status() === 403, 'Staff cannot see or change invoices');
check(!str_contains(call('GET', '/dashboard')->body(), 'href="/invoices"'), 'and the menu does not list Invoices for Staff');
$manager = make_user('Manager', 'p34-mgr@example.com');
sign_in($manager);
check(call('GET', '/invoices')->status() === 200 && call('GET', "/invoices/{$id1}")->status() === 200, 'a Manager can see invoices');
check(str_contains(call('GET', '/dashboard')->body(), 'href="/invoices"'), 'and the menu lists Invoices');
sign_in($admin);
check(call('GET', '/invoices/999999')->status() === 404, 'an invoice that does not exist is a 404');
