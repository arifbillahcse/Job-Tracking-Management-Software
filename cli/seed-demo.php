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

App::boot();

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    }
}
$count = max(1, min(500, (int) ($options['count'] ?? 60)));

$jobs = new JobRepository();
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
        'booked' => mt_rand(0, 100) < 12 ? mt_rand(-9, -1) : (mt_rand(0, 100) < 45 ? mt_rand(0, 30) : mt_rand(31, 150)),
        'in_transit' => mt_rand(-20, 5),
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
        'storage_fee' => $status === 'booked' && $available > 30 && mt_rand(0, 100) < 40 ? number_format(mt_rand(40, 450), 2, '.', '') : '0.00',
        'notes' => $pick($notes),
    ];

    if ($status === 'in_transit' || $status === 'delivered') {
        [$carrier, $driver, $phone] = $pick($carriers);
        $row += ['carrier_name' => $carrier, 'driver_name' => $driver, 'driver_phone' => $phone];
        $row['in_transit_at'] = $day($available - mt_rand(1, 6)) . ' 09:00:00';
        if ($status === 'delivered') {
            $row['delivered_at'] = $day($available) . ' 15:30:00';
        }
    }

    if ($jobs->jobNumberExists($row['job_number'])) {
        $skipped++;
        continue;
    }

    $jobs->create($row, $userId);
    $created++;
    $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
}

echo "Created {$created} demo job(s)" . ($skipped > 0 ? ", skipped {$skipped} existing" : '') . ".\n";
ksort($byStatus);
foreach ($byStatus as $status => $n) {
    echo '  ' . str_pad($status, 12) . $n . PHP_EOL;
}
