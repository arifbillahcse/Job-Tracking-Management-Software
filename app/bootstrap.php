<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Core/Autoloader.php';

App\Core\Autoloader::register(['App\\' => BASE_PATH . '/app/']);

// Composer is optional; it is only needed once a library such as OpenSpout is added (Phase 5).
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}

require BASE_PATH . '/app/Helpers/functions.php';
