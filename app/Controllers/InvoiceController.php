<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Paginator;
use App\Services\CarrierStatement;
use App\Services\InvoiceService;
use App\Services\JobService;

/**
 * Saved invoices (v1.16.0): the list, one invoice (printable), and saving a Carrier Statement as an invoice. Seeing needs
 * invoices.view, changing needs invoices.manage (route level); saving also needs the right to see money, because the lines hold balances.
 */
final class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim($request->queryStr('q')),
            'status' => in_array($request->queryStr('status'), ['open', 'paid', 'void'], true) ? $request->queryStr('status') : '',
            'type' => isset(InvoiceService::TYPES[$request->queryStr('type')]) ? $request->queryStr('type') : '',
            'from' => trim($request->queryStr('from')),
            'to' => trim($request->queryStr('to')),
        ];
        foreach (['from', 'to'] as $key) {
            if (CarrierStatement::parseDate($filters[$key], '')['error'] !== null) {
                $filters[$key] = '';
            }
        }
        $count = (int) InvoiceService::search($filters, 1, 0)['total'];
        $paginator = new Paginator($count, InvoiceService::PER_PAGE, max(1, $request->queryInt('page', 1)));
        $result = InvoiceService::search($filters, InvoiceService::PER_PAGE, $paginator->offset());

        return $this->view('invoices/index', [
            'title' => 'Invoices',
            'filters' => $filters,
            'invoices' => $result['rows'],
            'paginator' => $paginator,
            'sum' => $result['sum'],
            'openSum' => $result['open_sum'],
            'canManage' => can('invoices.manage'),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $invoice = InvoiceService::find($id);
        if ($invoice === null) {
            return ErrorPages::response(404);
        }
        return $this->view('invoices/show', [
            'title' => 'Invoice ' . InvoiceService::number($invoice),
            'invoice' => $invoice,
            'lines' => InvoiceService::lines($id),
            'number' => InvoiceService::number($invoice),
            'status' => InvoiceService::status($invoice),
            'direction' => CarrierStatement::direction(number_format((float) $invoice['total'], 2, '.', '')),
            'children' => InvoiceService::children($id),
            'source' => $invoice['source_invoice_id'] === null ? null : InvoiceService::find((int) $invoice['source_invoice_id']),
            'canManage' => can('invoices.manage'),
        ]);
    }

    /** Save the statement the person is looking at (its filters are posted again; everything is recomputed from the jobs). */
    public function store(Request $request): Response
    {
        if (!(new JobService())->canSeeFinancials()) {
            return ErrorPages::response(403);
        }
        $carrier = trim($request->str('carrier'));
        $driver = trim($request->str('driver'));
        $broker = trim($request->str('company'));
        $from = CarrierStatement::parseDate($request->str('from'), 'From date');
        $to = CarrierStatement::parseDate($request->str('to'), 'To date');
        $labor = CarrierStatement::parseMoney($request->str('labor'), 'Labor cost');
        $pads = CarrierStatement::parseMoney($request->str('pads'), 'Pads');
        $paid = CarrierStatement::parseMoney($request->str('paid'), 'Paid');
        $back = list_url('/jobs/statement', [
            'carrier' => $carrier, 'driver' => $driver, 'company' => $broker, 'from' => $from['value'], 'to' => $to['value'],
            'labor' => $labor['value'], 'pads' => $pads['value'], 'paid' => $paid['value'], 'go' => '1',
        ]);
        foreach ([$from['error'], $to['error'], $labor['error'], $pads['error'], $paid['error']] as $error) {
            if ($error !== null) {
                $this->flash('danger', $error);
                return $this->redirect($back);
            }
        }

        $result = InvoiceService::create(
            $carrier, $driver, $broker, $from['value'], $to['value'], $labor['cents'], $pads['cents'], $paid['cents'],
            $request->str('remarks'), $request->str('invoice_date'), $request->str('include_invoiced') === '1'
        );
        if (!$result['ok']) {
            $this->flash('danger', (string) $result['error']);
            return $this->redirect($back);
        }
        $this->flash('success', 'Invoice ' . $result['number'] . ' saved.');
        return $this->redirect('/invoices/' . $result['id']);
    }

    public function update(Request $request, int $id): Response
    {
        $result = InvoiceService::update($id, [
            'invoice_date' => $request->str('invoice_date'), 'labor' => $request->str('labor'), 'pads' => $request->str('pads'),
            'paid_amount' => $request->str('paid_amount'), 'remarks' => $request->str('remarks'),
        ]);
        return $this->done($result, $id, 'The invoice was updated.');
    }

    /** Make the company invoices (CI) of a driver invoice. */
    public function companies(Request $request, int $id): Response
    {
        $result = InvoiceService::generateCompanyInvoices($id);
        if (($result['error'] ?? '') === 'not_found') {
            return ErrorPages::response(404);
        }
        if (!$result['ok']) {
            $this->flash('danger', (string) $result['error']);
            return $this->redirect('/invoices/' . $id);
        }
        $skipped = (int) ($result['skipped'] ?? 0);
        $this->flash('success', 'Company invoices made: ' . implode(', ', $result['numbers'] ?? []) . '.' . ($skipped > 0 ? ' ' . $skipped . ' job' . ($skipped === 1 ? ' has' : 's have') . ' no company and ' . ($skipped === 1 ? 'was' : 'were') . ' left out.' : ''));
        return $this->redirect('/invoices/' . $id);
    }

    public function paid(Request $request, int $id): Response
    {
        return $this->done(InvoiceService::markPaid($id, $request->str('paid_on')), $id, 'The invoice is marked as paid.');
    }

    public function open(Request $request, int $id): Response
    {
        return $this->done(InvoiceService::markOpen($id), $id, 'The invoice is marked as not paid.');
    }

    public function void(Request $request, int $id): Response
    {
        return $this->done(InvoiceService::void($id, $request->str('reason')), $id, 'The invoice was voided. Its jobs can be invoiced again.');
    }

    /** @param array{ok:bool,error?:string} $result */
    private function done(array $result, int $id, string $message): Response
    {
        if (($result['error'] ?? '') === 'not_found') {
            return ErrorPages::response(404);
        }
        $this->flash($result['ok'] ? 'success' : 'danger', $result['ok'] ? $message : (string) $result['error']);
        return $this->redirect('/invoices/' . $id);
    }
}
