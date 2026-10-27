<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Repositories\SettingsRepository;

/**
 * Automatic storage fee: A-CF x rate once a job has waited more than N days, then once more for every further month.
 *
 *  - Mode "monthly" (default, the client's sheet: "times whatever months stay, no prorating"): fee = CF x rate x months, where
 *    month 1 starts on day N+1 and each further month is 30 days later (day 31-60 = 1 month, 61-90 = 2 months, ...). Part of a
 *    month counts as a whole month. Mode "once": CF x rate is charged a single time (the earlier behaviour).
 *  - "Waited" is counted from the pickup date (default; the 1st available date when a job has no pickup date),
 *    the 1st available date, or the loaded date (Settings).
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
    public const BASIS = ['pickup' => 'pickup date', 'first_available' => '1st available date', 'loaded' => 'loaded date'];
    public const MODES = ['monthly' => 'every month (CF × rate × months)', 'once' => 'once (CF × rate, one time)'];
    /** Length of one storage month, in days (assumption N2). */
    public const MONTH_DAYS = 30;
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
        $basis = (string) SettingsService::get('storage_fee_basis', 'pickup');
        return isset(self::BASIS[$basis]) ? $basis : 'pickup';
    }

    public static function mode(): string
    {
        $mode = (string) SettingsService::get('storage_fee_mode', 'monthly');
        return isset(self::MODES[$mode]) ? $mode : 'monthly';
    }

    /**
     * How many charges a job has accumulated as of a date: 0 before the fee starts, then 1, plus one per further 30 days
     * (always 1 in "once" mode).
     */
    public static function months(string $startsOn, string $asOf): int
    {
        if ($asOf < $startsOn) {
            return 0;
        }
        if (self::mode() === 'once') {
            return 1;
        }
        return 1 + intdiv(Clock::daysBetween($startsOn, $asOf), self::MONTH_DAYS);
    }

    /**
     * The date the waiting is counted from, or null when it has not started (e.g. basis is "loaded" and the job is not loaded).
     *
     * @param array<string,mixed> $job
     */
    public static function baseDate(array $job): ?string
    {
        $date = match (self::basis()) {
            'loaded' => $job['loaded_date'] ?? null,
            'pickup' => ($job['pickup_date'] ?? null) !== null && $job['pickup_date'] !== '' ? $job['pickup_date'] : ($job['first_available_date'] ?? null),
            default => $job['first_available_date'] ?? null,
        };
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
     * @param array<string,mixed> $job any row with status, cf, pickup_date, first_available_date, loaded_date, delivery_date, storage_rate, storage_fee_override
     * @return array{fee:string,rate:?string,manual:bool,months?:int}
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
        $months = self::months($start, $asOf);
        return ['fee' => number_format(round((float) $job['cf'] * (float) $rate * $months, 2), 2, '.', ''), 'rate' => $rate, 'manual' => false, 'months' => $months];
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
