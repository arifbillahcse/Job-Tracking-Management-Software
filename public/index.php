<?php

declare(strict_types=1);

// PHP's built-in dev server: let it serve existing static files (css/js/images) itself.
if (PHP_SAPI === 'cli-server') {
    $requested = __DIR__ . rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if (is_file($requested) && basename($requested) !== 'index.php') {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

App\Core\App::run();
