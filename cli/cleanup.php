<?php

declare(strict_types=1);

/**
 * Housekeeping. Dry run unless --apply is given.
 *   php cli/cleanup.php                    show what would be removed
 *   php cli/cleanup.php --apply            remove it
 *   php cli/cleanup.php --apply --audit-days=730   also delete audit entries older than 730 days (minimum 365)
 * Schedule the --apply form daily, e.g. from cron.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Services\AuditService;
use App\Services\MaintenanceService;

App::boot();

$apply = in_array('--apply', $argv, true);
$auditDays = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--audit-days=')) {
        $auditDays = (int) substr($arg, 13);
    }
}

try {
    $report = (new MaintenanceService())->run($apply, $auditDays);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
}

echo $apply ? "Cleanup (applied)\n" : "Cleanup (dry run: nothing is deleted without --apply)\n";
$removed = 0;
foreach ($report as $row) {
    printf("  %-58s found %4d  removed %4d\n", $row['label'], $row['found'], $row['removed']);
    $removed += $row['removed'];
}
if ($apply && $removed > 0) {
    AuditService::record('maintenance_cleanup', null, null, null, array_map(static fn (array $r): int => $r['removed'], $report));
}
