<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\SecurityHeadersMiddleware;
use ErrorException;
use Throwable;

final class ErrorHandler
{
    public static function register(): void
    {
        $debug = (bool) Config::get('app.debug', false);

        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $e): void {
            self::handle($e)->send();
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::error('Fatal error: ' . $error['message'], ['file' => $error['file'], 'line' => $error['line']]);
            }
        });
    }

    public static function handle(Throwable $e): Response
    {
        Logger::error(get_class($e) . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => substr($e->getTraceAsString(), 0, 2000),
            'uri' => Request::current()?->path(),
        ]);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        return SecurityHeadersMiddleware::apply(ErrorPages::response(500, null, $e), Request::current());
    }
}
