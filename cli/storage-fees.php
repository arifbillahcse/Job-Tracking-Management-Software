<?php

declare(strict_types=1);

/**
 * Re-assess the automatic storage fee of every open job (CF x rate once a job has waited more than the configured days).
 *   php cli/storage-fees.php
 * The application does this by itself once a day on the first page view; scheduling this at night (deploy/cron.example)
 * means the figures are already right when the first person opens the site.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Services\StorageFeeService;

App::boot();

$changed = StorageFeeService::refresh();
printf("Storage fees re-assessed: rate %s, after %d days, counted from the %s. %d job(s) changed.\n",
    StorageFeeService::rate(), StorageFeeService::days(), StorageFeeService::BASIS[StorageFeeService::basis()], $changed);
