<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Router;

/**
 * Static review of the route table: every route has exactly one access rule, permission keys exist,
 * nothing that changes data is reachable by GET or without signing in. Used by cli/route-audit.php and the tests.
 */
final class RouteAudit
{
    /** Public POSTs that are intentional. */
    private const PUBLIC_POST_ALLOWED = ['/login'];

    /** Path endings that name an action; such routes must not answer GET. */
    private const ACTION_WORDS = ['delete', 'restore', 'commit', 'cancel', 'activate', 'deactivate', 'unlock', 'reset-password', 'logout', 'status'];

    /**
     * @param array<int,string> $knownPermissions
     * @return array{problems:array<int,string>,routes:int,public:array<int,string>}
     */
    public static function inspect(Router $router, array $knownPermissions): array
    {
        $problems = [];
        $public = [];
        $seen = [];
        $known = array_flip($knownPermissions);

        foreach ($router->routes() as $route) {
            $id = $route->method . ' ' . $route->pattern;
            $rules = (int) $route->isPublic + (int) $route->isAuthOnly + (int) ($route->permission !== null);

            if (isset($seen[$id])) {
                $problems[] = "{$id}: declared twice";
            }
            $seen[$id] = true;

            if ($rules === 0) {
                $problems[] = "{$id}: no access rule (public, authOnly or can)";
            } elseif ($rules > 1) {
                $problems[] = "{$id}: more than one access rule";
            }
            if ($route->permission !== null && !isset($known[$route->permission])) {
                $problems[] = "{$id}: unknown permission \"{$route->permission}\"";
            }
            if ($route->isPublic) {
                $public[] = $id;
                if ($route->method !== 'GET' && !in_array($route->pattern, self::PUBLIC_POST_ALLOWED, true)) {
                    $problems[] = "{$id}: a public route that changes data";
                }
            }
            if ($route->method === 'GET') {
                if (in_array(basename($route->pattern), self::ACTION_WORDS, true)) {
                    $problems[] = "{$id}: looks like an action but answers GET";
                }
            }
        }

        return ['problems' => $problems, 'routes' => count($router->routes()), 'public' => $public];
    }
}
