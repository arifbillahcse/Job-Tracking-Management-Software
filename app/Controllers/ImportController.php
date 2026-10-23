<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ImportRepository;
use App\Services\ExportService;
use App\Services\Import\JobFields;
use App\Services\Import\XlsxWriter;
use App\Services\ImportService;
use App\Services\JobService;

/**
 * Import (jobs.import): upload -> preview -> confirm -> result, plus the downloadable template and error report.
 * Batches belong to the user who uploaded them; anyone else gets a 404.
 */
final class ImportController extends Controller
{
    private ImportService $service;
    private ImportRepository $batches;

    public function __construct()
    {
        $this->service = new ImportService();
        $this->batches = new ImportRepository();
    }

    public function index(Request $request): Response
    {
        return $this->view('import/index', [
            'title' => 'Import Jobs',
            'recent' => $this->batches->recentForUser((int) Auth::id(), 8),
            'fields' => JobFields::all(),
            'financials' => Auth::can('jobs.edit_financials'),
            'workflow' => Auth::can('jobs.status'),
            'formats' => ImportService::formats(),
            'maxBytes' => ImportService::maxBytes(),
            'maxRows' => ImportService::MAX_ROWS,
        ]);
    }

    public function upload(Request $request): Response
    {
        $result = $this->service->stage($request->file('file'), (int) Auth::id());
        if (!$result['ok']) {
            $this->flash('danger', (string) $result['error']);
            return $this->redirect('/jobs/import');
        }
        return $this->redirect('/jobs/import/' . $result['batch']);
    }

    /** Preview while previewed; the result once committed. */
    public function show(Request $request, int $id): Response
    {
        $batch = $this->batches->findForUser($id, (int) Auth::id());
        if ($batch === null) {
            return ErrorPages::response(404);
        }

        return $this->view('import/show', [
            'title' => $batch['status'] === 'committed' ? 'Import result' : 'Import preview',
            'batch' => $batch,
            'errors' => $this->batches->errors($id, 100),
            'fields' => JobFields::all(),
        ]);
    }

    public function commit(Request $request, int $id): Response
    {
        $result = $this->service->commit($id, (int) Auth::id());

        if ($result['ok']) {
            $this->flash($result['skipped'] > 0 ? 'warning' : 'success',
                number_format($result['imported']) . ' job(s) imported' . ($result['skipped'] > 0 ? ', ' . number_format($result['skipped']) . ' row(s) skipped (see the problem report).' : '.'));
            return $this->redirect('/jobs/import/' . $id);
        }

        return match ($result['code']) {
            'not_found' => ErrorPages::response(404),
            'already' => $this->back($id, 'info', 'This import has already been processed.'),
            'expired' => $this->back(0, 'danger', 'This preview expired, so nothing was imported. Please upload the file again.'),
            default => $this->back($id, 'danger', 'The file could not be read again. Nothing was imported.'),
        };
    }

    public function cancel(Request $request, int $id): Response
    {
        if (!$this->service->cancel($id, (int) Auth::id())) {
            return $this->back(0, 'info', 'That import can no longer be cancelled.');
        }
        $this->flash('info', 'The import was cancelled and the uploaded file deleted. Nothing was imported.');
        return $this->redirect('/jobs/import');
    }

    /** Every problem row with its original cells and the reasons, as a CSV the user can fix and upload again. */
    public function errors(Request $request, int $id): Response
    {
        $batch = $this->batches->findForUser($id, (int) Auth::id());
        if ($batch === null) {
            return ErrorPages::response(404);
        }

        $errors = $this->batches->errors($id);
        $headers = array_map('strval', $batch['headers']);

        return Response::stream(static function () use ($errors, $headers): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Row', 'Problems', ...$headers], ',', '"', '');
            foreach ($errors as $error) {
                $cells = array_pad($error['raw'], count($headers), '');
                fputcsv($out, array_map([ExportService::class, 'safeCell'], [(string) $error['row_no'], implode(' ', $error['messages']), ...array_map('strval', $cells)]), ',', '"', '');
            }
            fclose($out);
        }, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="import-' . $id . '-problems.csv"',
        ]);
    }

    public function template(Request $request, string $format): Response
    {
        $financials = Auth::can('jobs.edit_financials');
        [$headers, $example] = ExportService::templateRows($financials);

        if ($format === 'xlsx' && in_array('xlsx', ImportService::formats(), true)) {
            $columns = array_keys(array_filter(JobFields::all(), static fn (array $f): bool => $financials || !$f['financial']));
            $types = array_map(static fn (string $c): string => match (JobFields::all()[$c]['type']) {
                JobFields::DATE => XlsxWriter::DATE, JobFields::MONEY => XlsxWriter::MONEY, JobFields::NUMBER => XlsxWriter::NUMBER, default => XlsxWriter::TEXT,
            }, $columns);
            $file = tempnam(sys_get_temp_dir(), 'tpl');
            XlsxWriter::write((string) $file, 'Jobs', $headers, $types, [$example]);
            return Response::stream(static function () use ($file): void {
                readfile((string) $file);
                @unlink((string) $file);
            }, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="job-import-template.xlsx"',
            ]);
        }
        if ($format !== 'csv') {
            return ErrorPages::response(404);
        }

        return Response::stream(static function () use ($headers, $example): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '');
            fputcsv($out, $example, ',', '"', '');
            fclose($out);
        }, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="job-import-template.csv"',
        ]);
    }

    private function back(int $batchId, string $type, string $message): Response
    {
        $this->flash($type, $message);
        return $this->redirect($batchId > 0 ? '/jobs/import/' . $batchId : '/jobs/import');
    }
}
