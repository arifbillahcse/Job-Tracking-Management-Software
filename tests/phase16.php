<?php

declare(strict_types=1);

/**
 * Client request (v1.6): a Broker on every job: free text, repeatable, shown in the lists, sortable, filterable, exported.
 * Migration 016. Included by tests/run.php (after phase15.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Core\Request;
use App\Database\Migrator;
use App\Repositories\JobRepository;
use App\Services\ExportService;
use App\Services\Import\JobFields;
use App\Services\JobCriteria;

section('Broker: migration 016 on an existing database');
$dir = sys_get_temp_dir() . '/jt-mig16-' . bin2hex(random_bytes(4));
mkdir($dir);
foreach (glob(BASE_PATH . '/database/migrations/0{0[1-9],1[0-5]}_*.sql', GLOB_BRACE) as $f) {
    copy($f, $dir . '/' . basename($f));
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Database::setConnection($pdo);
(new Migrator($dir))->migrate();
$pdo->exec("INSERT INTO jobs (job_number, customer_first_name, customer_last_name, first_available_date, from_location, to_location, status, created_at, updated_at) VALUES ('OLD-1', 'A', 'B', '2026-01-01', 'X', 'Y', 'booked', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
copy(BASE_PATH . '/database/migrations/016_job_broker.sql', $dir . '/016_job_broker.sql');
check((new Migrator($dir))->migrate() === ['016_job_broker.sql'], 'only migration 016 runs on an existing database');
check($pdo->query("SELECT broker FROM jobs WHERE job_number = 'OLD-1'")->fetchColumn() === null, 'existing jobs get an empty Broker; nothing else changes');
check((new Migrator($dir))->migrate() === [], 'running it again does nothing');
$pdo->exec("INSERT INTO jobs (job_number, customer_first_name, customer_last_name, first_available_date, from_location, to_location, status, broker, created_at, updated_at) VALUES ('OLD-2', 'A', 'B', '2026-01-01', 'X', 'Y', 'booked', 'Prime', '2026-01-01 00:00:00', '2026-01-01 00:00:00'), ('OLD-3', 'A', 'B', '2026-01-01', 'X', 'Y', 'booked', 'Prime', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
check((int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE broker = 'Prime'")->fetchColumn() === 2, 'no unique key: the same broker is on two jobs');

Database::setConnection(null);
array_map('unlink', glob($dir . '/*'));
rmdir($dir);

section('Broker: form, details, list, sort, filter, export');
fresh_db();
fresh_session();
Clock::fake('2026-10-31');
$mgr = make_user('Manager', 'p16-mgr@example.com');
sign_in($mgr);
$form = call('GET', '/jobs/create')->body();
check(str_contains($form, 'name="broker"') && str_contains($form, 'list="broker-list"') && str_contains($form, '<datalist id="broker-list">'), 'the New Job form has a Broker box with suggestions');
foreach ([['P16-A', 'Top Nation'], ['P16-B', 'Unted'], ['P16-C', 'Top Nation'], ['P16-D', 'Prime'], ['P16-E', '']] as [$n, $b]) {
    call('POST', '/jobs', job_input(['job_number' => $n, 'broker' => $b]));
}
$brokerOf = static fn (string $n): ?string => ($v = Database::fetchValue('SELECT broker FROM jobs WHERE job_number = ?', [$n])) === false || $v === null ? null : (string) $v;
check($brokerOf('P16-A') === 'Top Nation' && $brokerOf('P16-C') === 'Top Nation' && $brokerOf('P16-B') === 'Unted' && $brokerOf('P16-E') === null, 'a job is saved with its broker; the same broker on two jobs is fine; empty stays empty');
check((int) Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number LIKE 'P16-%'") === 5, 'duplicate brokers never block a job');
$bad = call('POST', '/jobs', job_input(['job_number' => 'P16-LONG', 'broker' => str_repeat('x', 101)]));
check((int) Database::fetchValue("SELECT COUNT(*) FROM jobs WHERE job_number = 'P16-LONG'") === 0, 'a broker over 100 characters is refused');
$jid = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'P16-B'");
check(str_contains(plain(call('GET', "/jobs/{$jid}")->body()), 'Broker Unted'), 'Job details show the broker');
$edit = call('GET', "/jobs/{$jid}/edit")->body();
check(str_contains($edit, 'value="Unted"') && str_contains($edit, '<option value="Top Nation">'), 'Edit shows the broker and offers the brokers already used');
$job = (new JobRepository())->find($jid);
call('POST', "/jobs/{$jid}", job_input(['job_number' => 'P16-B', 'broker' => 'United', 'loaded_at' => (string) $job['updated_at']]));
check($brokerOf('P16-B') === 'United', 'the broker can be changed on edit');
check(str_contains((string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'job_updated' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$jid]), 'United'), '...and the change is audited');

$list = call('GET', '/jobs', [], ['q' => 'P16-'])->body();
check(preg_match('~<th[^>]*>.*?sort=broker~s', $list) === 1 && str_contains(plain($list), 'Broker') && str_contains(plain($list), 'Top Nation'), 'the Jobs list has a Broker column with a sort link');
$order = static function (string $dir): array {
    $html = call('GET', '/jobs', [], ['q' => 'P16-', 'sort' => 'broker', 'dir' => $dir])->body();
    preg_match_all('~P16-[A-E]~', $html, $m);
    return array_values(array_unique($m[0]));
};
$asc = $order('asc');
$desc = $order('desc');
check($asc[0] === 'P16-E' && in_array($asc[1], ['P16-D'], true) && $asc[count($asc) - 1] !== 'P16-E', 'sorted A-Z: no broker first, then Prime, Top Nation (twice), United last: ' . implode(',', $asc));
check($desc[0] === 'P16-B' && $desc[count($desc) - 1] === 'P16-E', 'sorted Z-A is the reverse: ' . implode(',', $desc));
$filtered = call('GET', '/jobs', [], ['q' => 'P16-', 'broker' => 'Top Nation'])->body();
preg_match_all('~P16-[A-E]~', $filtered, $m);
check(array_values(array_unique($m[0])) === ['P16-C', 'P16-A'] || array_values(array_unique($m[0])) === ['P16-A', 'P16-C'], 'the Broker filter shows only that broker\'s jobs (both of them)');
check(str_contains(call('GET', '/jobs')->body(), '<select class="form-select" id="f-broker"'), 'the filter panel has a Broker choice');
check(!str_contains($filtered, 'P16-D') && !str_contains($filtered, 'P16-B'), 'other brokers are not listed');
mk_job(['job_number' => 'P16-RDY', 'broker' => 'Prime', 'pickup_date' => '2026-10-25', 'status' => 'booked']);
mk_job(['job_number' => 'P16-LTR', 'broker' => 'Prime', 'pickup_date' => '2026-08-01', 'first_available_date' => '2026-12-20', 'status' => 'booked']);
mk_job(['job_number' => 'P16-TRN', 'broker' => 'Prime', 'status' => 'in_transit', 'carrier_name' => 'C', 'driver_name' => 'D', 'driver_phone' => '1', 'loaded_date' => '2026-10-20', 'in_transit_at' => '2026-10-20 10:00:00']);
mk_job(['job_number' => 'P16-DLV', 'broker' => 'Prime', 'status' => 'delivered', 'delivery_date' => '2026-10-25', 'delivered_at' => '2026-10-25 10:00:00']);
foreach (['/jobs/ready' => 'P16-RDY', '/jobs/more-than-30' => 'P16-LTR', '/jobs/in-transit' => 'P16-TRN', '/jobs/delivered' => 'P16-DLV'] as $uri => $num) {
    $r = call('GET', $uri);
    check($r->status() === 200 && str_contains($r->body(), $num) && str_contains($r->body(), 'sort=broker'), "{$uri}: the list shows the job with a sortable Broker column");
}
$c = JobCriteria::fromRequest(Request::make('GET', '/', [], ['broker' => 'Top Nation', 'sort' => 'broker']), 'all', true);
$csv = (new ExportService())->csv($c, true)->content();
check(str_contains($csv, 'Broker') && substr_count($csv, 'Top Nation') === 2 && !str_contains($csv, 'Prime'), 'the CSV export has the Broker column and follows the broker filter');
check(isset(JobFields::all()['broker']) && JobFields::label('broker') === 'Broker', 'Import accepts an optional Broker column');
