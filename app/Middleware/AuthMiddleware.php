<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Core\Route;
use App\Core\Session;

/**
 * Requires a signed-in user, and forces a password change when the account is flagged for one.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    /** Routes a user flagged "must change password" may still reach. */
    private const ALLOWED_WHEN_FLAGGED = ['account', 'account.password', 'logout'];

    public function handle(Request $request, callable $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Authentication required.'], 401);
            }
            if ($request->isMethod('GET')) {
                Session::set('intended', $request->pathWithQuery());
            }
            if (!Session::flashed('alert')) {
                Session::flash('alert', ['type' => 'warning', 'message' => 'Please sign in to continue.']);
            } else {
                Session::reflash();
            }
            return Response::redirect('/login');
        }

        if ((int) $user['must_change_password'] === 1) {
            $route = $request->attribute('route');
            $name = $route instanceof Route ? $route->name : null;
            if (!in_array($name, self::ALLOWED_WHEN_FLAGGED, true)) {
                Session::flash('alert', ['type' => 'warning', 'message' => 'Please set a new password before continuing.']);
                return Response::redirect('/account');
            }
        }

        return $next($request);
    }
}
