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

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth/login', ['title' => 'Sign in'], 'layouts/auth');
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'email' => 'required|email|max:190',
            'password' => 'required|max:1000',
        ], '/login', ['email' => 'Email', 'password' => 'Password']);

        if ($data instanceof Response) {
            return $data;
        }

        $result = (new AuthService())->attempt((string) $data['email'], (string) $data['password'], $request);

        if (!$result['ok']) {
            $this->flash('danger', (string) $result['error']);
            Session::flash('old', ['email' => $data['email']]);
            return $this->redirect('/login');
        }

        Auth::login($result['user']);
        AuditService::record('login_success', 'user', (int) $result['user']['id']);

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
