<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\ErrorPages;
use App\Core\Logger;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;

/**
 * Rejects every state-changing request (anything but GET/HEAD/OPTIONS) that
 * lacks a valid token, and any request whose Origin header names another host.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if ($request->isSafeMethod()) {
            return $next($request);
        }

        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== 'null') {
            $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
            $port = parse_url($origin, PHP_URL_PORT);
            $originAuthority = $port ? $originHost . ':' . $port : $originHost;
            if ($originAuthority !== $request->host()) {
                return $this->reject($request, 'origin mismatch');
            }
        }

        $submitted = $request->post('_csrf') ?? $request->header('X-CSRF-Token');
        if (!Csrf::validate(is_string($submitted) ? $submitted : null)) {
            return $this->reject($request, 'invalid token');
        }

        return $next($request);
    }

    private function reject(Request $request, string $reason): Response
    {
        Logger::warning('CSRF rejection', ['reason' => $reason, 'path' => $request->path(), 'ip' => $request->ip()]);

        if ($request->wantsJson()) {
            return Response::json(['error' => 'Invalid or missing security token.'], 403);
        }
        return ErrorPages::response(
            403,
            'Your session expired or the form was not valid. Please go back, refresh the page and try again.'
        );
    }
}
