<?php

declare(strict_types=1);

/**
 * Review the route table: every route needs exactly one access rule, permission keys must exist, and
 * nothing that changes data may answer GET or be public. Exits 1 on any problem (usable in CI).
 *   php cli/route-audit.php
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Config;
use App\Core\Router;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Services\RouteAudit;

App::boot();

$router = new Router();
$router->middleware(SecurityHeadersMiddleware::class, CsrfMiddleware::class);
(require BASE_PATH . '/app/Config/routes.php')($router);

$result = RouteAudit::inspect($router, array_keys((array) Config::get('permissions.permissions', [])));

echo $result['routes'] . " routes checked.\n";
echo "Public (no sign-in needed):\n";
foreach ($result['public'] as $route) {
    echo "  {$route}\n";
}
if ($result['problems'] === []) {
    echo "No problems found.\n";
    exit(0);
}
echo "Problems:\n";
foreach ($result['problems'] as $problem) {
    echo "  - {$problem}\n";
}
exit(1);
