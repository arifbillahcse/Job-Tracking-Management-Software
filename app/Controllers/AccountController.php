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
use App\Services\AuthService;
use App\Services\RememberService;

/**
 * The signed-in user's own account: profile summary and password change.
 */
final class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->view('account/show', [
            'title' => 'My Account',
            'rememberEnabled' => RememberService::enabled(),
            'remembered' => RememberService::enabled() ? RememberService::count((int) Auth::id()) : 0,
            'rememberDays' => RememberService::days(),
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $user = Auth::user();

        $data = $this->validate($request, ['name' => 'required|max:120'], '/account', ['name' => 'Name']);
        if ($data instanceof Response) {
            return $data;
        }

        if ((string) $data['name'] !== (string) $user['name']) {
            (new UserRepository())->update((int) $user['id'], ['name' => $data['name']]);
            AuditService::record('profile_updated', 'user', (int) $user['id'], ['name' => $user['name']], ['name' => $data['name']]);
            $this->flash('success', 'Your name was updated.');
        } else {
            $this->flash('info', 'No changes were made.');
        }
        return $this->redirect('/account');
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

        $check = (new AuthService())->confirmPassword($user, (string) $data['current_password']);
        if ($check === 'locked') {
            Auth::logout();
            $this->flash('danger', AuthService::MSG_LOCKED);
            return $this->redirect('/login');
        }
        if ($check !== 'ok') {
            \App\Core\Session::flash('errors', ['current_password' => ['Your current password is incorrect.']]);
            return $this->redirect('/account');
        }

        if ((string) $data['password'] === (string) $data['current_password']) {
            \App\Core\Session::flash('errors', ['password' => ['Your new password must be different from the current one.']]);
            return $this->redirect('/account');
        }

        $hash = PasswordPolicy::hash((string) $data['password']);
        (new UserRepository())->updatePassword((int) $user['id'], $hash, false);
        (new UserRepository())->clearLock((int) $user['id']);
        Auth::refreshPasswordMarker($hash);
        RememberService::revokeAll((int) $user['id']); // every remembered browser must sign in with the new password
        AuditService::record('password_changed', 'user', (int) $user['id']);

        $this->flash('success', 'Your password has been updated.');
        return $this->redirect('/account');
    }

    /** "Forget all devices": removes every "keep me signed in" cookie of this user, including this browser's. */
    public function forgetDevices(Request $request): Response
    {
        $n = RememberService::revokeAll((int) Auth::id());
        RememberService::forget();
        AuditService::record('remember_revoked', 'user', Auth::id(), null, ['devices' => $n]);
        $this->flash('success', $n === 0 ? 'No device was remembered.' : 'Forgotten on ' . $n . ' device' . ($n === 1 ? '' : 's') . '.');
        return $this->redirect('/account');
    }
}
