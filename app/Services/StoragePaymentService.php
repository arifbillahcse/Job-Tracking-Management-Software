<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;

/**
 * Storage fee payments (client request, v1.7.0): a customer who pays the storage fee month by month has each payment recorded on
 * the job; the fee still due is the assessed fee (StorageFeeService, or the manual override) minus what has been paid, never below 0.
 *
 * jobs.storage_paid always equals the sum of storage_payments for the job: it is recomputed from the table on every change,
 * never incremented. A payment can be for at most the amount still due, so a job never ends up overpaid by this screen.
 */
final class StoragePaymentService
{
    /**
     * What is still due on a job row: storage_fee - storage_paid, never negative.
     *
     * @param array<string,mixed> $job
     */
    public static function due(array $job): string
    {
        $fee = (int) round((float) ($job['storage_fee'] ?? 0) * 100);
        $paid = (int) round((float) ($job['storage_paid'] ?? 0) * 100);
        return number_format(max(0, $fee - $paid) / 100, 2, '.', '');
    }

    /** SQL for the same figure, for sorting and totals (portable: MySQL / MariaDB and SQLite). */
    public static function dueSql(string $alias = 'j'): string
    {
        return "(CASE WHEN {$alias}.storage_fee > {$alias}.storage_paid THEN {$alias}.storage_fee - {$alias}.storage_paid ELSE 0 END)";
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function forJob(int $jobId): array
    {
        return Database::fetchAll(
            'SELECT p.id, p.amount, p.paid_on, p.note, p.created_at, u.name AS recorded_by
             FROM storage_payments p LEFT JOIN users u ON u.id = p.created_by
             WHERE p.job_id = ? ORDER BY p.paid_on DESC, p.id DESC',
            [$jobId]
        );
    }

    /**
     * @return array{ok:bool,error?:string,job_number?:string,amount?:string,due?:string}
     */
    public static function record(int $jobId, mixed $amount, mixed $paidOn, mixed $note): array
    {
        $job = Database::fetchOne('SELECT id, job_number FROM jobs WHERE id = ? AND deleted_at IS NULL', [$jobId]);
        if ($job === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $amount = is_scalar($amount) ? trim((string) $amount) : '';
        $paidOn = is_scalar($paidOn) ? trim((string) $paidOn) : '';
        $note = is_scalar($note) ? trim((string) $note) : '';

        if (preg_match('/^(\d{1,10}(\.\d{1,2})?|\.\d{1,2})$/', $amount) !== 1 || (float) $amount <= 0) {
            return ['ok' => false, 'error' => 'Enter the amount paid as a plain amount above zero, such as 120 or 120.50.'];
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $paidOn);
        if ($date === false || $date->format('Y-m-d') !== $paidOn) {
            return ['ok' => false, 'error' => 'Choose the date the customer paid.'];
        }
        if ($paidOn > Clock::today()) {
            return ['ok' => false, 'error' => 'The payment date cannot be in the future.'];
        }
        if (mb_strlen($note) > 255) {
            return ['ok' => false, 'error' => 'The note can be at most 255 characters.'];
        }

        StorageFeeService::settle($jobId); // make sure the assessed fee is today's before comparing
        $cents = (int) round((float) $amount * 100);

        return Database::transaction(static function () use ($jobId, $job, $amount, $paidOn, $note, $cents): array {
            $row = Database::fetchOne('SELECT storage_fee, storage_paid FROM jobs WHERE id = ?', [$jobId]) ?? ['storage_fee' => 0, 'storage_paid' => 0];
            $dueCents = (int) round((float) self::due($row) * 100);
            if ($dueCents <= 0) {
                return ['ok' => false, 'error' => 'No storage fee is due on this job.'];
            }
            if ($cents > $dueCents) {
                return ['ok' => false, 'error' => 'The storage fee still due is ' . fmt_money(self::due($row)) . '. A payment cannot be more than that.'];
            }
            Database::execute(
                'INSERT INTO storage_payments (job_id, amount, paid_on, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$jobId, number_format($cents / 100, 2, '.', ''), $paidOn, $note === '' ? null : $note, Auth::id(), gmdate('Y-m-d H:i:s')]
            );
            $paid = self::resync($jobId);
            AuditService::record('storage_payment_added', 'job', $jobId, ['storage_paid' => (string) $row['storage_paid']], [
                'job' => (string) $job['job_number'], 'amount' => number_format($cents / 100, 2, '.', ''), 'paid_on' => $paidOn, 'storage_paid' => $paid,
            ]);
            return ['ok' => true, 'job_number' => (string) $job['job_number'], 'amount' => number_format($cents / 100, 2, '.', ''), 'due' => number_format(max(0, $dueCents - $cents) / 100, 2, '.', '')];
        });
    }

    /** Take a payment back (recorded by mistake). @return array{ok:bool,job_number?:string,amount?:string} */
    public static function remove(int $jobId, int $paymentId): array
    {
        $payment = Database::fetchOne('SELECT p.id, p.amount, p.paid_on, j.job_number FROM storage_payments p JOIN jobs j ON j.id = p.job_id WHERE p.id = ? AND p.job_id = ? AND j.deleted_at IS NULL', [$paymentId, $jobId]);
        if ($payment === null) {
            return ['ok' => false];
        }
        return Database::transaction(static function () use ($jobId, $paymentId, $payment): array {
            $before = (string) Database::fetchValue('SELECT storage_paid FROM jobs WHERE id = ?', [$jobId]);
            Database::execute('DELETE FROM storage_payments WHERE id = ? AND job_id = ?', [$paymentId, $jobId]);
            $paid = self::resync($jobId);
            AuditService::record('storage_payment_removed', 'job', $jobId, ['storage_paid' => $before], [
                'job' => (string) $payment['job_number'], 'amount' => number_format((float) $payment['amount'], 2, '.', ''), 'paid_on' => (string) $payment['paid_on'], 'storage_paid' => $paid,
            ]);
            return ['ok' => true, 'job_number' => (string) $payment['job_number'], 'amount' => number_format((float) $payment['amount'], 2, '.', '')];
        });
    }

    /** jobs.storage_paid = the sum of the job's payments. Returns it. */
    public static function resync(int $jobId): string
    {
        $sum = (string) Database::fetchValue('SELECT COALESCE(SUM(amount), 0) FROM storage_payments WHERE job_id = ?', [$jobId]);
        $paid = number_format((float) $sum, 2, '.', '');
        Database::execute('UPDATE jobs SET storage_paid = ? WHERE id = ?', [$paid, $jobId]);
        return $paid;
    }
}
