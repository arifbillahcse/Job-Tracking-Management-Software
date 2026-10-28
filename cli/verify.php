<?php

declare(strict_types=1);

/**
 * Read-only check of a deployment: runs every job, trip and foreman query against the real database, then reports the
 * settings and data that decide what people see. Paste the output when something looks wrong.
 *   php cli/verify.php
 * It never changes anything. Exit code 1 when something is broken (a warning is only a hint).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Services\DeployCheck;

App::boot();

$fail = 0;
$warn = 0;
foreach (DeployCheck::run() as $row) {
    $mark = ['ok' => '[ ok ]', 'info' => '[info]', 'warn' => '[warn]', 'fail' => '[FAIL]'][$row['level']];
    echo $mark . ' ' . $row['label'] . ($row['detail'] !== '' ? '  (' . $row['detail'] . ')' : '') . PHP_EOL;
    $fail += $row['level'] === 'fail' ? 1 : 0;
    $warn += $row['level'] === 'warn' ? 1 : 0;
}
echo PHP_EOL . ($fail === 0 ? 'Every query works' : 'Something is broken') . ": {$fail} failure(s), {$warn} warning(s).\n";
exit($fail === 0 ? 0 : 1);
