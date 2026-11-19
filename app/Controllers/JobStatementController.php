<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\CarrierStatement;
use App\Services\ExportService;

/**
 * The carrier / driver statement (client request, v1.11.0): pick a carrier and/or a driver, type the rate per cubic foot, optional dates.
 * It shows balances, so the route needs the permission to see them (jobs.view_financials); the CSV download also needs the export permission.
 */
final class JobStatementController extends Controller
{
    public function index(Request $request): Response
    {
        $carrier = trim($request->queryStr('carrier'));
        $driver = trim($request->queryStr('driver'));
        $from = CarrierStatement::parseDate($request->queryStr('from'), 'From date');
        $to = CarrierStatement::parseDate($request->queryStr('to'), 'To date');
        $asked = $request->queryStr('go') !== '' || $request->queryStr('format') !== '';
        $rate = CarrierStatement::parseRate($request->queryStr('rate'));

        $errors = [];
        if ($asked) {
            if ($carrier === '' && $driver === '') {
                $errors[] = 'Choose a carrier or a driver.';
            }
            foreach ([$rate['error'], $from['error'], $to['error']] as $e) {
                if ($e !== null) {
                    $errors[] = $e;
                }
            }
            if ($from['value'] !== '' && $to['value'] !== '' && $from['value'] > $to['value']) {
                $errors[] = 'The From date is after the To date.';
            }
        }

        $statement = null;
        if ($asked && $errors === []) {
            $statement = CarrierStatement::build($carrier, $driver, $from['value'], $to['value'], $rate['value']);
            if ($request->queryStr('format') === 'csv') {
                if (!can('jobs.export')) {
                    return ErrorPages::response(403);
                }
                return $this->csv($statement, $carrier, $driver, $from['value'], $to['value'], $rate['value']);
            }
        }

        return $this->view('jobs/statement', [
            'title' => 'Carrier Statement',
            'carriers' => CarrierStatement::carriers(),
            'drivers' => CarrierStatement::drivers(),
            'carrier' => $carrier,
            'driver' => $driver,
            'from' => $from['value'],
            'to' => $to['value'],
            'rate' => $request->queryStr('rate'),
            'errors' => $errors,
            'statement' => $statement,
            'canExport' => can('jobs.export'),
        ]);
    }

    /** @param array{rows:array<int,array<string,mixed>>,totals:array<string,mixed>,truncated:bool} $statement */
    private function csv(array $statement, string $carrier, string $driver, string $from, string $to, string $rate): Response
    {
        AuditService::record('statement_exported', 'job', null, null, [
            'carrier' => $carrier, 'driver' => $driver, 'from' => $from, 'to' => $to, 'rate' => $rate, 'rows' => count($statement['rows']),
        ]);
        $name = 'statement-' . preg_replace('/[^A-Za-z0-9]+/', '-', trim($carrier . ' ' . $driver)) . '-' . \App\Core\Clock::today() . '.csv';

        return Response::stream(static function () use ($statement, $rate): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // Text cells are made safe against spreadsheet formulas; the money and CF columns are our own numbers (a negative one is fine).
            $text = static fn (string $v): string => ExportService::safeCell($v);
            fputcsv($out, ['Job #', 'Status', 'Customer', 'Phone', 'From', 'To', 'A-CF', 'Rate', 'CF Pay', 'Bal. Due', 'Remarks'], ',', '"', '');
            foreach ($statement['rows'] as $r) {
                fputcsv($out, [
                    $text($r['job_number']), (string) \App\Core\Config::get('statuses.statuses.' . $r['status'] . '.label', $r['status']),
                    $text($r['customer']), $text($r['phone']), $text($r['from']), $text($r['to']),
                    (string) ($r['cf'] ?? ''), $rate, (string) ($r['fee'] ?? ''), $r['balance'], $text($r['remarks']),
                ], ',', '"', '');
            }
            $t = $statement['totals'];
            fputcsv($out, ['TOTALS', '', '', '', '', '', $t['cf'], '', $t['fee'], $t['subtotal'], ''], ',', '"', '');
            fputcsv($out, ['TOTAL DUE (Bal. Due total - CF Pay total)', '', '', '', '', '', '', '', '', $t['due'], ''], ',', '"', '');
            fclose($out);
        }, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"']);
    }
}
