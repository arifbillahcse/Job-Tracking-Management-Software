<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * The carrier / driver / company statement (client request, v1.11.0, company v1.14.0): the jobs of one carrier, driver and/or company
 * (the job's broker), with what the carrier is paid.
 *
 *   CF Pay   = the job's own carrier rate x its actual CF (the client's words: "rate times A-CF"; each job has its own rate, v1.12.0)
 *   Subtotal = the Bal. Due of the listed jobs
 *   TOTAL DUE = Subtotal - CF Pay
 *
 * Jobs in Stage, In Transit and Delivered are listed (deleted jobs never). The optional dates are on the loaded date, or the pickup date
 * when the job has not been loaded yet. All money is added in whole cents so the totals are exact.
 */
final class CarrierStatement
{
    public const STATUSES = ['staged', 'in_transit', 'delivered'];
    public const MAX_ROWS = 1000;

    /** @return array{value:string,error:?string} a date as Y-m-d, or '' (no limit) */
    public static function parseDate(mixed $input, string $label): array
    {
        $text = is_string($input) ? trim($input) : '';
        if ($text === '') {
            return ['value' => '', 'error' => null];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return ['value' => '', 'error' => $label . ' is not a valid date.'];
        }
        return ['value' => $text, 'error' => null];
    }

    /** The carrier names used by jobs that can be on a statement, for the drop-down. @return array<int,string> */
    public static function carriers(): array
    {
        return self::distinct('carrier_name');
    }

    /** @return array<int,string> */
    public static function drivers(): array
    {
        return self::distinct('driver_name');
    }

    /** The companies (brokers) of jobs that can be on a statement. @return array<int,string> */
    public static function brokers(): array
    {
        return self::distinct('broker');
    }

    /** @return array<int,string> */
    private static function distinct(string $column): array
    {
        $marks = implode(',', array_fill(0, count(self::STATUSES), '?'));
        $rows = Database::fetchAll(
            "SELECT DISTINCT {$column} AS v FROM jobs WHERE deleted_at IS NULL AND {$column} IS NOT NULL AND {$column} <> '' AND status IN ({$marks}) ORDER BY {$column}",
            self::STATUSES
        );
        return array_map(static fn (array $r): string => (string) $r['v'], $rows);
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,totals:array<string,mixed>,truncated:bool}
     */
    public static function build(string $carrier, string $driver, string $broker, string $from, string $to): array
    {
        $marks = implode(',', array_fill(0, count(self::STATUSES), '?'));
        $where = "j.deleted_at IS NULL AND j.status IN ({$marks})";
        $params = self::STATUSES;
        if ($carrier !== '') {
            $where .= ' AND LOWER(j.carrier_name) = ?'; // the same on MySQL and SQLite: letter case never matters
            $params[] = mb_strtolower($carrier);
        }
        if ($driver !== '') {
            $where .= ' AND LOWER(j.driver_name) = ?'; // the same on MySQL and SQLite: letter case never matters
            $params[] = mb_strtolower($driver);
        }
        if ($broker !== '') {
            $where .= ' AND LOWER(j.broker) = ?'; // the same on MySQL and SQLite: letter case never matters
            $params[] = mb_strtolower($broker);
        }
        $date = 'COALESCE(j.loaded_date, j.pickup_date, j.first_available_date)';
        if ($from !== '') {
            $where .= " AND {$date} >= ?";
            $params[] = $from;
        }
        if ($to !== '') {
            $where .= " AND {$date} <= ?";
            $params[] = $to;
        }
        $found = Database::fetchAll(
            "SELECT j.* FROM jobs j WHERE {$where} ORDER BY {$date} ASC, j.job_number ASC LIMIT " . (self::MAX_ROWS + 1),
            $params
        );
        $truncated = count($found) > self::MAX_ROWS;
        $found = array_slice($found, 0, self::MAX_ROWS);

        $rows = [];
        $cfTotal = 0;
        $feeTotal = 0;
        $balTotal = 0;
        $noCf = 0;
        $noRate = 0;
        $driverPhone = '';
        $incomplete = 0;
        foreach ($found as $job) {
            $cf = $job['cf'] === null || $job['cf'] === '' ? null : (int) round((float) $job['cf'] * 100);                    // hundredths of a cubic foot
            $rateCents = $job['carrier_rate'] === null || $job['carrier_rate'] === '' ? null : (int) round((float) $job['carrier_rate'] * 100);
            $fee = $cf === null || $rateCents === null ? null : intdiv($cf * $rateCents + 50, 100);                          // cents, half up; no CF or no rate = no CF Pay
            $bal = (int) round((float) $job['balance'] * 100);
            $cfTotal += $cf ?? 0;
            $feeTotal += $fee ?? 0;
            $balTotal += $bal;
            if ($driverPhone === '' && trim((string) ($job['driver_phone'] ?? '')) !== '') {
                $driverPhone = trim((string) $job['driver_phone']);
            }
            $noCf += $cf === null ? 1 : 0;
            $noRate += $rateCents === null ? 1 : 0;
            $incomplete += $fee === null ? 1 : 0;
            $rows[] = [
                'id' => (int) $job['id'],
                'job_number' => (string) $job['job_number'],
                'status' => (string) $job['status'],
                'customer' => trim((string) $job['customer_first_name'] . ' ' . (string) $job['customer_last_name']),
                'phone' => (string) ($job['customer_phone'] ?? ''),
                'broker' => (string) ($job['broker'] ?? ''),
                'carrier' => (string) ($job['carrier_name'] ?? ''),
                'driver' => (string) ($job['driver_name'] ?? ''),
                'from' => (string) $job['from_location'],
                'to' => (string) $job['to_location'],
                'cf' => $cf === null ? null : self::cents($cf),
                'rate' => $rateCents === null ? null : self::cents($rateCents),
                'fee' => $fee === null ? null : self::cents($fee),
                'balance' => self::cents($bal),
                'remarks' => (string) ($job['notes'] ?? ''),
            ];
        }

        return [
            'rows' => $rows,
            'truncated' => $truncated,
            'driver_phone' => $driverPhone,
            'totals' => [
                'jobs' => count($rows),
                'cf' => self::cents($cfTotal),
                'fee' => self::cents($feeTotal),
                'subtotal' => self::cents($balTotal),
                'due' => self::cents($balTotal - $feeTotal),
                'no_cf' => $noCf,
                'no_rate' => $noRate,
                'incomplete' => $incomplete,
            ],
        ];
    }

    /** Whole cents (or hundredths) as a decimal string with 2 places: 225000 => "2250.00". */
    private static function cents(int $value): string
    {
        return ($value < 0 ? '-' : '') . intdiv(abs($value), 100) . '.' . str_pad((string) (abs($value) % 100), 2, '0', STR_PAD_LEFT);
    }
}
