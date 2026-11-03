<?php

declare(strict_types=1);

/**
 * Client requirements v1.5: the required end-to-end scenario (job 346328 on three days of one trip),
 * sheet fields, one master job, same-day refusal and the flexible switch. Included by tests/run.php.
 */

use App\Core\Clock;
use App\Core\Database;
use App\Repositories\ForemanRepository;
use App\Repositories\JobRepository;

section('Required scenario: job 346328, one master job, daily entries on one trip');
fresh_session();
Clock::fake('2026-10-31');
$mgr = make_user('Manager', 'p15-mgr@example.com');
$joe = (new ForemanRepository())->create(['name' => 'Joe', 'phone' => null]);
sign_in($mgr);
// 1. trip without any job
call('POST', '/trips', ['trip_number' => 'P15-T', 'foreman_id' => (string) $joe, 'begins_on' => '2026-10-27', 'ends_on' => '2026-10-31', 'notes' => '']);
$tid = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'P15-T'");
check($tid > 0 && (int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ?', [$tid]) === 0, '1. trip created with no job');
// 2. job once, on that trip
$r = call('POST', '/jobs', job_input(['job_number' => '346328', 'trip_id' => (string) $tid, 'customer_first_name' => 'Dana', 'customer_last_name' => 'Reed']));
$jid = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = '346328'");
$job = (new JobRepository())->find($jid);
check($r->status() === 302 && (int) $job['trip_id'] === $tid && (int) $job['foreman_id'] === $joe, '2. job 346328 created once, on the trip with its foreman');
// 3. Oct 28 and Oct 29 entries with different expenses
call('POST', "/trips/{$tid}/jobs", ['job_number' => '346328', 'job_date' => '2026-10-28', 'activity' => 'pickup', 'diesel' => '100.00', 'hotels' => '50.00']);
call('POST', "/trips/{$tid}/jobs", ['job_number' => '346328', 'job_date' => '2026-10-29', 'activity' => 'other', 'diesel' => '70.00', 'helper' => '30.00', 'bh' => '10.00']);
$rows = Database::fetchAll('SELECT job_date, diesel, hotels, helper, bh FROM trip_jobs WHERE trip_id = ? AND job_id = ? ORDER BY job_date', [$tid, $jid]);
check(count($rows) === 2 && $rows[0]['job_date'] === '2026-10-28' && (float) $rows[0]['diesel'] === 100.0 && $rows[1]['job_date'] === '2026-10-29' && (float) $rows[1]['diesel'] === 70.0, '3. two separate entries, each with its own expenses (the empty placeholder was filled, not duplicated)');
// 4. separate rows in Trip Details
$page = plain(call('GET', "/trips/{$tid}")->body());
check(substr_count($page, '346328') >= 2 && str_contains($page, '2026-10-28') && str_contains($page, '2026-10-29') || (str_contains($page, 'Oct 28') && str_contains($page, 'Oct 29')), '4. Trip Details shows both days as separate rows');
// 5. total includes both days
$total = (float) Database::fetchValue('SELECT COALESCE(SUM(COALESCE(diesel,0)+COALESCE(hotels,0)+COALESCE(helper,0)+COALESCE(misc,0)+COALESCE(commission,0)+COALESCE(extra,0)),0) FROM trip_jobs WHERE trip_id = ?', [$tid]);
check($total === 250.0 && str_contains($page, '$250.00'), '5. the trip total ($250.00 = 100+50 on Oct 28, 70+30 on Oct 29; BH is not an expense column) includes both days');
// same job + same date refused
call('POST', "/trips/{$tid}/jobs", ['job_number' => '346328', 'job_date' => '2026-10-29', 'diesel' => '1']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ? AND job_id = ?', [$tid, $jid]) === 2, 'the same job on the same date is refused');
// a third day is fine
call('POST', "/trips/{$tid}/jobs", ['job_number' => '346328', 'job_date' => '2026-10-30', 'activity' => 'delivery', 'diesel' => '5']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM trip_jobs WHERE trip_id = ? AND job_id = ?', [$tid, $jid]) === 3, 'Oct 30 is a third entry');
// 6. main Jobs duplicate rejected
$before = (int) Database::fetchValue('SELECT COUNT(*) FROM jobs');
call('POST', '/jobs', job_input(['job_number' => '346328', 'customer_first_name' => 'Other']));
check((int) Database::fetchValue('SELECT COUNT(*) FROM jobs') === $before && (int) Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number = '346328'") === 1, '6. creating 346328 again in Jobs is rejected');
// 7. Jobs list shows it once
$list = plain(call('GET', '/jobs', [], ['q' => '346328'])->body());
check(substr_count($list, '346328') >= 1, '7. Jobs list finds the job');
check((int) Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number = '346328' AND deleted_at IS NULL") === 1, '...and there is one master job');

section('Closing sheet (Forman_Closing.xlsx): broker, foreman pay, sheet formulas, closing totals');
$peg = mk_job(['job_number' => 'A1677613', 'customer_first_name' => 'Peggy', 'customer_last_name' => 'Brown']);
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'A1677613', 'job_date' => '2026-10-02', 'activity' => 'pickup', 'broker' => 'Top Nation',
    'grand' => '4016.50', 'job_beg' => '2628.75', 'depo' => '1526', 'bh' => '2590.12', 'foreman_pay' => '2151.50', 'diesel' => '71.09', 'hotels' => '119', 'helper' => '200', 'misc' => '10']);
$pr = Database::fetchAll('SELECT * FROM trip_jobs WHERE job_id = ?', [$peg])[0];
check($pr['broker'] === 'Top Nation' && (float) $pr['foreman_pay'] === 2151.5, 'Broker and the amount paid to the foreman are saved on the entry');
check((float) $pr['addio'] === 1387.75 && (float) $pr['total_to_bh'] === 2490.5, 'Additional = Grand - Job Begins (1387.75) and Total to BH = Job Begins - Deposit + Additional (2490.50), as the sheet row for this job shows');
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'A1677613', 'job_date' => '2026-10-03', 'grand' => '100', 'job_beg' => '60', 'addio' => '5', 'total_to_bh' => '9']);
$typed = Database::fetchAll("SELECT addio, total_to_bh FROM trip_jobs WHERE job_id = ? AND job_date = '2026-10-03'", [$peg])[0];
check((float) $typed['addio'] === 5.0 && (float) $typed['total_to_bh'] === 9.0, 'a figure typed by the user is never overwritten by the formula');
$pg = plain(call('GET', "/trips/{$tid}")->body());
check(str_contains($pg, 'Broker') && str_contains($pg, 'Top Nation') && str_contains($pg, 'Foreman') && str_contains($pg, 'All expenses as on the closing sheet (Diesel + Hotels + Helpers + MISC): $') && str_contains($pg, 'Paid to foreman'), 'the trip page shows Broker, the Foreman amount and the closing-sheet totals');
check(preg_match('~Helpers \+ MISC\): \$([\d,.]+)~', $pg, $mm) === 1 && $mm[1] === '655.09', 'sheet "ALL EXP" = Diesel+Hotels+Helpers+MISC over every entry (255 + 400.09 = 655.09)');

section('Foreman column in the trip form is a number');
$ef = call('GET', "/trips/{$tid}/edit")->body();
check(str_contains($ef, 'name="rows[0][foreman_pay]"') && str_contains($ef, 'type="number"') && !str_contains($ef, 'name="rows[0][foreman_id]"'), 'each row has a Foreman number box, not a name drop-down');
$trow = Database::fetchAll('SELECT id, job_number, job_date, foreman_id FROM trip_jobs WHERE trip_id = ? AND job_id = ? ORDER BY id', [$tid, $jid])[0];
$post = ['trip_number' => 'P15-T', 'foreman_id' => (string) $joe, 'begins_on' => '2026-10-27', 'loaded_at' => (string) Database::fetchValue('SELECT updated_at FROM trips WHERE id = ?', [$tid]), 'rows' => []];
foreach (Database::fetchAll('SELECT * FROM trip_jobs WHERE trip_id = ? ORDER BY sort_order, id', [$tid]) as $i => $r) {
    $post['rows'][$i] = ['id' => (string) $r['id'], 'job_number' => $r['job_number'], 'job_date' => (string) $r['job_date'], 'activity' => (string) $r['activity'], 'foreman_pay' => (int) $r['id'] === (int) $trow['id'] ? '1234.50' : (string) $r['foreman_pay'],
        'diesel' => (string) $r['diesel'], 'hotels' => (string) $r['hotels'], 'helper' => (string) $r['helper']];
}
call('POST', "/trips/{$tid}", $post);
$after = Database::fetchAll('SELECT foreman_pay, foreman_id FROM trip_jobs WHERE trip_id = ? AND job_id = ? AND job_date = ?', [$tid, $jid, $trow['job_date']])[0] ?? ['foreman_pay' => null, 'foreman_id' => null];
check((float) $after['foreman_pay'] === 1234.5 && (int) $after['foreman_id'] === (int) $trow['foreman_id'], 'a number typed in the Foreman box is saved, and the entry keeps its foreman');
