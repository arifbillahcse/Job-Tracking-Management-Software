<?php

declare(strict_types=1);

/**
 * Build a throw-away SQLite database holding many jobs, for tests/perf.sh.
 *   DB_DRIVER=sqlite DB_DATABASE=/path/perf.sqlite php tests/support/perf_seed.php 20000
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Database\Migrator;
use App\Database\Seeder;
use App\Helpers\PasswordPolicy;

App::boot();
(new Migrator())->migrate();
(new Seeder())->run();

$count = max(1, (int) ($argv[1] ?? 20000));
$roleId = (int) Database::fetchValue("SELECT id FROM roles WHERE name = 'Admin'");
Database::execute(
    'INSERT INTO users (name, username, password_hash, role_id, is_active, must_change_password, created_at, updated_at) VALUES (?, ?, ?, ?, 1, 0, ?, ?)',
    ['Perf Admin', 'perf', PasswordPolicy::hash('Perf test passphrase 1'), $roleId, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]
);
$userId = (int) Database::fetchValue("SELECT id FROM users WHERE username = 'perf'");

mt_srand(42);
$first = ['Ada', 'Ben', 'Chloe', 'Dmitri', 'Elena', 'Farid', 'Grace', 'Hiro', 'Ines', 'Jack', 'Kofi', 'Lena'];
$last = ['Smith', 'Jones', 'Taylor', 'Brown', 'Wilson', 'Evans', 'Khan', 'Garcia', 'Kim', 'Novak', 'Okafor', 'Rossi'];
$cities = ['Austin, TX', 'Denver, CO', 'Boston, MA', 'Seattle, WA', 'Miami, FL', 'Chicago, IL', 'Phoenix, AZ', 'Atlanta, GA'];
$statuses = ['booked', 'booked', 'booked', 'booked', 'in_transit', 'delivered', 'delivered', 'on_hold', 'cancelled'];
$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$now = gmdate('Y-m-d H:i:s');

Database::transaction(static function () use ($count, $first, $last, $cities, $statuses, $today, $now, $userId): void {
    $job = Database::connect()->prepare(
        'INSERT INTO jobs (job_number, customer_first_name, customer_last_name, pickup_date, first_available_date, from_location, to_location, cf, balance, job_location, status,
            carrier_name, driver_name, driver_phone, storage_fee, notes, created_by, updated_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    for ($i = 1; $i <= $count; $i++) {
        $status = $statuses[mt_rand(0, count($statuses) - 1)];
        $available = $today->modify((mt_rand(-20, 140)) . ' days')->format('Y-m-d');
        $moving = in_array($status, ['in_transit', 'delivered'], true);
        $job->execute([
            sprintf('PF-%06d', $i), $first[mt_rand(0, 11)], $last[mt_rand(0, 11)] . ($i % 97 === 0 ? ' Ünïcode' : ''),
            $today->modify('-' . mt_rand(0, 30) . ' days')->format('Y-m-d'), $available,
            $cities[mt_rand(0, 7)], $cities[mt_rand(0, 7)], mt_rand(200, 2500), mt_rand(0, 900000) / 100, 'Warehouse ' . mt_rand(1, 9), $status,
            $moving ? 'Blue Ridge Haulers' : null, $moving ? 'Marcus Reed' : null, $moving ? '(555) 010-' . mt_rand(1000, 9999) : null,
            mt_rand(0, 5000) / 100, $i % 5 === 0 ? 'Call before arrival. Second floor, no elevator.' : null, $userId, $userId, $now, $now,
        ]);
    }
});
// Foremen and trips: about one trip per ten jobs, one to six job rows each, over 25 foremen.
Database::transaction(static function () use ($count, $first, $last, $cities, $today, $now, $userId): void {
    $foremanIds = [];
    for ($f = 1; $f <= 25; $f++) {
        Database::execute('INSERT INTO foremen (name, phone, active, created_at, updated_at) VALUES (?, ?, 1, ?, ?)', [$first[$f % 12] . ' ' . $last[$f % 12] . " {$f}", null, $now, $now]);
        $foremanIds[] = (int) Database::fetchValue('SELECT MAX(id) FROM foremen');
    }
    $trip = Database::connect()->prepare('INSERT INTO trips (trip_number, foreman_id, begins_on, ends_on, collected_by, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $row = Database::connect()->prepare('INSERT INTO trip_jobs (trip_id, job_id, sort_order, job_number, customer_name, job_date, from_location, bh, diesel, hotels, helper, misc, commission, extra, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $trips = max(1, intdiv($count, 10));
    for ($t = 1; $t <= $trips; $t++) {
        $begin = $today->modify('-' . mt_rand(0, 400) . ' days');
        $trip->execute([sprintf('PT-%06d', $t), $foremanIds[mt_rand(0, 24)], $begin->format('Y-m-d'), $begin->modify('+' . mt_rand(1, 6) . ' days')->format('Y-m-d'), 'BH Office', $userId, $userId, $now, $now]);
        $tripId = (int) Database::fetchValue('SELECT MAX(id) FROM trips');
        for ($r = 0, $n = mt_rand(1, 6); $r < $n; $r++) {
            $jobId = mt_rand(1, $count);
            Database::execute('UPDATE jobs SET trip_id = ?, foreman_id = ? WHERE id = ?', [$tripId, $foremanIds[(int) (($t - 1) % 25)] ?? $foremanIds[0], $jobId]);
            $row->execute([$tripId, $jobId, $r, sprintf('PF-%06d', $jobId), $first[mt_rand(0, 11)] . ' ' . $last[mt_rand(0, 11)], $begin->format('Y-m-d'), $cities[mt_rand(0, 7)], 'BH',
                mt_rand(0, 40000) / 100, mt_rand(0, 20000) / 100, mt_rand(0, 15000) / 100, mt_rand(0, 5000) / 100, mt_rand(0, 60000) / 100, null, $r === 0 ? 'ok' : null]);
        }
    }
});
echo "Inserted {$count} jobs. Total: " . Database::fetchValue('SELECT COUNT(*) FROM jobs') . "\n";

// Do the section and list queries use an index? (SQLite's planner is a stand-in for MySQL's; the index definitions are the same.)
$plans = [
    'Ready section' => ["SELECT id FROM jobs WHERE deleted_at IS NULL AND status = 'booked' AND first_available_date <= ? ORDER BY first_available_date, id DESC LIMIT 25", [$today->modify('+30 days')->format('Y-m-d')]],
    'In Transit section' => ["SELECT id FROM jobs WHERE deleted_at IS NULL AND status = 'in_transit' ORDER BY id DESC LIMIT 25", []],
    'Job number lookup' => ["SELECT id FROM jobs WHERE job_number = ?", ['PF-000123']],
    'Customer sort' => ['SELECT id FROM jobs WHERE deleted_at IS NULL ORDER BY customer_last_name, customer_first_name, id DESC LIMIT 25', []],
];
$plans['Trip list'] = ['SELECT t.id FROM trips t WHERE t.deleted_at IS NULL ORDER BY t.begins_on DESC, t.id DESC LIMIT 25', []];
$plans['Trip rows of one trip'] = ['SELECT id FROM trip_jobs WHERE trip_id = ? ORDER BY sort_order', [123]];
foreach ($plans as $label => [$sql, $params]) {
    $rows = Database::fetchAll('EXPLAIN QUERY PLAN ' . $sql, $params);
    $detail = implode(' | ', array_column($rows, 'detail'));
    echo sprintf("PLAN %-20s %s\n", $label, $detail);
}
