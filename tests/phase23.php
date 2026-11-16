<?php

declare(strict_types=1);

/**
 * v1.8.1 (calendar phase 2): the pickup calendar of Future Jobs. Included by tests/run.php (after phase22.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Repositories\ForemanRepository;

section('Pickup calendar');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
set_setting('section_basis', 'pickup');
$mgr = make_user('Manager', 'p23-mgr@example.com');
$kenth = (new ForemanRepository())->create(['name' => 'Kenth', 'phone' => null]);
$joe = (new ForemanRepository())->create(['name' => 'Joe', 'phone' => null]);
sign_in($mgr);
$mk = static function (string $n, ?string $pickup, string $status = 'booked', array $o = []): int {
    return mk_job($o + ['job_number' => $n, 'pickup_date' => $pickup, 'first_available_date' => $pickup ?? '2026-12-30', 'status' => $status, 'customer_first_name' => 'Ada', 'customer_last_name' => 'Smith']);
};
$mk('CAL-A', '2026-11-17', 'booked', ['foreman_id' => $kenth, 'cf' => '350', 'balance' => '1250.50', 'pickup_time' => '08-10AM', 'miles' => '593', 'confirmed' => 1, 'bill_of_lading' => 1, 'from_location' => 'Parsons, TN', 'to_location' => 'Graham, NC']);
$mk('CAL-B', '2026-11-17', 'booked', ['foreman_id' => $joe]);                       // same day, second job
$mk('CAL-C', '2026-11-30', 'booked', ['foreman_id' => $kenth]);                     // last day of the month
$mk('CAL-D', '2026-12-02', 'booked', ['customer_first_name' => '<b>Evil</b>']);       // next month, no foreman, hostile name
$mk('CAL-HOLD', '2026-11-20', 'on_hold');
$mk('CAL-CANCEL', '2026-11-21', 'cancelled');
$mk('CAL-ROAD', '2026-11-22', 'in_transit');
$mk('CAL-DONE', '2026-11-23', 'delivered');
$mk('CAL-PAST', '2026-11-10', 'booked');                                            // already picked up
$mk('CAL-TODAY', '2026-11-16', 'booked');                                           // today is not "after today"
$mk('CAL-NODATE', null, 'booked');
$page = call('GET', '/jobs/calendar');
$html = $page->body();
$pt = plain($html);
check($page->status() === 200 && str_contains($pt, 'Future Jobs (Pick-up): 3') && str_contains($pt, 'November 2026'), 'November 2026 shows "Future Jobs (Pick-up): 3" (CAL-A, CAL-B, CAL-C)');
foreach (['CAL-A', 'CAL-B', 'CAL-C'] as $n) {
    check(str_contains($html, '>' . $n . '</a>'), "{$n} is on the calendar");
}
foreach (['CAL-D', 'CAL-HOLD', 'CAL-CANCEL', 'CAL-ROAD', 'CAL-DONE', 'CAL-PAST', 'CAL-TODAY', 'CAL-NODATE'] as $n) {
    check(!str_contains($html, '>' . $n . '</a>'), "{$n} is not on the November calendar (next month, on hold, cancelled, in transit, delivered, picked up, today, or no pickup date)");
}
check(substr_count($html, 'jt-cal-head') === 7 && preg_match('~Sun.*?Mon.*?Tue.*?Wed.*?Thu.*?Fri.*?Sat~s', $pt) === 1, 'the grid has the seven weekday headings, Sunday first');
check(substr_count($html, 'jt-cal-blank') === 5 && substr_count($html, 'class="jt-cal-day') - substr_count($html, 'jt-cal-blank') === 30, 'November 2026 starts on a Sunday: 30 day cells and 5 blanks to finish the last week (29th and 30th, then 5 empty cells)');
check(preg_match('~jt-cal-today.{0,300}<span class="jt-cal-num">16</span>~s', $html) === 1, 'today (16) is highlighted');
// the 17th holds both jobs, numbered 1 and 2
check(preg_match('~<span class="jt-cal-num">17</span>.*?<span class="jt-cal-count"[^>]*>2</span>.*?CAL-A.*?CAL-B~s', $html) === 1 && preg_match('~jt-cal-ord">1</span>\s*<a class="jt-jobno" href="/jobs/\d+">CAL-A~s', $html) === 1, 'the 17th shows both jobs, a count of 2, and the jobs numbered 1 and 2');
$cell = preg_match('~CAL-A</a>.*?(?=jt-cal-ord">2|$)~s', $html, $m) ? plain($m[0]) : '';
check(str_contains($cell, '08-10AM') && str_contains($cell, 'Ada Smith') && str_contains($cell, 'Parsons, TN → Graham, NC') && str_contains($cell, '593 mi / 350 cf') && str_contains($cell, 'Kenth') && str_contains($cell, '$1,250.50') && str_contains($cell, '✓ Confirmed') && str_contains($cell, '✓ Bill of Lading') && !str_contains($cell, 'E-Signed') && !str_contains($cell, 'Job Docs'), 'a job shows pickup time, customer, route, miles / cubic feet, foreman, balance and only its ticked checks: ' . $cell);
// month navigation
check(str_contains($html, 'month=2026-10') && str_contains($html, 'month=2026-12') && str_contains($pt, 'Previous Month') && str_contains($pt, 'Next Month') && str_contains($pt, 'Today'), 'Previous Month, Today and Next Month links are there');
$dec = call('GET', '/jobs/calendar', [], ['month' => '2026-12'])->body();
check(str_contains(plain($dec), 'Future Jobs (Pick-up): 1') && str_contains(plain($dec), 'December 2026') && str_contains($dec, '>CAL-D</a>') && substr_count($dec, 'jt-cal-blank') === 4 && substr_count($dec, 'class="jt-cal-day') - substr_count($dec, 'jt-cal-blank') === 31, 'December 2026: one job (CAL-D), starting on a Tuesday (2 blanks first, 2 after, 31 day cells)');
check(str_contains($dec, '&lt;b&gt;Evil&lt;/b&gt;') && !str_contains($dec, '<b>Evil</b>'), 'a customer name is escaped, never HTML');
check(str_contains(plain(call('GET', '/jobs/calendar', [], ['month' => '2026-10'])->body()), 'Future Jobs (Pick-up): 0'), 'a month with no future jobs shows 0');
foreach (['nonsense', '2026-13', '1999-01', '2026-1', "' OR 1=1 --", '2026-11-01'] as $bad) {
    $r = call('GET', '/jobs/calendar', [], ['month' => $bad]);
    check($r->status() === 200 && str_contains(plain($r->body()), 'November 2026'), 'a bad month value (' . substr($bad, 0, 12) . ') falls back to this month');
}
// foreman filter
$k = plain(call('GET', '/jobs/calendar', [], ['foreman' => (string) $kenth])->body());
check(str_contains($k, 'Future Jobs (Pick-up): 2') && str_contains($k, 'CAL-A') === true, 'the foreman filter keeps only that foreman\'s jobs (Kenth: 2)');
$none = call('GET', '/jobs/calendar', [], ['month' => '2026-12', 'foreman' => 'none'])->body();
check(str_contains(plain($none), 'Future Jobs (Pick-up): 1') && str_contains(plain($none), 'No foreman'), 'the "No foreman assigned" filter finds the job without one');
check(str_contains($html, 'name="month" value="2026-11"') && str_contains($html, 'id="cal-foreman"'), 'the filter form keeps the month');
// the count agrees with the Future Jobs list
$all = call('GET', '/jobs/future', [], ['per_page' => '100'])->body();
check(str_contains(plain($all), 'of 4 jobs'), 'Future Jobs list: 4 jobs = 3 in November + 1 in December, the same jobs as the calendar months');
// tabs
check(str_contains($html, 'jt-tab-calendar') && str_contains($html, 'href="/jobs/calendar"') && str_contains(call('GET', '/jobs/future')->body(), 'href="/jobs/calendar"'), 'a Calendar button is next to the section tabs on the calendar and on the lists');
// money is for people who may see it
make_role('ViewOnly23', ['jobs.view']);
sign_in(make_user('ViewOnly23', 'p23-view@example.com'));
$v = call('GET', '/jobs/calendar');
check($v->status() === 200 && !str_contains($v->body(), '$1,250.50') && str_contains($v->body(), '>CAL-A</a>'), 'a user who may not see money sees the jobs but no balance');
make_role('NoPerms23', []);
sign_in(make_user('NoPerms23', 'p23-none@example.com'));
check(call('GET', '/jobs/calendar')->status() === 403, 'a user without jobs.view gets 403');
