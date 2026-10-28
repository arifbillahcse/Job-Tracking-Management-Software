<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Paginator;
use App\Repositories\JobRepository;
use App\Services\JobBucket;
use App\Services\JobCriteria;
use App\Services\JobService;
use App\Services\JobWorkflowService;

/**
 * The five job lists (All, Ready, More Than 30 Days, In Transit, Delivered). They share one engine:
 * the section comes from JobBucket, search/filter/sort from JobCriteria, SQL from JobRepository.
 */
final class JobListController extends Controller
{
    public function all(Request $request): Response
    {
        return $this->list($request, JobBucket::ALL);
    }

    public function ready(Request $request): Response
    {
        return $this->list($request, JobBucket::READY);
    }

    public function later(Request $request): Response
    {
        return $this->list($request, JobBucket::LATER);
    }

    public function transit(Request $request): Response
    {
        return $this->list($request, JobBucket::TRANSIT);
    }

    public function delivered(Request $request): Response
    {
        return $this->list($request, JobBucket::DELIVERED);
    }

    private function list(Request $request, string $bucket): Response
    {
        \App\Services\StorageFeeService::refreshIfDue();
        $service = new JobService();
        $workflow = new JobWorkflowService();
        $financials = $service->canSeeFinancials();

        $criteria = JobCriteria::fromRequest($request, $bucket, $financials);
        $total = $service->count($criteria);
        $paginator = new Paginator($total, $criteria->perPage, $criteria->page);
        $jobs = $total === 0 ? [] : $service->search($criteria, $criteria->perPage, $paginator->offset());

        // One-click button per row: Move to In Transit for booked jobs, Mark as Delivered for jobs in transit.
        $shortcuts = [];
        foreach ($jobs as $job) {
            $to = ['booked' => 'in_transit', 'in_transit' => 'delivered'][$job['status']] ?? null;
            if ($to !== null && $workflow->canMove($job, $to)) {
                $shortcuts[(int) $job['id']] = ['to' => $to] + (array) \App\Core\Config::get("statuses.transitions.{$job['status']}.{$to}");
            }
        }

        $repo = new JobRepository();
        $tabCounts = [];
        foreach (array_keys(JobBucket::definitions()) as $key) {
            $tabCounts[$key] = $repo->count(JobCriteria::forBucket($key));
        }

        return $this->view('jobs/list', [
            'title' => JobBucket::label($bucket),
            'bucket' => $bucket,
            'criteria' => $criteria,
            'jobs' => $jobs,
            'paginator' => $paginator,
            'tabCounts' => $tabCounts,
            'shortcuts' => $shortcuts,
            'showFinancials' => $financials,
            'foremen' => (new \App\Repositories\ForemanRepository())->allForFilter(),
        ]);
    }
}
