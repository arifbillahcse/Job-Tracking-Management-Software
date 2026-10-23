<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Core\Router;
use App\Middleware\GuestMiddleware;

/**
 * Every route must declare who may reach it: ->public(), ->authOnly() or ->can('permission.key').
 * The router refuses to run a route without one (deny by default).
 */
return static function (Router $router): void {
    $router->get('/', [DashboardController::class, 'home'])->public()->name('home');

    $router->group(['middleware' => [GuestMiddleware::class]], static function (Router $r): void {
        $r->get('/login', [AuthController::class, 'showLogin'])->public()->name('login');
        $r->post('/login', [AuthController::class, 'login'])->public()->name('login.attempt');
    });

    $router->post('/logout', [AuthController::class, 'logout'])->authOnly()->name('logout');

    $router->get('/dashboard', [DashboardController::class, 'index'])->can('dashboard.view')->name('dashboard');

    $router->get('/account', [AccountController::class, 'show'])->authOnly()->name('account');
    $router->post('/account/password', [AccountController::class, 'updatePassword'])->authOnly()->name('account.password');
};
