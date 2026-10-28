<?php

declare(strict_types=1);

/**
 * Insert realistic demo jobs for demonstrations and testing.
 *
 *   php cli/seed-demo.php [--count=60] [--force]
 *
 * Dates are relative to today so the demo always spans every section: some jobs available within
 * 30 days, some further out, some overdue, plus in-transit, delivered, on-hold and cancelled jobs.
 * Refuses to run when jobs already exist unless --force is given (job numbers that exist are skipped).
 * The data is deterministic (fixed random seed), so every run produces the same jobs.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Repositories\JobRepository;
use App\Repositories\StatusHistoryRepository;

App::boot();

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    }
}
$count = max(1, min(500, (int) ($options['count'] ?? 60)));

$jobs = new JobRepository();
$history = new StatusHistoryRepository();
if ($jobs->count() > 0 && !isset($options['force'])) {
    fwrite(STDERR, "Jobs already exist. Re-run with --force to add demo jobs anyway.\n");
    exit(1);
}

$admin = Database::fetchOne("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Admin' ORDER BY u.id LIMIT 1");
$userId = $admin === null ? null : (int) $admin['id'];

$first = ['Olivia', 'Liam', 'Emma', 'Noah', 'Ava', 'Ethan', 'Sophia', 'Mason', 'Isabella', 'Lucas', 'Mia', 'Henry', 'Amelia', 'Jack', 'Harper', 'Daniel', 'Evelyn', 'Owen', 'Abigail', 'Samuel'];
$last = ['Johnson', 'Williams', 'Brown', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez', 'Thompson'];
$cities = ['Austin, TX', 'Denver, CO', 'Seattle, WA', 'Chicago, IL', 'Atlanta, GA', 'Boston, MA', 'Phoenix, AZ', 'Portland, OR', 'Miami, FL', 'Nashville, TN', 'Dallas, TX', 'San Diego, CA', 'Columbus, OH', 'Charlotte, NC', 'Minneapolis, MN'];
$sites = ['Main warehouse', 'North depot', 'South depot', 'Storage unit A', 'Storage unit B', 'Cross-dock'];
$carriers = [['Blue Ridge Haulers', 'Marcus Reed', '(555) 010-2231'], ['Summit Freight', 'Priya Nair', '(555) 010-4417'], ['Coastline Movers', 'Tom Alvarez', '(555) 010-7788'], ['Prairie Express', 'Jenna Cole', '(555) 010-3320']];
$notes = [null, null, null, 'Customer prefers morning delivery.', 'Fragile items - handle with care.', 'Call customer 30 minutes before arrival.', 'Elevator reservation required at destination.'];

mt_srand(20261023);
$pick = static fn (array $list): mixed => $list[mt_rand(0, count($list) - 1)];
$day = static fn (int $offset): string => gmdate('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));

$created = 0;
$skipped = 0;
$byStatus = [];

for ($i = 1; $i <= $count; $i++) {
    $roll = mt_rand(1, 100);
    $status = match (true) {
        $roll <= 58 => 'booked',
        $roll <= 72 => 'in_transit',
        $roll <= 88 => 'delivered',
        $roll <= 94 => 'on_hold',
        default => 'cancelled',
    };

    $available = match ($status) {
        // a few jobs have waited more than 30 days, so the automatic storage fee is visible in the demo
        'booked' => ($roll2 = mt_rand(0, 100)) < 8 ? mt_rand(-80, -32) : ($roll2 < 20 ? mt_rand(-9, -1) : (mt_rand(0, 100) < 45 ? mt_rand(0, 30) : mt_rand(31, 150))),
        'in_transit' => mt_rand(0, 100) < 25 ? mt_rand(-60, -35) : mt_rand(-20, 5),
        'delivered' => mt_rand(-75, -8),
        default => mt_rand(-5, 60),
    };
    $pickup = mt_rand(0, 100) < 90 ? $available - mt_rand(3, 28) : null;

    $from = $pick($cities);
    do {
        $to = $pick($cities);
    } while ($to === $from);

    $row = [
        'job_number' => 'JT-' . (1000 + $i),
        'customer_first_name' => $pick($first),
        'customer_last_name' => $pick($last),
        'pickup_date' => $pickup === null ? null : $day($pickup),
        'first_available_date' => $day($available),
        'from_location' => $from,
        'to_location' => $to,
        'job_location' => mt_rand(0, 100) < 70 ? $pick($sites) : null,
        'cf' => number_format(mt_rand(250, 2400) + mt_rand(0, 99) / 100, 2, '.', ''),
        'balance' => $status === 'delivered' || $status === 'cancelled' ? '0.00' : number_format(mt_rand(150, 6500) + mt_rand(0, 99) / 100, 2, '.', ''),
        'status' => $status,
        'storage_fee' => '0.00', // calculated below by StorageFeeService (CF x rate once a job has waited more than 30 days)
        'customer_phone' => mt_rand(0, 100) < 85 ? sprintf('(555) %03d-%04d', mt_rand(100, 999), mt_rand(0, 9999)) : null,
        'notes' => $pick($notes),
    ];

    if ($status === 'in_transit' || $status === 'delivered') {
        [$carrier, $driver, $phone] = $pick($carriers);
        $row += ['carrier_name' => $carrier, 'driver_name' => $driver, 'driver_phone' => $phone];
        $loadedOffset = $available - mt_rand(1, 6);
        $row['in_transit_at'] = $day($loadedOffset) . ' 09:00:00';
        $row['loaded_date'] = $day($loadedOffset);
        if ($status === 'delivered') {
            $row['delivered_at'] = $day($available) . ' 15:30:00';
            $row['delivery_date'] = $day($available);
        }
    }

    if ($jobs->jobNumberExists($row['job_number'])) {
        $skipped++;
        continue;
    }

    $id = $jobs->create($row, $userId);

    // Status history that matches each job's state, so timelines and the dashboard activity feed look real.
    $carrierDetails = isset($row['carrier_name']) ? ['carrier_name' => $row['carrier_name'], 'driver_name' => $row['driver_name'], 'driver_phone' => $row['driver_phone']] : null;
    switch ($status) {
        case 'in_transit':
        case 'delivered':
            $history->insert($id, 'booked', 'in_transit', null, ['loaded_date' => $row['loaded_date']], $userId, $row['in_transit_at']);
            if ($status === 'delivered') {
                $history->insert($id, 'in_transit', 'delivered', null, ['delivery_date' => $row['delivery_date']], $userId, $row['delivered_at']);
            }
            break;
        case 'on_hold':
            $history->insert($id, 'booked', 'on_hold', $pick(['Customer asked to wait.', 'Awaiting payment.', 'Destination access not ready.']), null, $userId, $day($available - 2) . ' 10:00:00');
            break;
        case 'cancelled':
            $history->insert($id, 'booked', 'cancelled', $pick(['Customer cancelled the move.', 'Duplicate booking.', 'Quote not accepted.']), null, $userId, $day($available - 3) . ' 11:15:00');
            break;
    }

    $created++;
    $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
}

\App\Services\StorageFeeService::refresh(); // fees follow the rules in Settings

// Two foremen and three trips over the demo jobs, so the Trips pages are not empty (skipped when trips already exist).
$tripsCreated = 0;
if ((int) \App\Core\Database::fetchValue('SELECT COUNT(*) FROM trips') === 0) {
    $foremen = new \App\Repositories\ForemanRepository();
    $trips = new \App\Repositories\TripRepository();
    $ids = [$foremen->create(['name' => 'Joe Demo', 'phone' => '(555) 010-2231']), $foremen->create(['name' => 'Sam Demo', 'phone' => null])];
    $demoJobs = \App\Core\Database::fetchAll('SELECT id, job_number, customer_first_name, customer_last_name, from_location, pickup_date FROM jobs WHERE deleted_at IS NULL ORDER BY id LIMIT 6');
    foreach ([[0, 'DEMO-TRIP-1', 18, [100.10, 80.0, 40.0, 10.0, 120.0, null]], [1, 'DEMO-TRIP-2', 9, [75.5, null, 30.0, null, 90.0, 12.25]], [0, 'DEMO-TRIP-3', 2, [60.0, 55.0, null, 5.0, 70.0, null]]] as $n => [$who, $number, $daysAgo, $money]) {
        $rows = [];
        foreach (array_slice($demoJobs, $n * 2, 2) as $i => $job) {
            $rows[] = ['job_id' => (int) $job['id'], 'job_number' => $job['job_number'], 'customer_name' => $job['customer_first_name'] . ' ' . $job['customer_last_name'],
                'job_date' => $job['pickup_date'], 'from_location' => $job['from_location'], 'bh' => 'BH',
                'diesel' => $i === 0 ? $money[0] : null, 'hotels' => $i === 0 ? $money[1] : null, 'helper' => $money[2], 'misc' => $money[3], 'commission' => $i === 0 ? $money[4] : null, 'extra' => $money[5], 'remark' => $i === 0 ? 'Demo trip' : null];
        }
        if ($rows === []) {
            continue;
        }
        $begin = gmdate('Y-m-d', strtotime("-{$daysAgo} days"));
        $newTrip = $trips->create(['trip_number' => $number, 'foreman_id' => $ids[$who], 'begins_on' => $begin, 'ends_on' => gmdate('Y-m-d', strtotime($begin . ' +3 days')), 'collected_by' => 'BH Office', 'notes' => null], $rows, $userId);
        // The jobs on the trip are assigned to it and to its foreman.
        \App\Core\Database::execute('UPDATE jobs SET trip_id = ?, foreman_id = ? WHERE id IN (' . implode(',', array_map('intval', array_column($rows, 'job_id'))) . ')', [$newTrip, $ids[$who]]);
        $tripsCreated++;
    }
    echo "Created 2 demo foremen and {$tripsCreated} demo trip(s).\n";
}

echo "Created {$created} demo job(s)" . ($skipped > 0 ? ", skipped {$skipped} existing" : '') . ".\n";
ksort($byStatus);
foreach ($byStatus as $status => $n) {
    echo '  ' . str_pad($status, 12) . $n . PHP_EOL;
}
