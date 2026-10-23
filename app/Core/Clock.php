<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\SettingsService;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * "Today" in the business time zone (settings.timezone). Dates in the database are plain
 * calendar dates, so the 30-day rule must be evaluated against the business's today, not UTC's.
 * Tests can freeze the date with Clock::fake().
 */
final class Clock
{
    private static ?string $fakeToday = null;

    /** Freeze "today" as a Y-m-d date (null restores the real clock). */
    public static function fake(?string $date): void
    {
        self::$fakeToday = $date;
    }

    public static function timezone(): DateTimeZone
    {
        try {
            return new DateTimeZone(SettingsService::get('timezone', 'UTC') ?: 'UTC');
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /** Today's date, Y-m-d. */
    public static function today(): string
    {
        return self::$fakeToday ?? (new DateTimeImmutable('now', self::timezone()))->format('Y-m-d');
    }

    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** Whole days from $from to $to (negative when $to is earlier). */
    public static function daysBetween(string $from, string $to): int
    {
        $a = new DateTimeImmutable(substr($from, 0, 10));
        $b = new DateTimeImmutable(substr($to, 0, 10));
        return (int) $a->diff($b)->format('%r%a');
    }
}
