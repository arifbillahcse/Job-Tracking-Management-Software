<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Repositories\JobRepository;
use App\Repositories\StatusHistoryRepository;

/**
 * Figures for the dashboard. Section counts use JobBucket (through JobCriteria), exactly like the
 * list pages, so a card's number always equals the length of the list it links to.
 * Job rows are reduced to non-financial columns here, so nothing sensitive can reach the view.
 */
final class DashboardService
{
    public function __construct(
        private JobRepository $jobs = new JobRepository(),
        private StatusHistoryRepository $history = new StatusHistoryRepository()
    ) {
    }

    /**
     * @return array{
     *   counts:array<string,int>, statuses:array<string,int>, overdue_count:int,
     *   overdue:array<int,array<string,mixed>>, upcoming:array<int,array<string,mixed>>,
     *   money:array{balance:string,storage_fee:string}|null, activity:array<int,array<string,mixed>>
     * }
     */
    public function build(bool $withFinancials): array
    {
        $counts = [];
        foreach (array_keys(JobBucket::definitions()) as $bucket) {
            $counts[$bucket] = $this->jobs->count(JobCriteria::forBucket($bucket));
        }

        return [
            'counts' => $counts,
            'statuses' => $this->jobs->statusCounts(),
            'overdue_count' => $this->jobs->overdueCount(),
            'overdue' => array_map([$this, 'brief'], $this->jobs->readySlice(true, 5)),
            'upcoming' => array_map([$this, 'brief'], $this->jobs->readySlice(false, 5)),
            'money' => $withFinancials ? $this->jobs->moneyTotals() : null,
            'activity' => $this->activity(8),
        ];
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function brief(array $job): array
    {
        return [
            'id' => (int) $job['id'],
            'job_number' => $job['job_number'],
            'customer' => $job['customer_first_name'] . ' ' . $job['customer_last_name'],
            'first_available_date' => $job['first_available_date'],
            'from_location' => $job['from_location'],
            'to_location' => $job['to_location'],
            'status' => $job['status'],
        ];
    }

    /**
     * Latest status changes and newly created jobs, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    private function activity(int $limit): array
    {
        $labels = array_map(static fn (array $s): string => (string) $s['label'], (array) Config::get('statuses.statuses', []));
        $events = [];

        foreach ($this->history->latest($limit) as $row) {
            $events[] = [
                'at' => (string) $row['created_at'],
                'job_id' => (int) $row['job_id'],
                'job_number' => $row['job_number'],
                'customer' => $row['customer_first_name'] . ' ' . $row['customer_last_name'],
                'text' => ($labels[$row['from_status']] ?? $row['from_status']) . ' → ' . ($labels[$row['to_status']] ?? $row['to_status']),
                'by' => $row['changed_by_name'],
                'to_status' => $row['to_status'],
            ];
        }
        foreach ($this->jobs->recentlyCreated($limit) as $row) {
            $events[] = [
                'at' => (string) $row['created_at'],
                'job_id' => (int) $row['id'],
                'job_number' => $row['job_number'],
                'customer' => $row['customer_first_name'] . ' ' . $row['customer_last_name'],
                'text' => 'Job created',
                'by' => $row['created_by_name'],
                'to_status' => null,
            ];
        }

        usort($events, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
        return array_slice($events, 0, $limit);
    }
}
