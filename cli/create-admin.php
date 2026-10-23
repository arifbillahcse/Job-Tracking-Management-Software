<?php

declare(strict_types=1);

/**
 * Shortcut for: php cli/create-user.php --role=Admin ...
 *   php cli/create-admin.php --email=you@example.com --name="Your Name" [--password=...] [--force-change]
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$argv[] = '--role=Admin'; // appended last, so it wins over any --role given by mistake

require __DIR__ . '/create-user.php';
