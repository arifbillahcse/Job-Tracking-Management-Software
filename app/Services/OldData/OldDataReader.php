<?php

declare(strict_types=1);

namespace App\Services\OldData;

use PDO;
use ZipArchive;

/**
 * Reads the data of the client's OLD application (the "Invoice Manager": companies, drivers, driver invoices, company invoices) into one
 * plain array that OldDataImporter understands. Two sources:
 *   - the ZIP (or a folder) made by the old application's own Export button: four CSV files. Handy (no database access) but LOSSY: the
 *     export has no Labor, Pads, Paid, paid date, invoice remarks or customer phone, and the three amounts reach us only as one total;
 *   - the old DATABASE itself, read with SELECT only: complete.
 * Nothing here writes anywhere.
 *
 * Shape: ['companies' => [['id','name','address','city','phone','dot','mc']], 'drivers' => [['id','name','phone','license']],
 *         'invoices' => [['type','seq','party','date','subtotal','fee','total','labor','pads','paid','paid_date','remarks','created','source','lines'=>[...]]],
 *         'exact' => bool, 'source' => string, 'problems' => [string]]
 * Money is kept as typed (strings), the importer works in cents.
 */
final class OldDataReader
{
    private const CSV_FILES = ['companies.csv', 'drivers.csv', 'driver-invoices.csv', 'company-invoices.csv'];

    /** @return array<string,mixed> */
    public static function fromCsv(string $path): array
    {
        $texts = [];
        $problems = [];
        if (is_dir($path)) {
            foreach (self::CSV_FILES as $name) {
                $file = rtrim($path, '/\\') . '/' . $name;
                $texts[$name] = is_file($file) ? (string) file_get_contents($file) : null;
            }
        } elseif (is_file($path)) {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return self::failed('The file is not a readable ZIP: ' . $path);
            }
            foreach (self::CSV_FILES as $name) {
                $content = $zip->getFromName($name);
                $texts[$name] = $content === false ? null : $content;
            }
            $zip->close();
        } else {
            return self::failed('Not found: ' . $path);
        }
        foreach (self::CSV_FILES as $name) {
            if ($texts[$name] === null) {
                $problems[] = $name . ' is not in the export.';
            }
        }
        if (count(array_filter($texts, static fn (?string $t): bool => $t !== null)) === 0) {
            return self::failed('None of the four export files (' . implode(', ', self::CSV_FILES) . ') was found.');
        }

        $companies = [];
        foreach (self::rows($texts['companies.csv'] ?? null) as $r) {
            $companies[] = ['id' => (int) $r[0], 'name' => (string) ($r[1] ?? ''), 'address' => (string) ($r[2] ?? ''), 'city' => (string) ($r[3] ?? ''), 'phone' => (string) ($r[4] ?? ''), 'dot' => (string) ($r[5] ?? ''), 'mc' => (string) ($r[6] ?? '')];
        }
        $drivers = [];
        foreach (self::rows($texts['drivers.csv'] ?? null) as $r) {
            $drivers[] = ['id' => (int) $r[0], 'name' => trim((string) ($r[1] ?? '') . ' ' . (string) ($r[2] ?? '')), 'phone' => (string) ($r[3] ?? ''), 'license' => (string) ($r[4] ?? '')];
        }

        $invoices = [];
        foreach (['driver-invoices.csv' => 'DI', 'company-invoices.csv' => 'CI'] as $file => $type) {
            $current = null;
            foreach (self::rows($texts[$file] ?? null) as $r) {
                $number = trim((string) ($r[0] ?? ''));
                if (preg_match('/^' . $type . '-(\d+)$/i', $number, $m) !== 1) {
                    $problems[] = $file . ': a row with the invoice number "' . $number . '" was not understood and is left out.';
                    continue;
                }
                $seq = (int) $m[1];
                if ($current === null || $current['seq'] !== $seq) {
                    if ($current !== null) {
                        $invoices[] = $current;
                    }
                    $current = [
                        'type' => $type, 'seq' => $seq, 'party' => trim((string) ($r[1] ?? '')), 'date' => self::mdy((string) ($r[2] ?? '')), 'subtotal' => (string) ($r[14] ?? ''),
                        'fee' => (string) ($r[15] ?? ''), 'total' => (string) ($r[16] ?? ''), 'labor' => null, 'pads' => null, 'paid' => null, 'paid_date' => null,
                        'remarks' => null, 'created' => null, 'source' => null, 'lines' => [],
                    ];
                }
                $line = [
                    'job_number' => trim((string) ($r[3] ?? '')), 'other' => trim((string) ($r[4] ?? '')), 'customer' => trim((string) ($r[5] ?? '')), 'phone' => '',
                    'from' => trim((string) ($r[6] ?? '')), 'to' => trim((string) ($r[7] ?? '')), 'cf' => (string) ($r[8] ?? ''), 'rate' => (string) ($r[9] ?? ''),
                    'balance' => (string) ($r[11] ?? ''), 'new_balance' => (string) ($r[12] ?? ''), 'remarks' => trim((string) ($r[13] ?? '')),
                ];
                if (implode('', [$line['job_number'], $line['customer'], $line['from'], $line['to'], $line['cf'], $line['balance']]) !== '') {
                    $current['lines'][] = $line;
                }
            }
            if ($current !== null) {
                $invoices[] = $current;
            }
        }

        return ['companies' => $companies, 'drivers' => $drivers, 'invoices' => $invoices, 'exact' => false, 'source' => 'the old application\'s Export (CSV)', 'problems' => $problems];
    }

    /** @return array<string,mixed> */
    public static function fromDatabase(PDO $pdo): array
    {
        $problems = [];
        $all = static function (string $sql) use ($pdo, &$problems): array {
            try {
                return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                $problems[] = 'The old database could not be read (' . preg_replace('/\s+/', ' ', $e->getMessage()) . ').';
                return [];
            }
        };
        $companies = [];
        foreach ($all('SELECT * FROM companies ORDER BY id') as $r) {
            $companies[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'address' => (string) ($r['address'] ?? ''), 'city' => (string) ($r['city'] ?? ''), 'phone' => (string) ($r['phone'] ?? ''), 'dot' => (string) ($r['dot_number'] ?? ''), 'mc' => (string) ($r['mc_number'] ?? '')];
        }
        $driverNames = [];
        $drivers = [];
        foreach ($all('SELECT * FROM drivers ORDER BY id') as $r) {
            $name = trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''));
            $driverNames[(int) $r['id']] = $name;
            $drivers[] = ['id' => (int) $r['id'], 'name' => $name, 'phone' => (string) ($r['phone'] ?? ''), 'license' => (string) ($r['license'] ?? '')];
        }
        $companyNames = array_column($companies, 'name', 'id');

        $invoices = [];
        foreach (['DI' => ['driver_invoices', 'driver_invoice_items', 'driver_id'], 'CI' => ['company_invoices', 'company_invoice_items', 'company_id']] as $type => [$table, $itemTable, $partyKey]) {
            $items = [];
            foreach ($all("SELECT * FROM {$itemTable} ORDER BY invoice_id, sort_order, id") as $it) {
                $items[(int) $it['invoice_id']][] = $it;
            }
            foreach ($all("SELECT * FROM {$table} ORDER BY id") as $inv) {
                $partyId = (int) ($inv[$partyKey] ?? 0);
                $party = $type === 'DI' ? ($driverNames[$partyId] ?? '') : ($companyNames[$partyId] ?? '');
                $lines = [];
                foreach ($items[(int) $inv['id']] ?? [] as $it) {
                    $otherId = (int) ($it[$type === 'DI' ? 'company_id' : 'driver_id'] ?? 0);
                    $lines[] = [
                        'job_number' => trim((string) ($it['job_number'] ?? '')), 'other' => $type === 'DI' ? ($companyNames[$otherId] ?? '') : ($driverNames[$otherId] ?? ''),
                        'customer' => trim((string) ($it['customer_name'] ?? '')), 'phone' => trim((string) ($it['phone'] ?? '')), 'from' => trim((string) ($it['from_location'] ?? '')),
                        'to' => trim((string) ($it['to_location'] ?? '')), 'cf' => (string) ($it['cubic_feet'] ?? ''), 'rate' => (string) ($it['rate'] ?? ''),
                        'balance' => (string) ($it['balance_due'] ?? ''), 'new_balance' => (string) ($it['new_balance'] ?? ''), 'remarks' => trim((string) ($it['remarks'] ?? '')),
                    ];
                }
                $invoices[] = [
                    'type' => $type, 'seq' => (int) $inv['id'], 'party' => $party, 'date' => self::ymd((string) ($inv['date'] ?? '')), 'subtotal' => (string) ($inv['subtotal'] ?? ''),
                    'fee' => (string) ($inv['carrier_fee'] ?? ''), 'total' => (string) ($inv['total'] ?? ''), 'labor' => (string) ($inv['labor_cost'] ?? '0'), 'pads' => (string) ($inv['pads'] ?? '0'),
                    'paid' => (string) ($inv['paid'] ?? '0'), 'paid_date' => self::ymd((string) ($inv['paid_date'] ?? '')), 'remarks' => trim((string) ($inv['invoice_remarks'] ?? '')),
                    'created' => (string) ($inv['created_at'] ?? ''), 'source' => $type === 'CI' && !empty($inv['driver_invoice_id']) ? (int) $inv['driver_invoice_id'] : null, 'lines' => $lines,
                ];
            }
        }
        return ['companies' => $companies, 'drivers' => $drivers, 'invoices' => $invoices, 'exact' => true, 'source' => 'the old database', 'problems' => $problems];
    }

    /** @return array<string,mixed> */
    private static function failed(string $message): array
    {
        return ['companies' => [], 'drivers' => [], 'invoices' => [], 'exact' => false, 'source' => '', 'problems' => [$message], 'failed' => true];
    }

    /** The rows of a CSV text without its header line and without empty lines. @return array<int,array<int,string|null>> */
    private static function rows(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        $rows = [];
        $first = true;
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($first) {
                $first = false; // the header
                continue;
            }
            if ($r === [null] || implode('', array_map('strval', $r)) === '') {
                continue;
            }
            $rows[] = $r;
        }
        fclose($fh);
        return $rows;
    }

    /** MM-DD-YYYY (the old export) to YYYY-MM-DD, or ''. */
    private static function mdy(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $text, $m) === 1 && checkdate((int) $m[1], (int) $m[2], (int) $m[3])) {
            return $m[3] . '-' . $m[1] . '-' . $m[2];
        }
        return '';
    }

    private static function ymd(string $text): string
    {
        $text = substr(trim($text), 0, 10);
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $text : '';
    }
}
