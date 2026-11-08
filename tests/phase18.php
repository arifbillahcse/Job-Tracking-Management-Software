<?php

declare(strict_types=1);

/**
 * Client request (v1.6.2): Trip Details shows the job's Total to BH by default and totals every money column. Included by tests/run.php.
 */

use App\Core\Clock;
use App\Core\Database;
use App\Repositories\ForemanRepository;

section('Trip Details: Total to BH from the job, and the Totals row');
fresh_db();
fresh_session();
Clock::fake('2026-11-08');
$mgr = make_user('Manager', 'p18-mgr@example.com');
$joe = (new ForemanRepository())->create(['name' => 'Joe', 'phone' => null]);
sign_in($mgr);
call('POST', '/trips', ['trip_number' => 'K1101', 'foreman_id' => (string) $joe, 'begins_on' => '2026-11-01', 'ends_on' => '2026-11-08', 'notes' => '']);
$tid = (int) Database::fetchValue("SELECT id FROM trips WHERE trip_number = 'K1101'");
// job A: Grand 2000 - Deposit 500 = Total to BH 1500; job B: Total to BH 900 (2000 - 1100)
foreach ([['P18-A', '2000', '500'], ['P18-B', '2000', '1100'], ['P18-C', '', '']] as [$n, $g, $d]) {
    call('POST', '/jobs', job_input(['job_number' => $n, 'grand_total' => $g, 'deposit' => $d, 'balance' => '', 'storage_fee_override' => '']));
}
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'P18-A', 'job_date' => '2026-11-02', 'addio' => '300', 'bh' => '1000.00', 'foreman_pay' => '5.30', 'diesel' => '250', 'hotels' => '134.60']);
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'P18-B', 'job_date' => '2026-11-03', 'addio' => '500', 'bh' => '970.15', 'total_to_bh' => '777.00', 'hotels' => '65']);
call('POST', "/trips/{$tid}/jobs", ['job_number' => 'P18-C', 'job_date' => '2026-11-04', 'addio' => '500', 'bh' => 'cash']);
$page = call('GET', "/trips/{$tid}")->body();
$pt = plain($page);
check(preg_match('~P18-A.*?Nov 2, 2026.*?\$300\.00 \$1,500\.00 1000\.00~s', $pt) === 1, 'an entry with no Total to BH of its own shows the job\'s Total to BH ($1,500.00 = 2000 - 500)');
check(preg_match('~P18-B.*?\$500\.00 \$777\.00 970\.15~s', $pt) === 1, 'a Total to BH typed on the entry wins over the job\'s ($777.00, not $900.00)');
check(preg_match('~P18-C.*?Nov 4, 2026.*?\$500\.00 — cash~s', $pt) === 1, 'a job with no Total to BH leaves the entry\'s empty');
check(str_contains($page, 'title="From the job\'s Total to BH"'), 'the defaulted figure says where it came from');
// Totals row: Addio 300+500+500 = 1300; Total to BH 1500+777 = 2277; BH 1000+970.15 (cash ignored) = 1970.15; Foreman 5.30
check(preg_match('~Totals \$1,300\.00 \$2,277\.00 \$1,970\.15 \$5\.30 \$250\.00 \$199\.60~', $pt) === 1, 'the Totals row adds Addio ($1,300.00), Total to BH ($2,277.00), BH ($1,970.15), Foreman ($5.30) and each expense column');
check(str_contains($pt, 'Total expenses (sum of the six columns) $449.60'), 'Total expenses is unchanged: the six expense columns only ($449.60)');
// a user without money access sees none of it
sign_in(make_user('Staff', 'p18-stf@example.com'));
$sp = plain(call('GET', "/trips/{$tid}")->body());
check(!str_contains($sp, '1,500.00') && !str_contains($sp, 'Totals $') && !str_contains($sp, '2,277.00'), 'Staff see no Total to BH, BH or totals');
sign_in($mgr);
$csv = call('GET', '/trips/export')->content();
check(str_contains($csv, '1500.00') && str_contains($csv, '777.00'), 'the trip CSV has the same Total to BH: the job\'s default (1500.00) and the typed one (777.00)');
sign_in($mgr);
$ef = call('GET', "/trips/{$tid}/edit")->body();
check(preg_match('~name="rows\[0\]\[total_to_bh\]" value="1500"~', $ef) === 1 && preg_match('~name="rows\[1\]\[total_to_bh\]" value="777"~', $ef) === 1, 'the Edit Trip form is prefilled with the job\'s Total to BH (1500), and keeps a figure typed on the entry (777)');
check(str_contains(call('GET', "/trips/{$tid}")->body(), 'id="add-total_to_bh"') && str_contains(call('GET', "/trips/{$tid}")->body(), 'placeholder="from the job"'), 'the Add daily entry box says Total to BH comes from the job when left empty');
