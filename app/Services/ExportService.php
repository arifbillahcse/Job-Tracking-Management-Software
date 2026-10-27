<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Response;
use App\Repositories\JobRepository;
use App\Services\Import\JobFields;
use App\Services\Import\XlsxWriter;
use Generator;
use RuntimeException;

/**
 * Exports exactly what a list screen shows (section + search + filters + sort) as CSV or Excel.
 *
 *  - Columns follow the client's own sheets per section; the "All Jobs" export uses every importable column in
 *    template order, so an exported file can be imported again.
 *  - Balance and Storage Fee are left out unless the user may see money.
 *  - CSV is UTF-8 with a BOM (Excel reads accents correctly) and is streamed in chunks, so large exports use little memory.
 *  - Cells that start with = + - @ (or a tab / carriage return) get a leading apostrophe so a spreadsheet can never
 *    run them as a formula (CSV injection). The importer removes that apostrophe again.
 */
final class ExportService
{
    public const MAX_ROWS = 50000;
    public const XLSX_MAX_ROWS = 20000;
    private const CHUNK = 500;

    private const BASE = ['job_number', 'customer_first_name', 'customer_last_name', 'customer_phone', 'pickup_date', 'first_available_date', 'from_location', 'to_location', 'cf', 'balance'];

    public function __construct(private JobRepository $jobs = new JobRepository())
    {
    }

    /**
     * @return array<int,string> field keys, in column order
     */
    public static function columns(string $bucket, bool $financials): array
    {
        $columns = match ($bucket) {
            JobBucket::READY => self::BASE,
            JobBucket::LATER => [...self::BASE, 'storage_fee'],
            JobBucket::TRANSIT => [...self::BASE, 'loaded_date', 'carrier_name', 'driver_name', 'driver_phone'],
            JobBucket::DELIVERED => [...self::BASE, 'loaded_date', 'delivered_on'],
            default => array_keys(JobFields::all()),
        };

        return $financials
            ? array_values($columns)
            : array_values(array_filter($columns, static fn (string $c): bool => !JobFields::all()[$c]['financial']));
    }

    /** A CSV template with the headings and one example row (money columns only for users who can import them). */
    public static function templateRows(bool $financials): array
    {
        $columns = self::columns(JobBucket::ALL, $financials);
        return [
            array_map([JobFields::class, 'label'], $columns),
            // The example is a Booked job, so the dispatch / delivery columns stay empty (they apply to jobs that have moved on).
            array_map(static fn (string $c): string => in_array($c, ['carrier_name', 'driver_name', 'driver_phone', 'loaded_date', 'delivered_on'], true) ? '' : JobFields::all()[$c]['example'], $columns),
        ];
    }

    public function filename(string $bucket, string $extension): string
    {
        $slug = str_replace('_', '-', $bucket === JobBucket::LATER ? 'more-than-' . JobBucket::threshold() . '-days' : $bucket);
        return 'jobs-' . $slug . '-' . gmdate('Ymd-His') . '.' . $extension;
    }

    public function csv(JobCriteria $criteria, bool $financials): Response
    {
        $columns = self::columns($criteria->bucket, $financials);
        $count = min($this->jobs->count($criteria), self::MAX_ROWS);
        $this->audit($criteria, 'csv', $count);

        return Response::stream(function () use ($criteria, $columns): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: tells Excel the file is UTF-8
            fputcsv($out, array_map([JobFields::class, 'label'], $columns), ',', '"', '');
            foreach ($this->rows($criteria, $columns, self::MAX_ROWS) as $row) {
                fputcsv($out, array_map([self::class, 'safeCell'], $row), ',', '"', '');
            }
            fclose($out);
        }, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $this->filename($criteria->bucket, 'csv') . '"',
        ]);
    }

    public function xlsx(JobCriteria $criteria, bool $financials): Response
    {
        $columns = self::columns($criteria->bucket, $financials);
        $count = min($this->jobs->count($criteria), self::XLSX_MAX_ROWS);
        $this->audit($criteria, 'xlsx', $count);

        $file = tempnam(sys_get_temp_dir(), 'jobs');
        if ($file === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }
        $types = array_map(static fn (string $c): string => match (JobFields::all()[$c]['type']) {
            JobFields::DATE => XlsxWriter::DATE,
            JobFields::MONEY => XlsxWriter::MONEY,
            JobFields::NUMBER => XlsxWriter::NUMBER,
            default => XlsxWriter::TEXT,
        }, $columns);

        XlsxWriter::write($file, 'Jobs', array_map([JobFields::class, 'label'], $columns), $types, $this->rows($criteria, $columns, self::XLSX_MAX_ROWS));

        return Response::stream(static function () use ($file): void {
            readfile($file);
            @unlink($file);
        }, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $this->filename($criteria->bucket, 'xlsx') . '"',
            'Content-Length' => (string) filesize($file),
        ]);
    }

    /** Is there more than the export limit for this view? (Checked before streaming starts, so the user gets a message instead of a cut-off file.) */
    public function tooLarge(JobCriteria $criteria, string $format): bool
    {
        return $this->jobs->count($criteria) > ($format === 'xlsx' ? self::XLSX_MAX_ROWS : self::MAX_ROWS);
    }

    /**
     * Rows in chunks, in the list's own order, as cell values in column order.
     *
     * @param array<int,string> $columns
     * @return Generator<int,array<int,string|null>>
     */
    private function rows(JobCriteria $criteria, array $columns, int $cap): Generator
    {
        $sent = 0;
        for ($offset = 0; $sent < $cap; $offset += self::CHUNK) {
            $chunk = $this->jobs->fetch($criteria, min(self::CHUNK, $cap - $sent), $offset);
            if ($chunk === []) {
                return;
            }
            foreach ($chunk as $job) {
                yield array_map(fn (string $field): ?string => $this->cell($field, $job), $columns);
                $sent++;
            }
            if (count($chunk) < self::CHUNK) {
                return;
            }
        }
    }

    /**
     * @param array<string,mixed> $job
     */
    private function cell(string $field, array $job): ?string
    {
        $value = match ($field) {
            'delivered_on' => ($job['delivery_date'] ?? null) !== null ? (string) $job['delivery_date'] : ($job['delivered_at'] === null ? null : substr((string) $job['delivered_at'], 0, 10)),
            'status' => (string) \App\Core\Config::get('statuses.statuses.' . $job['status'] . '.label', $job['status']),
            'balance', 'storage_fee' => number_format((float) $job[$field], 2, '.', ''),
            'estimated_total', 'deposit', 'grand_total', 'bh_total', 'paid' => ($job[$field] ?? null) === null ? null : number_format((float) $job[$field], 2, '.', ''),
            'estimated_cf' => ($job['estimated_cf'] ?? null) === null ? null : rtrim(rtrim(number_format((float) $job['estimated_cf'], 2, '.', ''), '0'), '.'),
            'cf' => $job['cf'] === null ? null : rtrim(rtrim(number_format((float) $job['cf'], 2, '.', ''), '0'), '.'),
            default => $job[$field] ?? null,
        };
        return $value === null ? null : (string) $value;
    }

    /** Neutralise spreadsheet formulas in text cells. */
    public static function safeCell(?string $value): string
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    private function audit(JobCriteria $criteria, string $format, int $rows): void
    {
        AuditService::record('jobs_exported', 'job', null, null, [
            'section' => $criteria->bucket, 'format' => $format, 'rows' => $rows, 'filters' => $criteria->linkParams(),
        ]);
    }
}
