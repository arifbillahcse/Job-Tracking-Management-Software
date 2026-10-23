<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\JobController;
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

    // /jobs/create and /jobs/trash are literal paths; {id:int} only ever matches digits, so there is no clash.
    $router->get('/jobs', [JobController::class, 'index'])->can('jobs.view')->name('jobs');
    $router->get('/jobs/create', [JobController::class, 'create'])->can('jobs.create')->name('jobs.create');
    $router->post('/jobs', [JobController::class, 'store'])->can('jobs.create')->name('jobs.store');
    $router->get('/jobs/trash', [JobController::class, 'trash'])->can('jobs.delete')->name('jobs.trash');
    $router->get('/jobs/{id:int}', [JobController::class, 'show'])->can('jobs.view')->name('jobs.show');
    $router->get('/jobs/{id:int}/edit', [JobController::class, 'edit'])->can('jobs.edit')->name('jobs.edit');
    $router->post('/jobs/{id:int}', [JobController::class, 'update'])->can('jobs.edit')->name('jobs.update');
    $router->post('/jobs/{id:int}/delete', [JobController::class, 'destroy'])->can('jobs.delete')->name('jobs.delete');
    $router->post('/jobs/{id:int}/restore', [JobController::class, 'restore'])->can('jobs.delete')->name('jobs.restore');

    $router->get('/account', [AccountController::class, 'show'])->authOnly()->name('account');
    $router->post('/account/password', [AccountController::class, 'updatePassword'])->authOnly()->name('account.password');
};
