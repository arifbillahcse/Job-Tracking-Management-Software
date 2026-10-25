<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Repositories\SettingsRepository;

/**
 * Automatic storage fee: CF x rate, charged (once, not per day) when a job has waited more than N days.
 *
 *  - "Waited" is counted from the 1st available date (default) or from the loaded date (Settings).
 *  - A job whose clock has not started, or that has no CF, owes nothing.
 *  - Open jobs (booked, in transit, on hold) are re-assessed as days pass and whenever the rate or the rules change.
 *  - A delivered job is frozen: it keeps the fee assessed on its delivery date, at the rate used then.
 *  - Cancelled jobs owe nothing.
 *  - A fee typed in by hand (storage_fee_override) always wins and is never recalculated.
 *
 * The result is written to jobs.storage_fee, so lists, sorting, exports, the dashboard and totals all read one column.
 * The calculation lives only here (PHP); nothing else computes a fee.
 */
final class StorageFeeService
{
    public const BASIS = ['first_available' => '1st available date', 'loaded' => 'loaded date'];
    private const OPEN = ['booked', 'in_transit', 'on_hold'];

    public static function rate(): string
    {
        $rate = (string) SettingsService::get('storage_fee_rate', '0.6');
        return is_numeric($rate) ? $rate : '0.6';
    }

    public static function days(): int
    {
        $days = SettingsService::get('storage_fee_after_days', '30');
        return is_numeric($days) ? max(0, min(3650, (int) $days)) : 30;
    }

    public static function basis(): string
    {
        $basis = (string) SettingsService::get('storage_fee_basis', 'first_available');
        return isset(self::BASIS[$basis]) ? $basis : 'first_available';
    }

    /**
     * The date the waiting is counted from, or null when it has not started (e.g. basis is "loaded" and the job is not loaded).
     *
     * @param array<string,mixed> $job
     */
    public static function baseDate(array $job): ?string
    {
        $date = self::basis() === 'loaded' ? ($job['loaded_date'] ?? null) : ($job['first_available_date'] ?? null);
        return $date === null || $date === '' ? null : substr((string) $date, 0, 10);
    }

    /**
     * The first day a fee is charged for this job, or null when the clock has not started.
     *
     * @param array<string,mixed> $job
     */
    public static function startsOn(array $job): ?string
    {
        $base = self::baseDate($job);
        return $base === null ? null : Clock::addDays($base, self::days() + 1);
    }

    /**
     * What this job owes, as of today (open jobs) or its delivery date (delivered jobs).
     *
     * @param array<string,mixed> $job any row with status, cf, first_available_date, loaded_date, delivery_date, storage_rate, storage_fee_override
     * @return array{fee:string,rate:?string,manual:bool}
     */
    public static function assess(array $job, ?string $today = null): array
    {
        $override = $job['storage_fee_override'] ?? null;
        if ($override !== null && $override !== '') {
            return ['fee' => number_format((float) $override, 2, '.', ''), 'rate' => null, 'manual' => true];
        }
        $none = ['fee' => '0.00', 'rate' => null, 'manual' => false];

        $status = (string) ($job['status'] ?? 'booked');
        if ($status === 'cancelled' || ($job['cf'] ?? null) === null || (float) $job['cf'] <= 0) {
            return $none;
        }

        $today ??= Clock::today();
        $delivered = $status === 'delivered';
        $asOf = $delivered ? (substr((string) ($job['delivery_date'] ?? ''), 0, 10) ?: $today) : $today;

        $start = self::startsOn($job);
        if ($start === null || $asOf < $start) {
            return $none;
        }

        // A delivered job keeps the rate it was charged at, so changing the rate later never rewrites history.
        $rate = $delivered && ($job['storage_rate'] ?? null) !== null && $job['storage_rate'] !== '' ? (string) $job['storage_rate'] : self::rate();
        return ['fee' => number_format(round((float) $job['cf'] * (float) $rate, 2), 2, '.', ''), 'rate' => $rate, 'manual' => false];
    }

    /** Re-assess one job and store the result. Returns the fee. */
    public static function settle(int $id): string
    {
        $job = Database::fetchOne('SELECT * FROM jobs WHERE id = ?', [$id]);
        return $job === null ? '0.00' : self::store($job);
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function store(array $job): string
    {
        $result = self::assess($job);
        $same = number_format((float) $job['storage_fee'], 2, '.', '') === $result['fee']
            && ((string) ($job['storage_rate'] ?? '') === '' ? null : (float) $job['storage_rate']) === ($result['rate'] === null ? null : (float) $result['rate']);
        if (!$same) {
            // updated_at is deliberately left alone: a recalculation must not make someone's open edit form "stale".
            Database::execute('UPDATE jobs SET storage_fee = ?, storage_rate = ? WHERE id = ?', [$result['fee'], $result['rate'], $job['id']]);
        }
        return $result['fee'];
    }

    /**
     * Re-assess every open job (and bring overrides and cancelled jobs in line). Returns how many fees changed.
     */
    public static function refresh(): int
    {
        $changed = 0;
        $after = 0;
        while (true) {
            $rows = Database::fetchAll(
                "SELECT * FROM jobs WHERE deleted_at IS NULL AND id > ? AND (status IN ('booked', 'in_transit', 'on_hold') OR status = 'cancelled' OR storage_fee_override IS NOT NULL) ORDER BY id LIMIT 500",
                [$after]
            );
            if ($rows === []) {
                break;
            }
            Database::transaction(static function () use ($rows, &$changed): void {
                foreach ($rows as $job) {
                    $before = (string) $job['storage_fee'] . '|' . (string) ($job['storage_rate'] ?? '');
                    self::store($job);
                    $now = Database::fetchOne('SELECT storage_fee, storage_rate FROM jobs WHERE id = ?', [$job['id']]);
                    if ((string) $now['storage_fee'] . '|' . (string) ($now['storage_rate'] ?? '') !== $before) {
                        $changed++;
                    }
                }
            });
            $after = (int) end($rows)['id'];
        }
        (new SettingsRepository())->set('storage_fee_assessed_on', Clock::today());
        SettingsService::flush();
        return $changed;
    }

    /** Once per business day, on the first request that needs fresh figures. */
    public static function refreshIfDue(): void
    {
        if (SettingsService::get('storage_fee_assessed_on') !== Clock::today()) {
            self::refresh();
        }
    }
}
