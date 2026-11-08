<?php

declare(strict_types=1);

/**
 * Client rules (v1.6.1): Total to BH = Grand total - Deposit; Balance due = Total to BH - Paid. Included by tests/run.php.
 */

use App\Core\Clock;
use App\Core\Database;
use App\Repositories\JobRepository;
use App\Services\JobService;

section('Money rules: Total to BH and Balance due');
check(JobService::deriveMoney(['grand_total' => '1000', 'deposit' => '300', 'paid' => '200'])['bh_total'] === '700.00', 'Total to BH = 1000 - 300 = 700.00');
check(JobService::deriveMoney(['grand_total' => '1000', 'deposit' => '300', 'paid' => '200'])['balance'] === '500.00', 'Balance due = 700 - 200 = 500.00');
check(JobService::deriveMoney(['grand_total' => '1000', 'deposit' => '300', 'paid' => '800'])['balance'] === '-100.00', 'a job paid 100 more than it owes shows a credit of -100.00');
check(JobService::deriveMoney(['grand_total' => '1000'])['bh_total'] === '1000.00' && JobService::deriveMoney(['grand_total' => '1000'])['balance'] === '1000.00', 'blank Deposit and Paid count as 0');
check(JobService::deriveMoney(['deposit' => '300'])['bh_total'] === '-300.00', 'blank Grand total counts as 0 once a Deposit is typed');
$kept = JobService::deriveMoney(['bh_total' => '250', 'balance' => '10', 'paid' => '50']);
check($kept['bh_total'] === '250' && $kept['balance'] === '200.00', 'with no Grand total and no Deposit a typed Total to BH is kept; the balance still follows it');
$none = JobService::deriveMoney(['balance' => '75']);
check($none['balance'] === '75', 'with nothing to calculate from, a typed balance is kept');

fresh_db();
fresh_session();
Clock::fake('2026-10-31');
$mgr = make_user('Manager', 'p17-mgr@example.com');
sign_in($mgr);
$form = call('GET', '/jobs/create')->body();
check(preg_match('~name="bh_total"[^>]*readonly~', $form) === 1 && preg_match('~name="balance"[^>]*readonly~', $form) === 1 && str_contains($form, 'Total to BH') && str_contains($form, 'Balance due') && str_contains($form, 'data-money="grand_total"'), 'the job form shows Total to BH and Balance due as calculated (read-only) boxes');
call('POST', '/jobs', job_input(['job_number' => 'P17-A', 'grand_total' => '5000', 'deposit' => '1200', 'paid' => '1800', 'bh_total' => '1', 'balance' => '2']));
$row = (new JobRepository())->find((int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'P17-A'"));
check((float) $row['bh_total'] === 3800.0 && (float) $row['balance'] === 2000.0, 'saving a job: Total to BH 5000 - 1200 = 3800.00 and Balance due 3800 - 1800 = 2000.00, whatever was posted in those two boxes');
$jid = (int) $row['id'];
call('POST', "/jobs/{$jid}", job_input(['job_number' => 'P17-A', 'grand_total' => '5000', 'deposit' => '1200', 'paid' => '3800', 'loaded_at' => (string) $row['updated_at']]));
$row = (new JobRepository())->find($jid);
check((float) $row['balance'] === 0.0 && (float) $row['bh_total'] === 3800.0, 'editing Paid to 3800 makes the balance 0.00');
check(str_contains(plain(call('GET', "/jobs/{$jid}")->body()), 'Total to BH $3,800.00'), 'Job details label it Total to BH');

// a user who may not edit money cannot change them
$stf = make_user('Staff', 'p17-stf@example.com');
sign_in($stf);
$row = (new JobRepository())->find($jid);
call('POST', "/jobs/{$jid}", job_input(['job_number' => 'P17-A', 'grand_total' => '9', 'deposit' => '1', 'paid' => '0', 'loaded_at' => (string) $row['updated_at']]));
$after = (new JobRepository())->find($jid);
check((float) $after['bh_total'] === 3800.0 && (float) $after['balance'] === 0.0 && (float) $after['grand_total'] === 5000.0, 'Staff cannot change the money; the stored figures stay');

section('Money rules: the calculated boxes can be negative and must not block saving');
sign_in($mgr);
// exactly what the browser posts for the screenshot case: Grand 0, Deposit 0.14, Paid 222 -> Total to BH -0.14, Balance due -222.14
$before = (int) Database::fetchValue('SELECT COUNT(*) FROM jobs');
call('POST', '/jobs', job_input(['job_number' => 'P17-NEG', 'grand_total' => '0', 'deposit' => '0.14', 'paid' => '222', 'bh_total' => '-0.14', 'balance' => '-222.14', 'storage_fee_override' => '34']));
$neg = (new JobRepository())->find((int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'P17-NEG'"));
check((int) Database::fetchValue('SELECT COUNT(*) FROM jobs') === $before + 1 && (float) $neg['bh_total'] === -0.14 && (float) $neg['balance'] === -222.14, 'a job whose calculated Total to BH is -0.14 and Balance due -222.14 is saved (it used to fail with "must be an amount")');
$nid = (int) $neg['id'];
call('POST', "/jobs/{$nid}", job_input(['job_number' => 'P17-NEG', 'grand_total' => '10', 'deposit' => '0.14', 'paid' => '222', 'bh_total' => '9.86', 'balance' => '-212.14', 'loaded_at' => (string) $neg['updated_at']]));
$neg = (new JobRepository())->find($nid);
check((float) $neg['bh_total'] === 9.86 && (float) $neg['balance'] === -212.14, 'the same on edit');
// a garbage value in a calculated box is ignored, not trusted
call('POST', '/jobs', job_input(['job_number' => 'P17-JUNK', 'grand_total' => '100', 'deposit' => '40', 'paid' => '10', 'bh_total' => 'abc', 'balance' => '<b>']));
$junk = (new JobRepository())->find((int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'P17-JUNK'"));
check((float) $junk['bh_total'] === 60.0 && (float) $junk['balance'] === 50.0, 'whatever is posted in the calculated boxes is replaced by the calculation (100 - 40 = 60, 60 - 10 = 50)');
// with nothing to calculate from, a negative typed balance is still refused
call('POST', '/jobs', job_input(['job_number' => 'P17-NB', 'balance' => '-5']));
check((int) Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number = 'P17-NB'") === 0, 'a negative balance typed with no Grand total / Deposit / Total to BH is still refused');
