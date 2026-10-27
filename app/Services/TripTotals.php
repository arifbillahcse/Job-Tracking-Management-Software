<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TripRepository;

/**
 * Column totals of a trip. The ONLY calculation in the trip module, kept in one place (assumption T1):
 * every column is the plain sum of its rows, and "Total expenses" is the sum of the six expense columns.
 * Nothing here uses the sheet's Grand Total / BH Total / Paid / Balance columns, whose rules are not confirmed.
 * Cents are added as integers so no floating point error can creep in.
 */
final class TripTotals
{
    /**
     * @param array<int,array<string,mixed>> $rows trip_jobs rows
     * @return array<string,string> column => total as "1234.50" (the six money columns plus "total")
     */
    public static function forRows(array $rows): array
    {
        $cents = array_fill_keys(TripRepository::MONEY, 0);
        foreach ($rows as $row) {
            foreach (TripRepository::MONEY as $column) {
                if (($row[$column] ?? null) !== null && $row[$column] !== '') {
                    $cents[$column] += (int) round((float) $row[$column] * 100);
                }
            }
        }
        $cents['total'] = array_sum($cents);

        return array_map(static fn (int $c): string => number_format($c / 100, 2, '.', ''), $cents);
    }
}
