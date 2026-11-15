<?php

declare(strict_types=1);

/**
 * v1.7.1: a job counted once however many daily entries it has; the dashboard's Overdue card matches the list it links to. Included by tests/run.php.
 */

use App\Core\Clock;
use App\Core\Database;
use App\Repositories\ForemanRepository;
use App\Repositories\JobRepository;
use App\Services\TripCriteria;
use App\Services\TripService;

section('Jobs are counted once, entries separately');
fresh_db();
fresh_session();
Clock::fake('2026-11-15');
$mgr = make_user('Manager', 'p21-mgr@example.com');
$joe = (new ForemanRepository())->create(['name' => 'Joe', 'phone' => null]);
sign_in($mgr);
call('POST', '/trips', ['trip_number' => 'K-21', 'foreman_id' => (string) $joe, 'begins_on' => '2026-11-10', 'notes' => '']);
$tid = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'K-21'");
foreach (['C-1', 'C-2'] as $n) {
    mk_job(['job_number' => $n]);
}
foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $d) {
    call('POST', "/trips/{$tid}/jobs", ['job_number' => 'C-1', 'job_date' => $d, 'diesel' => '10']);
}
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'C-2', 'job_date' => '2026-11-10', 'diesel' => '5']);
$page = plain(call('GET', "/trips/{$tid}")->body());
check(str_contains($page, 'Jobs on this trip (2, 4 entries)'), 'Trip Details: 2 jobs on 4 entries (job C-1 has three days)');
$list = plain(call('GET', '/trips', [], ['q' => 'K-21'])->body());
check(preg_match('~K-21.*?\b2\b\s*4 entries~', $list) === 1, 'the Trips list shows 2 jobs and "4 entries"');
$rep = (new TripService())->foremanReport(TripCriteria::fromRequest(\App\Core\Request::make('GET', '/trips/report', [], [])));
check($rep['rows'][0]['jobs'] === 2 && $rep['rows'][0]['entries'] === 4 && $rep['totals']['jobs'] === 2 && $rep['totals']['entries'] === 4, 'the foreman report counts 2 jobs and 4 entries');
$rp = plain(call('GET', '/trips/report')->body());
check(str_contains($rp, 'Entries') && preg_match('~Joe\s*1\s*2\s*4~', $rp) === 1, 'the report page has an Entries column (Joe: 1 trip, 2 jobs, 4 entries)');
check(str_contains(call('GET', '/trips/report/export')->content(), 'Foreman,Trips,Jobs,Entries'), 'and so does its CSV');
$dash = plain(call('GET', '/dashboard')->body());
check(preg_match('~Foreman trips.*?Jobs\s*2\s*4 entries~s', $dash) === 1, 'the dashboard shows 2 jobs and 4 entries');

section('Dashboard Overdue matches the list it links to');
fresh_db();
fresh_session();
Clock::fake('2026-11-15');
set_setting('section_basis', 'pickup');
$mgr = make_user('Manager', 'p21b-mgr@example.com');
sign_in($mgr);
$mk = static function (string $n, string $pickup, string $avail, string $status = 'booked'): void {
    mk_job(['job_number' => $n, 'pickup_date' => $pickup, 'first_available_date' => $avail, 'status' => $status]);
};
$mk('O-READY', '2026-11-05', '2026-11-10');     // ready, 1st available passed
$mk('O-LATER', '2026-08-01', '2026-08-20');     // more than 30 days, passed
$mk('O-FUTURE', '2026-12-05', '2026-11-10');    // future pickup but its 1st available date passed
$mk('U-TODAY', '2026-11-10', '2026-11-15');     // due today = upcoming, not overdue
$mk('U-LATER', '2026-12-01', '2026-12-10');     // upcoming
$mk('O-TRANSIT', '2026-11-01', '2026-11-02', 'in_transit'); // on the road: not overdue
$mk('O-DONE', '2026-10-01', '2026-10-02', 'delivered');
$dash = call('GET', '/dashboard')->body();
$count = (int) (preg_match('~Overdue\s*<span class="badge text-bg-danger">(\d+)</span>~', $dash, $m) ? $m[1] : -1);
check($count === 3, 'the Overdue card counts the 3 booked jobs whose 1st available date has passed: ' . $count);
check(str_contains($dash, 'href="/jobs/booked?overdue=1') && str_contains($dash, 'View all overdue'), 'its link opens the Booked Jobs list filtered to overdue jobs');
$html = call('GET', '/jobs/booked', [], ['overdue' => '1'])->body();
preg_match_all('~class="jt-jobno" href="/jobs/\d+">([A-Z]-[A-Z]+)</a>~', $html, $mm);
$shown = array_values(array_unique($mm[1]));
sort($shown);
check($shown === ['O-FUTURE', 'O-LATER', 'O-READY'] && str_contains(plain($html), 'of 3 jobs'), 'that list has exactly the same 3 jobs: ' . implode(',', $shown));
check(str_contains($html, 'id="f-overdue"') && str_contains($html, 'checked'), 'the filter panel shows the Overdue only box ticked');
$d2 = call('GET', '/jobs/booked')->body();
check(str_contains(plain($d2), 'of 6 jobs') || str_contains(plain($d2), 'of 7 jobs'), 'without the filter the list is longer again');
$slice = (new JobRepository())->readySlice(false, 10);
$nums = array_column($slice, 'job_number');
sort($nums);
check($nums === ['U-LATER', 'U-TODAY'], 'Coming up next = booked jobs whose 1st available date is today or later: ' . implode(',', $nums));
check(str_contains($dash, 'href="/jobs/booked?sort=available') && str_contains($dash, 'View booked jobs'), 'its link opens the booked list sorted by 1st available date');
