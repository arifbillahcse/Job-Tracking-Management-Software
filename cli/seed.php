<?php

declare(strict_types=1);

/**
 * Seed permissions, default roles and default settings (safe to run repeatedly).
 *   php cli/seed.php
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Database\Seeder;

App::boot();

foreach ((new Seeder())->run() as $what => $count) {
    echo str_pad(str_replace('_', ' ', $what), 22) . $count . PHP_EOL;
}
