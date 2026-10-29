<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\RememberService;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth/login', ['title' => 'Sign in', 'remember' => RememberService::enabled(), 'rememberDays' => RememberService::days()], 'layouts/auth');
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'username' => 'required|lower|max:60',
            'password' => 'required|max:1000',
        ], '/login', ['username' => 'Username', 'password' => 'Password']);

        if ($data instanceof Response) {
            return $data;
        }

        $result = (new AuthService())->attempt((string) $data['username'], (string) $data['password'], $request);

        if (!$result['ok']) {
            $this->flash('danger', (string) $result['error']);
            Session::flash('old', ['username' => $data['username']]);
            return $this->redirect('/login');
        }

        Auth::login($result['user']);
        AuditService::record('login_success', 'user', (int) $result['user']['id']);
        if ($request->str('remember') === '1') {
            RememberService::issue($result['user'], $request);
        }

        $intended = Session::pull('intended');
        $target = is_string($intended) && is_internal_path($intended) ? $intended : '/dashboard';

        return $this->redirect($target);
    }

    public function logout(Request $request): Response
    {
        AuditService::record('logout', 'user', Auth::id());
        Auth::logout();
        $this->flash('success', 'You have been signed out.');
        return $this->redirect('/login');
    }
}
