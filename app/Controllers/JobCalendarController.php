<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ForemanRepository;
use App\Repositories\JobRepository;
use App\Services\JobService;
use DateTimeImmutable;

/**
 * The pickup calendar (client request, v1.8.1): Future Jobs on the day they are picked up, one month at a time.
 *
 * The jobs are exactly the Future Jobs section (JobBucket::FUTURE: booked, pickup date after today), so the month's count
 * agrees with the Future Jobs list. Weeks start on Sunday. The month comes from ?month=YYYY-MM (anything else means this month).
 */
final class JobCalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $today = Clock::today();
        $raw = $request->queryStr('month');
        $first = preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/', $raw) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $raw . '-01', Clock::timezone())
            : DateTimeImmutable::createFromFormat('!Y-m-d', substr($today, 0, 7) . '-01', Clock::timezone());
        if ($first === false) {
            $first = new DateTimeImmutable('first day of this month', Clock::timezone());
        }
        $last = $first->modify('last day of this month');

        $foremanRaw = $request->queryStr('foreman');
        $foremanId = $foremanRaw === 'none' ? -1 : max(0, $request->queryInt('foreman'));

        $jobs = (new JobRepository())->futureInRange($first->format('Y-m-d'), $last->format('Y-m-d'), $foremanId);
        $byDay = [];
        foreach ($jobs as $job) {
            $byDay[substr((string) $job['pickup_date'], 0, 10)][] = $job;
        }

        // Sunday-first weeks, padded with empty cells before the 1st and after the last day.
        $weeks = [];
        $week = array_fill(0, (int) $first->format('w'), null);
        for ($d = $first; $d <= $last; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            $week[] = ['date' => $date, 'day' => (int) $d->format('j'), 'dow' => $d->format('D'), 'today' => $date === $today, 'jobs' => $byDay[$date] ?? []];
            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }
        if ($week !== []) {
            $weeks[] = array_pad($week, 7, null);
        }

        $repo = new JobRepository();
        $tabCounts = [];
        foreach (array_keys(\App\Services\JobBucket::definitions()) as $key) {
            $tabCounts[$key] = $repo->count(\App\Services\JobCriteria::forBucket($key));
        }
        $service = new JobService();

        return $this->view('jobs/calendar', [
            'title' => 'Jobs Calendar',
            'tabCounts' => $tabCounts,
            'weeks' => $weeks,
            'count' => count($jobs),
            'monthLabel' => $first->format('F Y'),
            'month' => $first->format('Y-m'),
            'prevMonth' => $first->modify('-1 month')->format('Y-m'),
            'nextMonth' => $first->modify('+1 month')->format('Y-m'),
            'thisMonth' => substr($today, 0, 7),
            'foremanId' => $foremanId,
            'foremen' => (new ForemanRepository())->allForFilter(),
            'showFinancials' => $service->canSeeFinancials(),
        ]);
    }
}
