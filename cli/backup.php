<?php

declare(strict_types=1);

/**
 * Back up the database to storage/backups (mode 0600) and keep the newest N.
 *   php cli/backup.php [--keep=14]
 * Schedule daily from cron (see deploy/cron.example). Copy the files OFF this server too (docs/operations.md).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Services\AuditService;
use App\Services\BackupService;

App::boot();

$keep = 14;
foreach ($argv as $arg) {
    if (preg_match('/^--keep=(\d+)$/', $arg, $m)) {
        $keep = max(1, (int) $m[1]);
    }
}

$service = new BackupService();
try {
    $result = $service->create();
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
$removed = $service->prune($keep);

printf("Backup written: %s (%s KB, %s)\n", $result['path'], number_format($result['bytes'] / 1024, 1), $result['driver']);
echo $removed > 0 ? "Removed {$removed} older backup(s); keeping the newest {$keep}.\n" : "Keeping the newest {$keep}.\n";
AuditService::record('backup_created', null, null, null, ['file' => basename($result['path']), 'bytes' => $result['bytes']]);
