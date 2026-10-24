<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Services\ExportService;
use App\Services\Import\XlsxReader;
use App\Services\JobBucket;
use App\Services\JobCriteria;
use App\Services\JobService;

/**
 * Export of the list the user is looking at (jobs.export + jobs.view): same section, search, filters and sort.
 */
final class ExportController extends Controller
{
    public function export(Request $request, string $bucket): Response
    {
        if (!JobBucket::isValid($bucket)) {
            return ErrorPages::response(404);
        }
        if (!can('jobs.view')) {
            return ErrorPages::response(403);
        }

        $format = strtolower($request->queryStr('format', 'csv'));
        if (!in_array($format, ['csv', 'xlsx'], true) || ($format === 'xlsx' && !XlsxReader::available())) {
            return ErrorPages::response(404);
        }

        $financials = (new JobService())->canSeeFinancials();
        $criteria = JobCriteria::fromRequest($request, $bucket, $financials);
        $service = new ExportService();

        if ($service->tooLarge($criteria, $format)) {
            $this->flash('danger', 'That is too many rows to export in one file. Narrow the list with a search or filters and try again.');
            return $this->redirect(list_path($bucket, $criteria->linkParams()));
        }

        return $format === 'xlsx' ? $service->xlsx($criteria, $financials) : $service->csv($criteria, $financials);
    }
}
