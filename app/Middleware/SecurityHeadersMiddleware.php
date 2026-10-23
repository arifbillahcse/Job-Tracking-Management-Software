<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;

/**
 * Adds the baseline security headers to every response, including error pages.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        return self::apply($next($request), $request);
    }

    public static function apply(Response $response, ?Request $request): Response
    {
        $cdn = Config::get('app.allow_cdn_fallback', false) ? ' https://cdn.jsdelivr.net' : '';

        $headers = [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'{$cdn}; style-src 'self'{$cdn}; "
                . "img-src 'self' data:; font-src 'self'{$cdn}; connect-src 'self'; object-src 'none'; "
                . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        // Pages show private data; stop browsers and proxies from caching them (back-button after logout).
        if (!$response->hasHeader('Cache-Control')) {
            $headers['Cache-Control'] = 'no-store, max-age=0';
        }

        if ($request !== null && $request->isSecure() && Config::get('app.env') === 'production') {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            $response->header($name, $value);
        }
        return $response;
    }
}
