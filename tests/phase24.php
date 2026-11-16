<?php

declare(strict_types=1);

/**
 * v1.8.2 (calendar phase 3): second pickups on the calendar, taken from trip entries with the activity Pickup.
 * Included by tests/run.php (after phase23.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Repositories\ForemanRepository;

section('Second pickups on the calendar');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
set_setting('section_basis', 'pickup');
$mgr = make_user('Manager', 'p24-mgr@example.com');
$kenth = (new ForemanRepository())->create(['name' => 'Kenth', 'phone' => null]);
$joe = (new ForemanRepository())->create(['name' => 'Joe', 'phone' => null]);
sign_in($mgr);
call('POST', '/trips', ['trip_number' => 'T-24', 'foreman_id' => (string) $kenth, 'begins_on' => '2026-11-10', 'notes' => '']);
$tid = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'T-24'");
$mk = static fn (string $n, ?string $pickup, string $status = 'booked', array $o = []): int => mk_job($o + ['job_number' => $n, 'pickup_date' => $pickup, 'first_available_date' => $pickup ?? '2026-12-30', 'status' => $status, 'customer_first_name' => 'Ada', 'customer_last_name' => 'Smith', 'foreman_id' => $kenth]);
$entry = static function (string $n, string $date, string $activity = 'pickup') use ($tid): void {
    call('POST', "/trips/{$tid}/jobs", ['job_number' => $n, 'job_date' => $date, 'activity' => $activity]);
};
$mk('SP-MAIN', '2026-11-18');                     // a Future Job, with a second pickup on the 25th
$entry('SP-MAIN', '2026-11-25');
$mk('SP-PAST', '2026-11-10');                      // first pickup already done, second one is ahead
$entry('SP-PAST', '2026-11-27');
$mk('SP-DELIVERY', '2026-11-19');                  // a delivery entry is not a pickup
$entry('SP-DELIVERY', '2026-11-26', 'delivery');
$mk('SP-SAMEDAY', '2026-11-20');                   // an entry on the job's own pickup day adds nothing
$entry('SP-SAMEDAY', '2026-11-20');
$mk('SP-PASTENTRY', '2026-11-19');                 // a pickup entry that is not in the future
$entry('SP-PASTENTRY', '2026-11-12');
$mk('SP-HOLD', '2026-11-19', 'on_hold');
$entry('SP-HOLD', '2026-11-24');
$mk('SP-ROAD', '2026-11-19', 'in_transit');
$entry('SP-ROAD', '2026-11-24');
$mk('SP-DONE', '2026-11-19', 'delivered');
$entry('SP-DONE', '2026-11-24');
$mk('SP-JOE', '2026-11-19', 'booked', ['foreman_id' => $joe]);
$entry('SP-JOE', '2026-11-23');
Database::execute('UPDATE jobs SET foreman_id = ? WHERE job_number = ?', [$joe, 'SP-JOE']); // adding an entry gives the job the trip's foreman; this job is Joe's
// the same job and day twice (as a legacy double entry) still shows once
$mk('SP-TWICE', '2026-11-19');
$entry('SP-TWICE', '2026-11-28');
$rowId = (int) Database::fetchValue("SELECT id FROM trip_jobs WHERE job_number = 'SP-TWICE' AND job_date = '2026-11-28'");
Database::execute('INSERT INTO trip_jobs (trip_id, job_id, sort_order, job_number, customer_name, job_date, activity) SELECT trip_id, job_id, sort_order + 1, job_number, customer_name, job_date, activity FROM trip_jobs WHERE id = ?', [$rowId]);
// a deleted trip's entries are not shown
call('POST', '/trips', ['trip_number' => 'T-24-GONE', 'foreman_id' => (string) $kenth, 'begins_on' => '2026-11-10', 'notes' => '']);
$gone = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'T-24-GONE'");
$mk('SP-GONE', '2026-11-19');
call('POST', "/trips/{$gone}/jobs", ['job_number' => 'SP-GONE', 'job_date' => '2026-11-29', 'activity' => 'pickup']);
Database::execute('UPDATE trips SET deleted_at = ? WHERE id = ?', ['2026-11-16 00:00:00', $gone]);

$html = call('GET', '/jobs/calendar')->body();
$pt = plain($html);
$onDay = static function (string $html, int $day): string {
    return preg_match('~<span class="jt-cal-num">' . $day . '</span>(.*?)(?=<div class="jt-cal-day|$)~s', $html, $m) ? $m[1] : '';
};
$d25 = $onDay($html, 25);
check(str_contains($d25, '>SP-MAIN</a>') && str_contains($d25, '2nd pickup') && str_contains($d25, 'jt-cal-job-second'), 'SP-MAIN appears on the 25th, marked "2nd pickup"');
check(str_contains($onDay($html, 18), '>SP-MAIN</a>') && !str_contains($onDay($html, 18), '2nd pickup'), '...and still on its own pickup day, the 18th, as a normal pickup');
check(str_contains($onDay($html, 27), '>SP-PAST</a>') && str_contains($onDay($html, 27), '2nd pickup'), 'a job whose first pickup is done but has a pickup ahead shows on that day as a second pickup');
check(!str_contains($html, '>SP-PAST</a>') || substr_count($html, '>SP-PAST</a>') === 1, 'it is not shown a second time for its past first pickup');
check(!str_contains($html, '>SP-DELIVERY</a>') || !str_contains($onDay($html, 26), 'SP-DELIVERY'), 'a delivery entry is not a second pickup');
check(substr_count($html, '>SP-SAMEDAY</a>') === 1, 'an entry on the job\'s own pickup day does not add a second card');
check(!str_contains($html, 'SP-PASTENTRY</a>') || !str_contains($onDay($html, 12), 'SP-PASTENTRY'), 'a pickup entry before today is not shown');
foreach (['SP-HOLD', 'SP-ROAD', 'SP-DONE'] as $n) {
    check(!str_contains($onDay($html, 24), $n), "{$n} (on hold / in transit / delivered) has no second pickup on the calendar");
}
check(substr_count($onDay($html, 28), '>SP-TWICE</a>') === 1, 'two entries for the same job on the same day show once');
check(!str_contains($html, '>SP-GONE</a>') || !str_contains($onDay($html, 29), 'SP-GONE'), 'an entry on a deleted trip is not shown');
check(str_contains($pt, 'second pickups') && preg_match('~Future Jobs \(Pick-up\): (\d+)\s*\+ (\d+) second pickups~', $pt, $m) === 1 && (int) $m[2] === 4, 'the title says how many second pickups there are (+ 4: the 23rd, 25th, 27th, 28th)');
$mainCount = (int) ($m[1] ?? -1);
check($mainCount === (int) preg_replace('~.*of (\d+) jobs.*~s', '$1', plain(call('GET', '/jobs/future', [], ['per_page' => '100'])->body())), 'the main count still equals the Future Jobs list (second pickups are counted apart)');
$k = call('GET', '/jobs/calendar', [], ['foreman' => (string) $kenth])->body();
check(!str_contains($onDay($k, 23), 'SP-JOE') && str_contains($onDay($k, 25), 'SP-MAIN'), 'the foreman filter applies to second pickups (Joe\'s job is not in Kenth\'s view)');
$j = call('GET', '/jobs/calendar', [], ['foreman' => (string) $joe])->body();
check(str_contains($onDay($j, 23), 'SP-JOE') && str_contains($onDay($j, 23), '2nd pickup'), 'Joe\'s view has his second pickup');
check(preg_match('~href="/jobs/\d+">SP-MAIN</a>\s*<span class="jt-cal-2nd" title="[^"]*trip T-24~', $html) === 1, 'the badge names the trip (T-24) and links to the job');
check(substr_count($onDay($html, 25), 'jt-cal-ord">1<') === 1, 'the second pickup is numbered like any other pickup of that day');
