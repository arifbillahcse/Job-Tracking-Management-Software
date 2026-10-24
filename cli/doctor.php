<?php

declare(strict_types=1);

/**
 * Environment self-check: PHP, extensions, configuration, permissions, database and accounts.
 * Exits 1 when anything fails (warnings do not fail it).
 *   php cli/doctor.php
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Services\EnvironmentCheck;

App::boot();

echo 'Job Tracker ' . app_version() . ' (PHP ' . PHP_VERSION . ")\n\n";
$fail = 0;
$warn = 0;
foreach (EnvironmentCheck::run() as $row) {
    $mark = ['ok' => '[ ok ]', 'warn' => '[warn]', 'fail' => '[FAIL]'][$row['level']];
    echo $mark . ' ' . $row['label'] . ($row['detail'] !== '' ? '  (' . $row['detail'] . ')' : '') . PHP_EOL;
    $fail += $row['level'] === 'fail' ? 1 : 0;
    $warn += $row['level'] === 'warn' ? 1 : 0;
}
echo PHP_EOL . ($fail === 0 ? 'Ready' : 'Not ready') . ": {$fail} failure(s), {$warn} warning(s).\n";
exit($fail === 0 ? 0 : 1);
