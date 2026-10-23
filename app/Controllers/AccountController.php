<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\PasswordPolicy;
use App\Repositories\UserRepository;
use App\Services\AuditService;

/**
 * The signed-in user's own account: profile summary and password change.
 */
final class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->view('account/show', ['title' => 'My Account']);
    }

    public function updatePassword(Request $request): Response
    {
        $user = Auth::user();

        $data = $this->validate($request, [
            'current_password' => 'required|max:1000',
            'password' => 'required|password|confirmed',
        ], '/account', [
            'current_password' => 'Current password',
            'password' => 'New password',
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        if (!password_verify((string) $data['current_password'], (string) $user['password_hash'])) {
            \App\Core\Session::flash('errors', ['current_password' => ['Your current password is incorrect.']]);
            return $this->redirect('/account');
        }

        if ((string) $data['password'] === (string) $data['current_password']) {
            \App\Core\Session::flash('errors', ['password' => ['Your new password must be different from the current one.']]);
            return $this->redirect('/account');
        }

        $hash = PasswordPolicy::hash((string) $data['password']);
        (new UserRepository())->updatePassword((int) $user['id'], $hash, false);
        Auth::refreshPasswordMarker($hash);
        AuditService::record('password_changed', 'user', (int) $user['id']);

        $this->flash('success', 'Your password has been updated.');
        return $this->redirect('/account');
    }
}
