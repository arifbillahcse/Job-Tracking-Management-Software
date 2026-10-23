<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\ErrorPages;
use App\Core\Logger;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;

/**
 * Deny by default: the signed-in user must hold the route's permission key.
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    public function __construct(private string $permission)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (Auth::can($this->permission)) {
            return $next($request);
        }

        Logger::warning('Permission denied', [
            'user_id' => Auth::id(),
            'permission' => $this->permission,
            'path' => $request->path(),
        ]);
        AuditService::record('access_denied', null, null, null, [
            'permission' => $this->permission,
            'path' => $request->path(),
            'method' => $request->method(),
        ]);

        if ($request->wantsJson()) {
            return Response::json(['error' => 'You do not have permission to do that.'], 403);
        }
        return ErrorPages::response(403);
    }
}
