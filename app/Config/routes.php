<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ExportController;
use App\Controllers\ForemanController;
use App\Controllers\ImportController;
use App\Controllers\JobController;
use App\Controllers\JobListController;
use App\Controllers\RoleController;
use App\Controllers\SettingsController;
use App\Controllers\TripController;
use App\Controllers\UserController;
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
    $router->get('/jobs', [JobListController::class, 'all'])->can('jobs.view')->name('jobs');
    $router->get('/jobs/ready', [JobListController::class, 'ready'])->can('jobs.view')->name('jobs.ready');
    $router->get('/jobs/more-than-30', [JobListController::class, 'later'])->can('jobs.view')->name('jobs.later');
    $router->get('/jobs/in-transit', [JobListController::class, 'transit'])->can('jobs.view')->name('jobs.transit');
    $router->get('/jobs/delivered', [JobListController::class, 'delivered'])->can('jobs.view')->name('jobs.delivered');
    $router->get('/jobs/create', [JobController::class, 'create'])->can('jobs.create')->name('jobs.create');
    $router->post('/jobs', [JobController::class, 'store'])->can('jobs.create')->name('jobs.store');
    $router->get('/jobs/trash', [JobController::class, 'trash'])->can('jobs.delete')->name('jobs.trash');
    // Import and export. /jobs/import and /jobs/export/... are literal paths; {id:int} only matches digits.
    $router->get('/jobs/import', [ImportController::class, 'index'])->can('jobs.import')->name('import');
    $router->post('/jobs/import', [ImportController::class, 'upload'])->can('jobs.import')->name('import.upload');
    $router->get('/jobs/import/template/{format}', [ImportController::class, 'template'])->can('jobs.import')->name('import.template');
    $router->get('/jobs/import/{id:int}', [ImportController::class, 'show'])->can('jobs.import')->name('import.show');
    $router->post('/jobs/import/{id:int}/commit', [ImportController::class, 'commit'])->can('jobs.import')->name('import.commit');
    $router->post('/jobs/import/{id:int}/cancel', [ImportController::class, 'cancel'])->can('jobs.import')->name('import.cancel');
    $router->get('/jobs/import/{id:int}/problems', [ImportController::class, 'errors'])->can('jobs.import')->name('import.problems');
    $router->get('/jobs/export/{bucket}', [ExportController::class, 'export'])->can('jobs.export')->name('export');

    $router->get('/jobs/{id:int}', [JobController::class, 'show'])->can('jobs.view')->name('jobs.show');
    $router->get('/jobs/{id:int}/edit', [JobController::class, 'edit'])->can('jobs.edit')->name('jobs.edit');
    $router->post('/jobs/{id:int}', [JobController::class, 'update'])->can('jobs.edit')->name('jobs.update');
    // Coarse gate only: JobWorkflowService checks the permission of the specific transition (jobs.status or jobs.reopen).
    $router->post('/jobs/{id:int}/status', [JobController::class, 'changeStatus'])->can('jobs.view')->name('jobs.status');
    $router->post('/jobs/{id:int}/delete', [JobController::class, 'destroy'])->can('jobs.delete')->name('jobs.delete');
    $router->post('/jobs/{id:int}/restore', [JobController::class, 'restore'])->can('jobs.delete')->name('jobs.restore');

    // Foreman trips and foremen (/trips/create is a literal path; {id:int} only matches digits).
    $router->get('/trips', [TripController::class, 'index'])->can('trips.view')->name('trips');
    $router->get('/trips/create', [TripController::class, 'create'])->can('trips.manage')->name('trips.create');
    $router->post('/trips', [TripController::class, 'store'])->can('trips.manage')->name('trips.store');
    $router->get('/trips/{id:int}', [TripController::class, 'show'])->can('trips.view')->name('trips.show');
    $router->get('/trips/{id:int}/edit', [TripController::class, 'edit'])->can('trips.manage')->name('trips.edit');
    $router->post('/trips/{id:int}', [TripController::class, 'update'])->can('trips.manage')->name('trips.update');
    $router->post('/trips/{id:int}/delete', [TripController::class, 'destroy'])->can('trips.manage')->name('trips.delete');
    $router->get('/foremen', [ForemanController::class, 'index'])->can('foremen.manage')->name('foremen');
    $router->get('/foremen/create', [ForemanController::class, 'create'])->can('foremen.manage')->name('foremen.create');
    $router->post('/foremen', [ForemanController::class, 'store'])->can('foremen.manage')->name('foremen.store');
    $router->get('/foremen/{id:int}/edit', [ForemanController::class, 'edit'])->can('foremen.manage')->name('foremen.edit');
    $router->post('/foremen/{id:int}', [ForemanController::class, 'update'])->can('foremen.manage')->name('foremen.update');
    $router->post('/foremen/{id:int}/toggle', [ForemanController::class, 'toggle'])->can('foremen.manage')->name('foremen.toggle');

    // User administration. The services add finer rules (only manage users whose permissions you hold, never remove the last administrator).
    $router->get('/users', [UserController::class, 'index'])->can('users.manage')->name('users');
    $router->get('/users/create', [UserController::class, 'create'])->can('users.manage')->name('users.create');
    $router->post('/users', [UserController::class, 'store'])->can('users.manage')->name('users.store');
    $router->get('/users/{id:int}/edit', [UserController::class, 'edit'])->can('users.manage')->name('users.edit');
    $router->post('/users/{id:int}', [UserController::class, 'update'])->can('users.manage')->name('users.update');
    $router->post('/users/{id:int}/activate', [UserController::class, 'activate'])->can('users.manage')->name('users.activate');
    $router->post('/users/{id:int}/deactivate', [UserController::class, 'deactivate'])->can('users.manage')->name('users.deactivate');
    $router->post('/users/{id:int}/reset-password', [UserController::class, 'resetPassword'])->can('users.manage')->name('users.reset');
    $router->post('/users/{id:int}/unlock', [UserController::class, 'unlock'])->can('users.manage')->name('users.unlock');
    $router->post('/users/{id:int}/delete', [UserController::class, 'destroy'])->can('users.manage')->name('users.delete');

    // Roles and the permission matrix (/roles/matrix is a literal path; {id:int} only matches digits).
    $router->get('/roles', [RoleController::class, 'index'])->can('roles.manage')->name('roles');
    $router->get('/roles/create', [RoleController::class, 'create'])->can('roles.manage')->name('roles.create');
    $router->post('/roles', [RoleController::class, 'store'])->can('roles.manage')->name('roles.store');
    $router->get('/roles/matrix', [RoleController::class, 'matrix'])->can('roles.manage')->name('roles.matrix');
    $router->post('/roles/matrix', [RoleController::class, 'saveMatrix'])->can('roles.manage')->name('roles.matrix.save');
    $router->get('/roles/{id:int}/edit', [RoleController::class, 'edit'])->can('roles.manage')->name('roles.edit');
    $router->post('/roles/{id:int}', [RoleController::class, 'update'])->can('roles.manage')->name('roles.update');
    $router->post('/roles/{id:int}/delete', [RoleController::class, 'destroy'])->can('roles.manage')->name('roles.delete');

    $router->get('/account', [AccountController::class, 'show'])->authOnly()->name('account');
    $router->post('/account/profile', [AccountController::class, 'updateProfile'])->authOnly()->name('account.profile');
    $router->post('/account/password', [AccountController::class, 'updatePassword'])->authOnly()->name('account.password');

    // Phase 7: read-only audit trail and application settings.
    $router->get('/audit', [AuditController::class, 'index'])->can('audit.view')->name('audit');
    $router->get('/audit/{id:int}', [AuditController::class, 'show'])->can('audit.view')->name('audit.show');
    $router->get('/settings', [SettingsController::class, 'edit'])->can('settings.manage')->name('settings');
    $router->post('/settings', [SettingsController::class, 'update'])->can('settings.manage')->name('settings.update');
    // The storage fee rules alone, for people who may change the rate but not the other settings.
    $router->get('/settings/storage-fee', [SettingsController::class, 'storageEdit'])->can('settings.storage_fee')->name('settings.storage');
    $router->post('/settings/storage-fee', [SettingsController::class, 'storageUpdate'])->can('settings.storage_fee')->name('settings.storage.update');
};
