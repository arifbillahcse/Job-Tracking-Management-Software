<?php

declare(strict_types=1);

/**
 * Download Bootstrap 5.3.3 into public/assets/vendor so the app serves it locally
 * (required in production: the CSP only allows the CDN when APP_ENV is not "production").
 *
 *   php cli/fetch-assets.php
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$base = dirname(__DIR__) . '/public/assets/vendor/bootstrap';
$files = [
    'css/bootstrap.min.css' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    'js/bootstrap.bundle.min.js' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
];

$context = stream_context_create(['http' => ['timeout' => 30], 'ssl' => ['verify_peer' => true]]);

foreach ($files as $relative => $source) {
    $target = $base . '/' . $relative;
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) {
        fwrite(STDERR, "Cannot create " . dirname($target) . PHP_EOL);
        exit(1);
    }

    $data = @file_get_contents($source, false, $context);
    if ($data === false || strlen($data) < 10000) {
        fwrite(STDERR, "Download failed: {$source}\n");
        exit(1);
    }

    file_put_contents($target, $data);
    echo "Saved {$relative} (" . number_format(strlen($data)) . " bytes)\n";
}

echo "Done. Bootstrap is now served from public/assets/vendor/bootstrap.\n";
