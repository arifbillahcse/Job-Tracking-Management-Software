<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;

/**
 * THE single definition of the job sections. Dashboard counts and every list page build their
 * WHERE clause from condition(), so they cannot disagree. A job's section is never stored; it is
 * computed from status and 1st available date every time, so changing either moves the job automatically.
 *
 *   Ready for Delivery  status = booked AND available date from today - N days to today + N days   (assumptions A2, K1)
 *   More Than 30 Days   status = booked AND available date more than N days away, in the future OR in the past
 *                       (jobs that have already waited more than N days are the ones that carry the storage fee)
 *   In Transit          status = in_transit
 *   Delivered           status = delivered
 *   All Jobs            every job that is not deleted (also on hold / cancelled, which belong to no other section)
 *
 * N is settings.ready_threshold_days (default 30).
 */
final class JobBucket
{
    public const ALL = 'all';
    public const READY = 'ready';
    public const LATER = 'later';
    public const TRANSIT = 'transit';
    public const DELIVERED = 'delivered';

    /**
     * @return array<string,array{label:string,path:string,icon:string,tone:string}>
     */
    public static function definitions(): array
    {
        return [
            self::ALL       => ['label' => 'All Jobs',            'path' => '/jobs',             'icon' => 'briefcase',    'tone' => 'all'],
            self::READY     => ['label' => 'Ready for Delivery',  'path' => '/jobs/ready',       'icon' => 'clock',        'tone' => 'ready'],
            self::LATER     => ['label' => 'More Than 30 Days',   'path' => '/jobs/more-than-30', 'icon' => 'calendar',     'tone' => 'later'],
            self::TRANSIT   => ['label' => 'In Transit',          'path' => '/jobs/in-transit',  'icon' => 'truck',        'tone' => 'transit'],
            self::DELIVERED => ['label' => 'Delivered',           'path' => '/jobs/delivered',   'icon' => 'check-circle', 'tone' => 'delivered'],
        ];
    }

    public static function isValid(string $bucket): bool
    {
        return array_key_exists($bucket, self::definitions());
    }

    /** Section title with the configured threshold, e.g. "More Than 45 Days". */
    public static function label(string $bucket): string
    {
        $label = self::definitions()[$bucket]['label'] ?? $bucket;
        return $bucket === self::LATER ? 'More Than ' . self::threshold() . ' Days' : $label;
    }

    /** Days ahead that still count as "ready for delivery" (settings.ready_threshold_days). */
    public static function threshold(): int
    {
        $value = SettingsService::get('ready_threshold_days', '30');
        // A blank or non-numeric value must not silently become 0 (which would empty the Ready list).
        return is_numeric($value) ? max(0, min(3650, (int) $value)) : 30;
    }

    /** Oldest available date that is still "ready": today - threshold (older jobs have waited too long and move to More Than N Days). */
    public static function pastCutoff(): string
    {
        return Clock::addDays(Clock::today(), -self::threshold());
    }

    /** Last available date that is still "ready": today + threshold. */
    public static function cutoff(): string
    {
        return Clock::addDays(Clock::today(), self::threshold());
    }

    /**
     * SQL condition (positional parameters) selecting the jobs of a section.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public static function condition(string $bucket, string $alias = 'j'): array
    {
        $base = "{$alias}.deleted_at IS NULL";

        return match ($bucket) {
            self::READY => ["{$base} AND {$alias}.status = ? AND {$alias}.first_available_date <= ? AND {$alias}.first_available_date >= ?", ['booked', self::cutoff(), self::pastCutoff()]],
            self::LATER => ["{$base} AND {$alias}.status = ? AND ({$alias}.first_available_date > ? OR {$alias}.first_available_date < ?)", ['booked', self::cutoff(), self::pastCutoff()]],
            self::TRANSIT => ["{$base} AND {$alias}.status = ?", ['in_transit']],
            self::DELIVERED => ["{$base} AND {$alias}.status = ?", ['delivered']],
            default => [$base, []],
        };
    }

    /**
     * Which section a job currently belongs to, or null (on hold / cancelled). PHP twin of condition(),
     * used for display; the tests assert both agree.
     *
     * @param array<string,mixed> $job
     */
    public static function of(array $job): ?string
    {
        return match ((string) $job['status']) {
            'booked' => (string) $job['first_available_date'] <= self::cutoff() && (string) $job['first_available_date'] >= self::pastCutoff() ? self::READY : self::LATER,
            'in_transit' => self::TRANSIT,
            'delivered' => self::DELIVERED,
            default => null,
        };
    }

    /**
     * Countdown shown next to the available date of a booked job.
     *
     * @param array<string,mixed> $job
     * @return array{text:string,tone:string}|null tone: danger (overdue), warning (today), ready, later
     */
    public static function availability(array $job): ?array
    {
        if ((string) $job['status'] !== 'booked') {
            return null;
        }

        $days = Clock::daysBetween(Clock::today(), (string) $job['first_available_date']);

        return match (true) {
            $days < 0 => ['text' => 'Overdue by ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's'), 'tone' => 'danger'],
            $days === 0 => ['text' => 'Today', 'tone' => 'warning'],
            $days === 1 => ['text' => 'Tomorrow', 'tone' => $days <= self::threshold() ? 'ready' : 'later'],
            default => ['text' => 'in ' . $days . ' days', 'tone' => $days <= self::threshold() ? 'ready' : 'later'],
        };
    }

    /** The day a booked job moves from "More Than N Days" into "Ready for Delivery". */
    public static function movesToReadyOn(string $availableDate): string
    {
        return Clock::addDays($availableDate, -self::threshold());
    }
}
