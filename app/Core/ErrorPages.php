<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Friendly error pages. Technical detail is only shown when APP_DEBUG is on
 * (never in production).
 */
final class ErrorPages
{
    private const TITLES = [
        403 => ['Access denied', "You don't have permission to do that."],
        404 => ['Page not found', "The page you're looking for doesn't exist or has moved."],
        405 => ['Method not allowed', 'That action is not supported for this page.'],
        413 => ['File too large', 'The upload is larger than this server accepts.'],
        500 => ['Something went wrong', 'An unexpected error occurred. It has been logged and we will look into it.'],
    ];

    public static function response(int $status, ?string $message = null, ?Throwable $debug = null): Response
    {
        [$title, $default] = self::TITLES[$status] ?? ['Error', 'An error occurred.'];

        try {
            $html = View::render('errors/error', [
                'title' => $title,
                'status' => $status,
                'heading' => $title,
                'message' => $message ?? $default,
                'debug' => Config::get('app.debug', false) ? $debug : null,
            ], 'layouts/auth');
        } catch (Throwable) {
            // Last resort if the layout itself is broken.
            $html = '<!doctype html><meta charset="utf-8"><title>' . $status . '</title><h1>' . $status . '</h1>';
        }

        return new Response($html, $status);
    }
}
