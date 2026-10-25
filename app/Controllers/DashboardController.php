<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\DashboardService;
use App\Services\JobService;
use App\Services\PermissionService;

final class DashboardController extends Controller
{
    /** "/" sends signed-in users to the dashboard and everyone else to the login page. */
    public function home(Request $request): Response
    {
        Session::reflash();
        return $this->redirect(Auth::check() ? '/dashboard' : '/login');
    }

    public function index(Request $request): Response
    {
        \App\Services\StorageFeeService::refreshIfDue(); // fees follow the calendar: re-assess once a day
        $user = Auth::user();

        // Dashboard access alone does not reveal job data: that needs jobs.view.
        $data = can('jobs.view') ? (new DashboardService())->build((new JobService())->canSeeFinancials()) : null;

        return $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'data' => $data,
            'permissions' => PermissionService::forRole((int) $user['role_id']),
        ]);
    }
}
