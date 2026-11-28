<?php

declare(strict_types=1);

namespace App\Services\OldData;

use App\Core\Auth;
use App\Core\Database;
use App\Services\AuditService;
use App\Services\CompanyService;
use App\Services\DriverService;

/**
 * Brings the data of the OLD application (see OldDataReader) into this software (v1.20.0, assumption AR1):
 *   companies and drivers  -> the Companies and Drivers lists (a name that is already there is left alone);
 *   driver invoices        -> saved driver invoices DI-n, company invoices -> saved company invoices CI-n, with the SAME NUMBER they had
 *                             (DI-295 stays DI-295; new invoices continue after the highest number);
 *   invoice lines          -> saved lines, linked to the job with the same Job # when exactly one such job exists (so those jobs show "On DI-295"
 *                             and are not billed again by accident).
 * The totals are worked out again from the lines with this software's formula (subtotal - CF Pay - Labor - Pads + Paid); a difference from
 * the old total is reported. It is safe to run again: an invoice whose number already exists is skipped. A dry run (the default) writes nothing.
 */
final class OldDataImporter
{
    /** @return array<string,mixed> the report (counts, warnings, errors, applied) */
    public static function run(array $data, bool $apply): array
    {
        $report = [
            'source' => (string) ($data['source'] ?? ''), 'exact' => (bool) ($data['exact'] ?? false), 'applied' => false, 'errors' => [], 'warnings' => array_values((array) ($data['problems'] ?? [])),
            'companies' => ['new' => 0, 'existing' => 0, 'skipped' => 0], 'drivers' => ['new' => 0, 'existing' => 0, 'skipped' => 0],
            'DI' => ['new' => 0, 'existing' => 0], 'CI' => ['new' => 0, 'existing' => 0], 'lines' => 0, 'linked' => 0, 'unlinked' => 0, 'mismatch' => 0,
        ];
        if (!empty($data['failed'])) {
            $report['errors'] = $report['warnings'];
            $report['warnings'] = [];
            return $report;
        }

        $jobIds = [];
        foreach (Database::fetchAll('SELECT id, job_number FROM jobs WHERE deleted_at IS NULL') as $j) {
            $jobIds[mb_strtolower((string) $j['job_number'])][] = (int) $j['id'];
        }

        // 1. work everything out first (writes nothing)
        $companies = self::planMasters($data['companies'] ?? [], $report, 'companies', static fn (array $c): array => self::companyInput($c), static fn (string $n): bool => CompanyService::findByName($n) !== null, static fn (array $in) => CompanyService::validate($in, null), 'company');
        $drivers = self::planMasters($data['drivers'] ?? [], $report, 'drivers', static fn (array $d): array => ['name' => $d['name'], 'phone' => $d['phone'], 'license' => $d['license']], static fn (string $n): bool => DriverService::findByName($n) !== null, static fn (array $in) => DriverService::validate($in, null), 'driver');

        $invoices = [];
        foreach ((array) ($data['invoices'] ?? []) as $inv) {
            $plan = self::planInvoice($inv, (bool) ($data['exact'] ?? false), $jobIds, $report);
            if ($plan === null) {
                continue;
            }
            $exists = Database::fetchValue('SELECT COUNT(*) FROM invoices WHERE type = ? AND seq = ?', [$plan['type'], $plan['seq']]);
            if ((int) $exists > 0) {
                $report[$plan['type']]['existing']++;
                continue;
            }
            $report[$plan['type']]['new']++;
            $report['lines'] += count($plan['lines']);
            $invoices[] = $plan;
        }
        if ($report['errors'] !== [] || !$apply) {
            return $report;
        }

        // 2. write it, all or nothing
        Database::transaction(static function () use ($companies, $drivers, $invoices): void {
            foreach ($companies as $input) {
                CompanyService::save(null, $input, false);
            }
            foreach ($drivers as $input) {
                DriverService::save(null, $input, false);
            }
            foreach ($invoices as $plan) {
                self::insert($plan);
            }
        });
        $report['applied'] = true;
        AuditService::record('old_data_imported', 'import', null, null, [
            'source' => $report['source'], 'companies' => (string) $report['companies']['new'], 'drivers' => (string) $report['drivers']['new'],
            'driver_invoices' => (string) $report['DI']['new'], 'company_invoices' => (string) $report['CI']['new'], 'lines' => (string) $report['lines'],
        ]);
        return $report;
    }

    /** @param array<string,mixed> $c @return array<string,string> */
    private static function companyInput(array $c): array
    {
        return ['name' => $c['name'], 'address' => $c['address'], 'city' => $c['city'], 'phone' => $c['phone'], 'dot_number' => $c['dot'], 'mc_number' => $c['mc']];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $report
     * @return array<int,array<string,string>> the inputs to save (a field that does not pass the rules is emptied and reported)
     */
    private static function planMasters(array $rows, array &$report, string $key, callable $toInput, callable $exists, callable $validate, string $label): array
    {
        $todo = [];
        $seen = [];
        foreach ($rows as $row) {
            $input = $toInput($row);
            $name = CompanyService::clean($input['name'] ?? '');
            if ($name === '') {
                $report[$key]['skipped']++;
                $report['warnings'][] = 'A ' . $label . ' with no name (old id ' . ($row['id'] ?? '?') . ') is left out.';
                continue;
            }
            if ($exists($name) || isset($seen[mb_strtolower($name)])) {
                $report[$key]['existing']++;
                continue;
            }
            $v = $validate($input);
            if (isset($v['errors']['name'])) {
                $report[$key]['skipped']++;
                $report['warnings'][] = 'The ' . $label . ' "' . mb_substr($name, 0, 40) . '" is left out: ' . $v['errors']['name'];
                continue;
            }
            foreach ($v['errors'] as $field => $message) {
                $input[$field] = '';
                $report['warnings'][] = 'The ' . $label . ' "' . $name . '": ' . $message . ' It is imported without that field.';
            }
            $seen[mb_strtolower($name)] = true;
            $report[$key]['new']++;
            $todo[] = $input;
        }
        return $todo;
    }

    private static function cents(mixed $value): int
    {
        $text = str_replace([',', '$', ' '], '', trim((string) $value));
        return $text === '' || !is_numeric($text) ? 0 : (int) round((float) $text * 100);
    }

    private static function fmt(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * @param array<string,mixed> $inv
     * @param array<string,array<int,int>> $jobIds
     * @param array<string,mixed> $report
     * @return array<string,mixed>|null
     */
    private static function planInvoice(array $inv, bool $exact, array $jobIds, array &$report): ?array
    {
        $type = (string) $inv['type'];
        $seq = (int) $inv['seq'];
        $number = $type . '-' . $seq;
        if ($seq < 1) {
            $report['warnings'][] = $number . ' has no valid number and is left out.';
            return null;
        }

        $lines = [];
        $subtotal = 0;
        $fee = 0;
        $cfTotal = 0;
        $coarse = 0;
        foreach ((array) $inv['lines'] as $l) {
            $cf = self::cents($l['cf']);
            $rateText = str_replace([',', '$', ' '], '', trim((string) $l['rate']));
            $rate = is_numeric($rateText) ? (float) $rateText : 0.0;
            $lineFee = (int) round(($cf / 100) * $rate * 100);
            if (abs($rate * 100 - round($rate * 100)) > 0.0001) {
                $coarse++;
            }
            $balance = self::cents($l['balance']);
            $remarks = (string) $l['remarks'];
            $original = self::cents($l['new_balance']);
            if ($original !== 0) {
                $remarks = trim($remarks . ($remarks === '' ? '' : ' ') . '[Original Bal. ' . self::fmt($original) . ']');
            }
            $key = mb_strtolower((string) $l['job_number']);
            $job = isset($jobIds[$key]) && count($jobIds[$key]) === 1 ? $jobIds[$key][0] : null;
            $job === null ? $report['unlinked']++ : $report['linked']++;
            $lines[] = [
                'job_id' => $job, 'job_number' => mb_substr((string) $l['job_number'], 0, 100), 'customer' => mb_substr((string) $l['customer'], 0, 255), 'phone' => mb_substr((string) $l['phone'], 0, 30),
                'from' => mb_substr((string) $l['from'], 0, 255), 'to' => mb_substr((string) $l['to'], 0, 255), 'other' => mb_substr((string) $l['other'], 0, 150),
                'cf' => self::fmt($cf), 'rate' => number_format($rate, 2, '.', ''), 'fee' => self::fmt($lineFee), 'balance' => self::fmt($balance), 'remarks' => mb_substr($remarks, 0, 5000),
            ];
            $subtotal += $balance;
            $fee += $lineFee;
            $cfTotal += $cf;
        }
        if ($coarse > 0) {
            $report['warnings'][] = $number . ': ' . $coarse . ' line' . ($coarse === 1 ? ' has' : 's have') . ' a rate with more than 2 decimals; the rate is shown rounded, the CF Pay is exact.';
        }
        if ($lines === []) {
            $subtotal = self::cents($inv['subtotal']);
            $fee = self::cents($inv['fee']);
            $report['warnings'][] = $number . ' has no jobs on it; it is imported with its totals only.';
        } else {
            if (abs($subtotal - self::cents($inv['subtotal'])) > 1 || abs($fee - self::cents($inv['fee'])) > 1) {
                $report['warnings'][] = $number . ': the jobs add up to Bal. Due ' . self::fmt($subtotal) . ' and CF Pay ' . self::fmt($fee) . ', the old invoice said ' . self::fmt(self::cents($inv['subtotal'])) . ' and ' . self::fmt(self::cents($inv['fee'])) . '. The sums from the jobs are used.';
            }
        }

        $labor = 0;
        $pads = 0;
        $paid = 0;
        $remarks = trim((string) ($inv['remarks'] ?? ''));
        $oldTotal = self::cents($inv['total']);
        if ($exact) {
            $labor = self::cents($inv['labor']);
            $pads = self::cents($inv['pads']);
            $paid = self::cents($inv['paid']);
        } else {
            // The export has only the invoice total: Labor, Pads and Paid reach us as one amount (it is added: a minus takes money off).
            $adjustment = $oldTotal - ($subtotal - $fee);
            if ($adjustment !== 0) {
                $paid = $adjustment;
                $remarks = trim($remarks . ($remarks === '' ? '' : "\n") . 'Imported from the old application: its export had no Labor, Pads or Paid, so together they are shown as Paid / adjustment ' . self::fmt($adjustment) . '.');
            }
        }
        $total = $subtotal - $fee - $labor - $pads + $paid;
        if (abs($total - $oldTotal) > 1) {
            $report['mismatch']++;
            $report['warnings'][] = $number . ': the total is ' . self::fmt($total) . ' here and was ' . self::fmt($oldTotal) . ' in the old application.';
        }

        $date = (string) $inv['date'];
        if ($date === '') {
            $date = substr((string) ($inv['created'] ?? ''), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                $date = \App\Core\Clock::today();
            }
            $report['warnings'][] = $number . ' has no valid date; ' . $date . ' is used.';
        }
        if ((string) $inv['party'] === '') {
            $report['warnings'][] = $number . ' has no ' . ($type === 'DI' ? 'driver' : 'company') . ' name.';
        }

        return [
            'type' => $type, 'seq' => $seq, 'party' => mb_substr((string) $inv['party'], 0, $type === 'DI' ? 150 : 100), 'date' => $date, 'cf' => self::fmt($cfTotal), 'subtotal' => self::fmt($subtotal), 'fee' => self::fmt($fee),
            'labor' => self::fmt($labor), 'pads' => self::fmt($pads), 'paid' => self::fmt($paid), 'total' => self::fmt($total), 'remarks' => mb_substr($remarks, 0, 2000),
            'paid_on' => (string) ($inv['paid_date'] ?? '') !== '' ? (string) $inv['paid_date'] : null, 'created' => (string) ($inv['created'] ?? ''), 'source' => $inv['source'] ?? null, 'lines' => $lines,
        ];
    }

    /** @param array<string,mixed> $p */
    private static function insert(array $p): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $created = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $p['created']) === 1 ? $p['created'] : $now;
        $isDriver = $p['type'] === 'DI';
        $driver = $isDriver && $p['party'] !== '' ? DriverService::findByName($p['party']) : null;
        $broker = !$isDriver && $p['party'] !== '' ? $p['party'] : null;
        $source = null;
        if (!$isDriver && $p['source'] !== null) {
            $found = Database::fetchValue("SELECT id FROM invoices WHERE type = 'DI' AND seq = ?", [(int) $p['source']]);
            $source = $found === false || $found === null ? null : (int) $found;
        }
        $id = Database::insert(
            'INSERT INTO invoices (type, seq, invoice_date, carrier, driver, broker, company_info, driver_phone, driver_license, period_from, period_to, total_cf, subtotal, carrier_fee, labor, pads, paid_amount, total, remarks, paid_on, source_invoice_id, created_by, created_at, updated_at)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $p['type'], $p['seq'], $p['date'], $isDriver && $p['party'] !== '' ? $p['party'] : null, $broker, $broker !== null ? CompanyService::snapshot($broker) : null,
                $driver !== null && (string) ($driver['phone'] ?? '') !== '' ? $driver['phone'] : null, $driver !== null && (string) ($driver['license'] ?? '') !== '' ? $driver['license'] : null,
                $p['cf'], $p['subtotal'], $p['fee'], $p['labor'], $p['pads'], $p['paid'], $p['total'], $p['remarks'] === '' ? null : $p['remarks'], $p['paid_on'], $source, Auth::id(), $created, $now,
            ]
        );
        $order = 0;
        foreach ($p['lines'] as $l) {
            Database::execute(
                'INSERT INTO invoice_lines (invoice_id, sort_order, job_id, job_number, status, customer, phone, from_location, to_location, broker, carrier, driver, cf, rate, fee, balance, remarks)
                 VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)',
                [
                    $id, ++$order, $l['job_id'], $l['job_number'], $l['customer'], $l['phone'] === '' ? null : $l['phone'], $l['from'], $l['to'],
                    $isDriver ? ($l['other'] === '' ? null : $l['other']) : $broker, $isDriver ? ($p['party'] === '' ? null : $p['party']) : ($l['other'] === '' ? null : $l['other']),
                    $l['cf'], $l['rate'], $l['fee'], $l['balance'], $l['remarks'] === '' ? null : $l['remarks'],
                ]
            );
        }
    }
}
