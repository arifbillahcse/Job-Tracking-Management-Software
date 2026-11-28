<?php

declare(strict_types=1);

/**
 * Final review (v1.22.0): a safety net that does not depend on any one feature. Every POST route of the application, as registered, must refuse
 * a request whose CSRF token is forged and change nothing, and no route may answer a data-changing request to GET.
 * Included by tests/run.php (after phase39.php).
 */

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use App\Core\Request;
use App\Services\CompanyService;
use App\Services\DriverService;
use App\Services\InvoiceService;

section('Every POST route refuses a forged CSRF token');
fresh_db();
fresh_session();
Clock::fake('2026-11-28');
$admin = make_user('Admin', 'p40-admin@example.com');
sign_in($admin);
$job = mk_job(['job_number' => 'Z-1', 'status' => 'in_transit', 'carrier_name' => 'KEN', 'driver_name' => 'Dan', 'broker' => 'Prime', 'cf' => '10', 'carrier_rate' => '2.00', 'balance' => '50.00', 'loaded_date' => '2026-11-05']);
call('POST', '/invoices', ['carrier' => 'KEN']);
call('POST', '/companies', ['name' => 'Prime']);
call('POST', '/drivers', ['name' => 'Dan']);

$tables = ['jobs', 'trips', 'foremen', 'users', 'roles', 'settings', 'invoices', 'invoice_lines', 'companies', 'drivers', 'storage_payments', 'audit_logs'];
$snapshot = static function () use ($tables): array {
    $out = [];
    foreach ($tables as $t) {
        $out[$t] = (int) Database::fetchValue("SELECT COUNT(*) FROM {$t}");
    }
    $out['jobs_updated'] = (string) Database::fetchValue('SELECT MAX(updated_at) FROM jobs');
    $out['invoices_updated'] = (string) Database::fetchValue('SELECT MAX(updated_at) FROM invoices');
    return $out;
};
$before = $snapshot();
$router = real_router();
$tested = 0;
$notRefused = [];
foreach ($router->routes() as $route) {
    if ($route->method !== 'POST') {
        continue;
    }
    $path = (string) preg_replace('/\{[a-z_]+:int\}/', '1', $route->pattern);
    next_request();
    $_GET = [];
    Auth::reset();
    $res = $router->dispatch(Request::make('POST', $path, ['_csrf' => 'forged'], [], [], []));
    $tested++;
    if ($res->status() !== 403) {
        $notRefused[] = $path . ' -> ' . $res->status();
    }
}
check($tested >= 40, 'the check really walks the route table (' . $tested . ' POST routes found)');
check($notRefused === [], 'every POST route answers 403 to a forged token' . ($notRefused === [] ? '' : ': ' . implode(', ', $notRefused)));
check($snapshot() === $before, 'and not one of them changed a row (jobs, trips, people, roles, settings, invoices, companies, drivers, payments, audit log)');

section('No data-changing route answers GET');
$bad = [];
foreach ($router->routes() as $route) {
    if ($route->method !== 'GET') {
        continue;
    }
    if (preg_match('~/(delete|void|paid|open|status|toggle|restore|company-invoices|storage-payments|unlock|reset|import/(apply|confirm))(/|$)~', $route->pattern) === 1) {
        $bad[] = $route->pattern;
    }
}
check($bad === [], 'no GET route has a name that changes data' . ($bad === [] ? '' : ': ' . implode(', ', $bad)));

section('Money and personal data stay behind their permission');
$staff = make_user('Staff', 'p40-staff@example.com');
sign_in($staff);
$denied = [];
foreach (['/invoices', '/invoices/1', '/invoices/export', '/companies', '/drivers', '/jobs/statement', '/settings', '/users', '/audit', '/roles'] as $path) {
    $status = call('GET', $path)->status();
    if ($status !== 403) {
        $denied[] = $path . ' -> ' . $status;
    }
}
check($denied === [], 'Staff get 403 on invoices, export, companies, drivers, the statement, settings, users, audit and roles' . ($denied === [] ? '' : ': ' . implode(', ', $denied)));
$page = call('GET', '/jobs/' . $job)->body();
check(!str_contains($page, 'Carrier rate') && !str_contains($page, 'Balance') , 'and a job page shows Staff no rate and no balance');
sign_in($admin);

section('A search treats % and _ as plain characters');
call('POST', '/companies', ['name' => 'Prime Moving']);
call('POST', '/drivers', ['name' => 'Joe Smith']);
check(CompanyService::search('%') === [] && CompanyService::search('_') === [] && count(CompanyService::search('prime mov')) === 1, 'Companies: a % or _ typed in the search matches nothing, it is not a wildcard');
check(DriverService::search('%') === [] && DriverService::search('J_e') === [] && count(DriverService::search('joe sm')) === 1, 'Drivers: the same');
check(InvoiceService::search(['q' => '%'], 10, 0)['total'] === 0 && InvoiceService::search(['q' => 'k_n'], 10, 0)['total'] === 0 && InvoiceService::search(['q' => 'ken'], 10, 0)['total'] === 1, 'Invoices: the same');
