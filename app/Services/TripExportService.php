<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Response;
use App\Repositories\TripRepository;
use Generator;

/**
 * CSV export of trips (trips.export): the filtered trip list as one line per job row with the trip details repeated
 * (the shape of the client's sheet), and the foreman-wise report. UTF-8 with a BOM so Excel reads it, streamed in
 * chunks, and spreadsheet formulas are neutralised exactly as in the job export. Expense columns only for users
 * with trips.financials.
 */
final class TripExportService
{
    public const MAX_ROWS = 50000;
    private const CHUNK = 500;

    private const ACTIVITY_LABELS = ['pickup' => 'Pickup', 'delivery' => 'Delivery', 'other' => 'Other'];
    private const MONEY_LABELS = ['diesel' => 'Diesel', 'hotels' => 'Hotels', 'helper' => 'Helper', 'misc' => 'Misc', 'commission' => 'Commission', 'extra' => 'Extra'];

    public function __construct(private TripRepository $trips = new TripRepository())
    {
    }

    /** @return array<string,string> column key => heading, in file order */
    public static function columns(bool $money, bool $phone = false): array
    {
        $columns = [
            'trip_number' => 'Trip Number', 'begins_on' => 'Trip Begins', 'ends_on' => 'Trip Ends',
            'job_number' => 'Job #', 'customer_name' => 'Customer Name', 'customer_phone' => 'Customer Phone', 'job_date' => 'Date', 'activity' => 'Activity',
            'from_location' => 'From', 'to_location' => 'To', 'bh' => 'BH', 'foreman_name' => 'Foreman',
        ];
        if (!$phone) {
            unset($columns['customer_phone']);
        }
        if ($money) {
            $columns += self::MONEY_LABELS;
        }
        return $columns + ['remark' => 'Remark'];
    }

    public function tooLarge(TripCriteria $criteria): bool
    {
        return $this->trips->countJobRows($criteria) > self::MAX_ROWS;
    }

    public function csv(TripCriteria $criteria, bool $money): Response
    {
        $columns = self::columns($money, \App\Core\Auth::can('jobs.view'));
        $count = min($this->trips->countJobRows($criteria), self::MAX_ROWS);
        AuditService::record('trips_exported', 'trip', null, null, ['format' => 'csv', 'rows' => $count, 'filters' => $criteria->linkParams()]);

        return $this->stream('trips-' . gmdate('Ymd-His') . '.csv', array_values($columns), $this->rows($criteria, array_keys($columns)));
    }

    /** @param array{rows:array<int,array<string,mixed>>,totals:array<string,string|int>,money:bool} $report */
    public function reportCsv(array $report, TripCriteria $criteria): Response
    {
        $head = ['Foreman', 'Trips', 'Jobs'];
        if ($report['money']) {
            $head = [...$head, ...array_values(self::MONEY_LABELS), 'Total Expenses'];
        }
        $lines = (function () use ($report): Generator {
            foreach ($report['rows'] as $r) {
                yield $this->reportLine($r['name'], $r, $report['money']);
            }
            yield $this->reportLine('Total', $report['totals'], $report['money']);
        })();
        AuditService::record('trips_exported', 'trip', null, null, ['format' => 'csv', 'report' => 'by foreman', 'rows' => count($report['rows']), 'filters' => $criteria->linkParams()]);

        return $this->stream('trips-by-foreman-' . gmdate('Ymd-His') . '.csv', $head, $lines);
    }

    /**
     * @param array<string,mixed> $r
     * @return array<int,string>
     */
    private function reportLine(string $name, array $r, bool $money): array
    {
        $line = [$name, (string) $r['trips'], (string) $r['jobs']];
        if ($money) {
            foreach ([...array_keys(self::MONEY_LABELS), 'total'] as $k) {
                $line[] = (string) $r[$k];
            }
        }
        return $line;
    }

    /**
     * @param array<int,string> $heading
     * @param iterable<int,array<int,string|null>> $lines
     */
    private function stream(string $filename, array $heading, iterable $lines): Response
    {
        return Response::stream(static function () use ($heading, $lines): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $heading, ',', '"', '');
            foreach ($lines as $line) {
                fputcsv($out, array_map([ExportService::class, 'safeCell'], $line), ',', '"', '');
            }
            fclose($out);
        }, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * @param array<int,string> $keys
     * @return Generator<int,array<int,string|null>>
     */
    private function rows(TripCriteria $criteria, array $keys): Generator
    {
        $sent = 0;
        for ($offset = 0; $sent < self::MAX_ROWS; $offset += self::CHUNK) {
            $chunk = $this->trips->jobRows($criteria, min(self::CHUNK, self::MAX_ROWS - $sent), $offset);
            if ($chunk === []) {
                return;
            }
            foreach ($chunk as $row) {
                yield array_map(static function (string $k) use ($row): ?string {
                    $v = $row[$k] ?? null;
                    if ($v === null) {
                        return null;
                    }
                    if ($k === 'activity') {
                        return self::ACTIVITY_LABELS[$v] ?? (string) $v;
                    }
                    return isset(self::MONEY_LABELS[$k]) ? number_format((float) $v, 2, '.', '') : (string) $v;
                }, $keys);
                $sent++;
            }
            if (count($chunk) < self::CHUNK) {
                return;
            }
        }
    }
}
