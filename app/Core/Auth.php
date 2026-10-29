<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\UserRepository;
use App\Services\PermissionService;

/**
 * Who is signed in. The user row is re-read from the database on every
 * request, so deactivating an account, changing its role or revoking a
 * permission takes effect immediately, and a password change signs out
 * every other session of that user.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

    /**
     * @return array<string,mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $id = Session::get('user_id');
        if (!is_int($id)) {
            // Not signed in: a valid "keep me signed in" cookie signs the user in again with a fresh session.
            $remembered = \App\Services\RememberService::restore();
            if ($remembered === null) {
                return self::$user = null;
            }
            self::login($remembered);
            self::$resolved = true;
            $id = (int) $remembered['id'];
        }

        $user = (new UserRepository())->findById($id);
        $marker = Session::get('pw_marker');

        if ($user === null || (int) $user['is_active'] !== 1 || !is_string($marker)
            || !hash_equals(self::marker((string) $user['password_hash']), $marker)) {
            Session::invalidate();
            return self::$user = null;
        }

        return self::$user = $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user === null ? null : (int) $user['id'];
    }

    /**
     * @param array<string,mixed> $user
     */
    public static function login(array $user): void
    {
        Session::regenerate();
        Csrf::rotate();
        Session::set('user_id', (int) $user['id']);
        Session::set('pw_marker', self::marker((string) $user['password_hash']));
        self::reset();
    }

    public static function logout(): void
    {
        \App\Services\RememberService::forget();
        Session::invalidate();
        self::reset();
    }

    /** Call after the signed-in user changes their own password so this session stays valid. */
    public static function refreshPasswordMarker(string $newHash): void
    {
        Session::set('pw_marker', self::marker($newHash));
        Session::regenerate();
        Csrf::rotate();
        self::reset();
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        return $user !== null && PermissionService::roleHas((int) $user['role_id'], $permission);
    }

    /** Forget the cached user (tests, and after login/logout within one request). */
    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
    }

    private static function marker(string $passwordHash): string
    {
        return substr(hash('sha256', $passwordHash), 0, 32);
    }
}
