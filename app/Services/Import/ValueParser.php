<?php

declare(strict_types=1);

namespace App\Services\Import;

use DateTimeImmutable;

/**
 * Turns the messy text people type in spreadsheets into the clean values the job rules expect.
 * Every method returns the cleaned value, null when the cell is empty, or false when the text cannot be understood.
 */
final class ValueParser
{
    private const TEXT_DATE_FORMATS = ['M j, Y', 'F j, Y', 'M j Y', 'F j Y', 'j M Y', 'j F Y', 'd-M-Y', 'd M Y'];

    private const STATUSES = [
        'booked' => 'booked', 'new' => 'booked',
        'intransit' => 'in_transit', 'transit' => 'in_transit', 'dispatched' => 'in_transit',
        'delivered' => 'delivered', 'complete' => 'delivered', 'completed' => 'delivered',
        'onhold' => 'on_hold', 'hold' => 'on_hold',
        'cancelled' => 'cancelled', 'canceled' => 'cancelled',
    ];

    /** Text cell: trimmed, control characters removed, and the apostrophe our export adds before = + - @ undone. */
    public static function text(string $value): ?string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value;
        $value = trim($value);
        if (preg_match("/^'[=+\\-@]/", $value) === 1) {
            $value = substr($value, 1);
        }
        return $value === '' ? null : $value;
    }

    /** A value that is "really" a whole number but was stored as 1001.0 by a spreadsheet. */
    public static function wholeNumberText(string $value): ?string
    {
        $value = self::text($value);
        return ($value !== null && preg_match('/^\d+\.0+$/', $value) === 1) ? (string) (int) $value : $value;
    }

    /**
     * Dates: ISO (2026-11-01), US (11/01/2026), day-first when unambiguous (25/12/2026), written out
     * (Nov 1, 2026 / 1 November 2026) and, for spreadsheet files, Excel serial numbers.
     *
     * @return string|false|null Y-m-d
     */
    public static function date(string $value, bool $fromSpreadsheet = false): string|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T].*)?$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : false;
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})(?:[ T].*)?$/', $value, $m) === 1) {
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
            [$month, $day] = ($a > 12 && $b <= 12) ? [$b, $a] : [$a, $b]; // US order unless it can only be day-first
            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : false;
        }

        if ($fromSpreadsheet && preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            $serial = (int) floor((float) $value);
            if ($serial >= 1 && $serial <= 2958465) {
                return (new DateTimeImmutable('1899-12-30'))->modify('+' . $serial . ' days')->format('Y-m-d');
            }
            return false;
        }

        foreach (self::TEXT_DATE_FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed->format('Y-m-d');
            }
        }

        return false;
    }

    /**
     * Amounts and quantities: "$1,200.50", " 900 ", "1200.5". Not accepted: 1.200,50 (ambiguous), text, formulas.
     *
     * @return string|false|null plain number such as 1200.50
     */
    public static function number(string $value): string|false|null
    {
        $value = trim(str_replace(["\u{00A0}", ' '], '', $value));
        if ($value === '') {
            return null;
        }
        $value = (string) preg_replace('/^[$€£]/u', '', $value); // /u: € is three bytes
        if ($value === '') {
            return false; // a currency symbol with no amount
        }
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $value) === 1) {
            $value = str_replace(',', '', $value);
        }
        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? $value : false;
    }

    /**
     * @return string|false|null a status key such as in_transit
     */
    public static function status(string $value): string|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $normalized = (string) preg_replace('/[^a-z]+/', '', strtolower($value));
        return self::STATUSES[$normalized] ?? false;
    }
}
