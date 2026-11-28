<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Response;
use ZipArchive;

/**
 * Invoice export (v1.21.0): the saved invoices (one row per invoice) and their lines (one row per job line) as CSV, or everything in one ZIP
 * (invoices, lines, companies, drivers), for the invoices that match the list's filters. Money and CF columns are written as plain numbers
 * (a negative one stays a number); text cells are made safe against spreadsheet formulas.
 */
final class InvoiceExport
{
    public const MAX_ROWS = 50000;

    private const SUMMARY = ['Invoice #', 'Type', 'Status', 'Date', 'Paid on', 'Carrier', 'Driver', 'Company', 'Jobs', 'Total CF', 'Subtotal (Bal. Due total)', 'CF Pay total', 'Labor', 'Pads', 'Paid / adjustment', 'Total due', 'Who owes whom', 'Made from', 'Remarks'];
    private const LINES = ['Invoice #', 'Type', 'Status', 'Date', 'Invoice carrier', 'Invoice driver', 'Invoice company', 'Job #', 'Job status', 'Customer', 'Phone', 'From', 'To', 'Carrier', 'Driver', 'Company', 'A-CF', 'Rate', 'CF Pay', 'Bal. Due', 'Remarks'];

    /** @param array<string,string> $filters @return array<int,array<int,string>> */
    public static function summaryRows(array $filters): array
    {
        [$where, $params] = InvoiceService::where($filters);
        $rows = Database::fetchAll(
            "SELECT i.*, (SELECT COUNT(*) FROM invoice_lines il WHERE il.invoice_id = i.id) AS line_count,
                    (SELECT s.type FROM invoices s WHERE s.id = i.source_invoice_id) AS source_type, (SELECT s.seq FROM invoices s WHERE s.id = i.source_invoice_id) AS source_seq
             FROM invoices i WHERE {$where} ORDER BY i.id ASC LIMIT " . self::MAX_ROWS,
            $params
        );
        $out = [self::SUMMARY];
        foreach ($rows as $r) {
            $dir = CarrierStatement::direction(number_format((float) $r['total'], 2, '.', ''));
            $out[] = [
                InvoiceService::number($r), InvoiceService::TYPES[$r['type']] ?? (string) $r['type'], ucfirst(InvoiceService::status($r)), (string) $r['invoice_date'], (string) ($r['paid_on'] ?? ''),
                (string) ($r['carrier'] ?? ''), (string) ($r['driver'] ?? ''), (string) ($r['broker'] ?? ''), (string) $r['line_count'], self::num($r['total_cf']), self::num($r['subtotal']),
                self::num($r['carrier_fee']), self::num($r['labor']), self::num($r['pads']), self::num($r['paid_amount']), self::num($r['total']),
                $dir['code'] === 'settled' ? $dir['text'] : $dir['text'] . ' ' . $dir['amount'], $r['source_type'] !== null ? $r['source_type'] . '-' . (int) $r['source_seq'] : '', (string) ($r['remarks'] ?? ''),
            ];
        }
        return $out;
    }

    /** @param array<string,string> $filters @return array<int,array<int,string>> */
    public static function lineRows(array $filters): array
    {
        [$where, $params] = InvoiceService::where($filters);
        $rows = Database::fetchAll(
            "SELECT il.*, i.type, i.seq, i.invoice_date, i.voided_at, i.paid_on, i.carrier AS inv_carrier, i.driver AS inv_driver, i.broker AS inv_broker
             FROM invoice_lines il JOIN invoices i ON i.id = il.invoice_id WHERE {$where} ORDER BY i.id ASC, il.sort_order ASC, il.id ASC LIMIT " . self::MAX_ROWS,
            $params
        );
        $labels = array_map(static fn (array $s): string => (string) $s['label'], (array) \App\Core\Config::get('statuses.statuses', []));
        $out = [self::LINES];
        foreach ($rows as $r) {
            $out[] = [
                (string) $r['type'] . '-' . (int) $r['seq'], InvoiceService::TYPES[$r['type']] ?? (string) $r['type'], ucfirst(InvoiceService::status($r)), (string) $r['invoice_date'],
                (string) ($r['inv_carrier'] ?? ''), (string) ($r['inv_driver'] ?? ''), (string) ($r['inv_broker'] ?? ''), (string) $r['job_number'],
                $r['status'] === null ? '' : ($labels[$r['status']] ?? (string) $r['status']), (string) $r['customer'], (string) ($r['phone'] ?? ''), (string) $r['from_location'], (string) $r['to_location'],
                (string) ($r['carrier'] ?? ''), (string) ($r['driver'] ?? ''), (string) ($r['broker'] ?? ''), $r['cf'] === null ? '' : self::num($r['cf']), $r['rate'] === null ? '' : self::num($r['rate']),
                $r['fee'] === null ? '' : self::num($r['fee']), self::num($r['balance']), (string) ($r['remarks'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array<int,array<int,string>> */
    public static function companyRows(): array
    {
        $out = [['Name', 'Address', 'City', 'Phone', 'DOT #', 'MC #']];
        foreach (Database::fetchAll('SELECT * FROM companies ORDER BY name') as $r) {
            $out[] = [(string) $r['name'], (string) ($r['address'] ?? ''), (string) ($r['city'] ?? ''), (string) ($r['phone'] ?? ''), (string) ($r['dot_number'] ?? ''), (string) ($r['mc_number'] ?? '')];
        }
        return $out;
    }

    /** @return array<int,array<int,string>> */
    public static function driverRows(): array
    {
        $out = [['Name', 'Phone', 'License #']];
        foreach (Database::fetchAll('SELECT * FROM drivers ORDER BY name') as $r) {
            $out[] = [(string) $r['name'], (string) ($r['phone'] ?? ''), (string) ($r['license'] ?? '')];
        }
        return $out;
    }

    /** One CSV file as a response. @param array<int,array<int,string>> $rows */
    public static function csvResponse(array $rows, string $name): Response
    {
        return Response::stream(static function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // tells Excel the file is UTF-8
            self::write($out, $rows);
            fclose($out);
        }, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"']);
    }

    /** Everything in one ZIP. @param array<string,string> $filters */
    public static function zipResponse(array $filters): Response
    {
        $file = tempnam(sys_get_temp_dir(), 'jt_inv_');
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (['invoices.csv' => self::summaryRows($filters), 'invoice-lines.csv' => self::lineRows($filters), 'companies.csv' => self::companyRows(), 'drivers.csv' => self::driverRows()] as $name => $rows) {
            $mem = fopen('php://memory', 'r+');
            fwrite($mem, "\xEF\xBB\xBF");
            self::write($mem, $rows);
            rewind($mem);
            $zip->addFromString($name, (string) stream_get_contents($mem));
            fclose($mem);
        }
        $zip->close();

        return Response::stream(static function () use ($file): void {
            readfile($file);
            @unlink($file);
        }, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="invoices-export-' . Clock::today() . '.zip"']);
    }

    /** @param resource $handle @param array<int,array<int,string>> $rows */
    private static function write($handle, array $rows): void
    {
        foreach ($rows as $i => $row) {
            // The header is ours; a text cell that starts like a formula gets an apostrophe. A number (a plain amount, minus sign allowed) is left alone.
            fputcsv($handle, $i === 0 ? $row : array_map(static fn (string $v): string => preg_match('/^-?\d+(\.\d+)?$/', $v) === 1 ? $v : ExportService::safeCell($v), $row), ',', '"', '');
        }
    }

    private static function num(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
