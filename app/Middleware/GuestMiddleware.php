<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;

/**
 * For pages meant for signed-out visitors only (the login form).
 */
final class GuestMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        return Auth::check() ? Response::redirect('/dashboard') : $next($request);
    }
}
