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
            if (PHP_SAPI === 'cli') {
                self::reportCli($e); // an operator at a terminal needs a sentence, not an HTML error page
            }
            self::handle($e)->send();
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::error('Fatal error: ' . $error['message'], ['file' => $error['file'], 'line' => $error['line']]);
            }
        });
    }

    /** Plain-text failure for command-line tools: what happened, the likely fix, and where the details are. Exits 1. */
    private static function reportCli(Throwable $e): never
    {
        Logger::error(get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
        if (!is_file(BASE_PATH . '/.env')) {
            fwrite(STDERR, 'There is no .env file in ' . BASE_PATH . '. Copy deploy/env.production.example to .env and fill in APP_URL and the DB_* values (docs/deployment.md, step 4).' . PHP_EOL);
        } elseif ($e instanceof \PDOException) {
            fwrite(STDERR, 'Check DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env (on cPanel the names carry your account prefix, e.g. cpuser_jobtracker).' . PHP_EOL);
        }
        fwrite(STDERR, 'Details: storage/logs/app-' . gmdate('Y-m-d') . '.log' . PHP_EOL);
        exit(1);
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
