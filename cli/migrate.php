<?php

declare(strict_types=1);

/**
 * Apply pending database migrations.
 *   php cli/migrate.php            run pending migrations
 *   php cli/migrate.php --status   list applied / pending files
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Database\Migrator;

App::boot();

$migrator = new Migrator();

if (in_array('--status', $argv, true)) {
    foreach ($migrator->status() as $row) {
        echo ($row['applied'] ? '[x] ' : '[ ] ') . $row['filename'] . PHP_EOL;
    }
    exit(0);
}

$ran = $migrator->migrate();
echo $ran === [] ? "Nothing to migrate.\n" : "Applied:\n  " . implode("\n  ", $ran) . "\n";
