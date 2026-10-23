<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use Throwable;

final class App
{
    /** Load configuration and error handling; shared by web requests and CLI scripts. */
    public static function boot(): void
    {
        Env::load(BASE_PATH . '/.env');
        date_default_timezone_set('UTC'); // all timestamps are stored in UTC
        ErrorHandler::register();
    }

    public static function run(): void
    {
        self::boot();

        header_remove('X-Powered-By');

        $request = Request::capture();

        try {
            Session::start($request);

            $router = new Router();
            $router->middleware(SecurityHeadersMiddleware::class, CsrfMiddleware::class);
            (require BASE_PATH . '/app/Config/routes.php')($router);

            $response = $router->dispatch($request);
        } catch (Throwable $e) {
            $response = ErrorHandler::handle($e);
        }

        $response->send($request->method() === 'HEAD');
    }
}
