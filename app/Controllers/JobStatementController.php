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
 * The carrier / driver statement (client request, v1.11.0): pick a carrier and/or a driver, optional dates. The rate per CF comes from each job.
 * It shows balances, so the route needs the permission to see them (jobs.view_financials); the CSV download also needs the export permission.
 */
final class JobStatementController extends Controller
{
    public function index(Request $request): Response
    {
        $carrier = trim($request->queryStr('carrier'));
        $driver = trim($request->queryStr('driver'));
        $broker = trim($request->queryStr('company'));
        $from = CarrierStatement::parseDate($request->queryStr('from'), 'From date');
        $to = CarrierStatement::parseDate($request->queryStr('to'), 'To date');
        $labor = CarrierStatement::parseMoney($request->queryStr('labor'), 'Labor cost');
        $pads = CarrierStatement::parseMoney($request->queryStr('pads'), 'Pads');
        $paid = CarrierStatement::parseMoney($request->queryStr('paid'), 'Paid');
        $asked = $request->queryStr('go') !== '' || $request->queryStr('format') !== '';

        $errors = [];
        if ($asked) {
            if ($carrier === '' && $driver === '' && $broker === '') {
                $errors[] = 'Choose a carrier, a driver or a company.';
            }
            foreach ([$from['error'], $to['error'], $labor['error'], $pads['error'], $paid['error']] as $e) {
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
            $statement = CarrierStatement::build($carrier, $driver, $broker, $from['value'], $to['value'], $labor['cents'], $pads['cents'], $paid['cents']);
            if ($request->queryStr('format') === 'csv') {
                if (!can('jobs.export')) {
                    return ErrorPages::response(403);
                }
                return $this->csv($statement, $carrier, $driver, $broker, $from['value'], $to['value']);
            }
        }

        return $this->view('jobs/statement', [
            'title' => 'Carrier Statement',
            'carriers' => CarrierStatement::carriers(),
            'drivers' => CarrierStatement::drivers(),
            'carrier' => $carrier,
            'driver' => $driver,
            'company' => $broker,
            'labor' => $labor['value'],
            'pads' => $pads['value'],
            'paid' => $paid['value'],
            'companies' => CarrierStatement::brokers(),
            'from' => $from['value'],
            'to' => $to['value'],
            'errors' => $errors,
            'statement' => $statement,
            'canExport' => can('jobs.export'),
        ]);
    }

    /** @param array{rows:array<int,array<string,mixed>>,totals:array<string,mixed>,truncated:bool} $statement */
    private function csv(array $statement, string $carrier, string $driver, string $broker, string $from, string $to): Response
    {
        AuditService::record('statement_exported', 'job', null, null, [
            'carrier' => $carrier, 'driver' => $driver, 'company' => $broker, 'from' => $from, 'to' => $to, 'rows' => count($statement['rows']),
        ]);
        $name = 'statement-' . preg_replace('/[^A-Za-z0-9]+/', '-', trim($carrier . ' ' . $driver . ' ' . $broker)) . '-' . \App\Core\Clock::today() . '.csv';

        return Response::stream(static function () use ($statement): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // Text cells are made safe against spreadsheet formulas; the money and CF columns are our own numbers (a negative one is fine).
            $text = static fn (string $v): string => ExportService::safeCell($v);
            fputcsv($out, ['Job #', 'Status', 'Customer', 'Phone', 'From', 'To', 'A-CF', 'Rate', 'CF Pay', 'Bal. Due', 'Remarks', 'Company', 'Carrier', 'Driver'], ',', '"', '');
            foreach ($statement['rows'] as $r) {
                fputcsv($out, [
                    $text($r['job_number']), (string) \App\Core\Config::get('statuses.statuses.' . $r['status'] . '.label', $r['status']),
                    $text($r['customer']), $text($r['phone']), $text($r['from']), $text($r['to']),
                    (string) ($r['cf'] ?? ''), (string) ($r['rate'] ?? ''), (string) ($r['fee'] ?? ''), $r['balance'], $text($r['remarks']), $text($r['broker']), $text($r['carrier']), $text($r['driver']),
                ], ',', '"', '');
            }
            $t = $statement['totals'];
            fputcsv($out, ['TOTALS', '', '', '', '', '', $t['cf'], '', $t['fee'], $t['subtotal'], '', '', '', ''], ',', '"', '');
            foreach (['labor' => 'Labor cost (subtracted)', 'pads' => 'Pads (subtracted)', 'paid' => 'Paid (added; a minus takes money off)'] as $key => $label) {
                if ($t[$key] !== '0.00') {
                    fputcsv($out, [$label, '', '', '', '', '', '', '', '', $t[$key], '', '', '', ''], ',', '"', '');
                }
            }
            fputcsv($out, ['TOTAL DUE', '', '', '', '', '', '', '', '', $t['due'], '', '', '', ''], ',', '"', '');
            $dir = CarrierStatement::direction($t['due'], (int) $t['incomplete'] === 0);
            fputcsv($out, [$dir['text'], '', '', '', '', '', '', '', '', $dir['code'] === 'settled' ? '' : $dir['amount'], '', '', '', ''], ',', '"', '');
            fclose($out);
        }, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"']);
    }
}
