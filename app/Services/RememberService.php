<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\UserRepository;

/**
 * "Keep me signed in on this device" (remember me).
 *
 * The browser keeps a cookie "selector:validator" (random, HttpOnly, SameSite=Lax, Secure over HTTPS). The database keeps
 * only the selector and a SHA-256 hash of the validator. When a request arrives without a signed-in session but with a valid
 * cookie, the user is signed in again with a fresh session, exactly as if they had typed their password.
 *
 * A token is refused (and removed) when it is unknown, expired, has a wrong validator, belongs to an account that is inactive,
 * locked or gone, or was issued before the user's password last changed. Signing out removes this browser's token;
 * "Sign out on all devices" removes them all. The lifetime is fixed from the moment of issue (REMEMBER_ME_DAYS); using the
 * cookie does not extend it. REMEMBER_ME_DAYS=0 turns the feature off.
 */
final class RememberService
{
    public const COOKIE = 'JT_REMEMBER';

    /** The last cookie this process sent (name => value, empty value = deleted); read by the tests. */
    public static ?string $lastCookie = null;

    public static function days(): int
    {
        return max(0, min(90, (int) Config::get('app.remember.days', 14)));
    }

    /**
     * On when REMEMBER_ME_DAYS > 0 and the table exists. A server that has the new code but has not run `php cli/migrate.php`
     * yet simply has no checkbox; signing in must never fail because of it.
     */
    public static function enabled(): bool
    {
        if (self::days() <= 0) {
            return false;
        }
        if (self::$tableReady === null || self::$tableReadyFor !== Database::connect()) {
            try {
                Database::fetchAll('SELECT id FROM remember_tokens WHERE 1 = 0');
                self::$tableReady = true;
            } catch (\Throwable) {
                self::$tableReady = false;
            }
            self::$tableReadyFor = Database::connect();
        }
        return self::$tableReady;
    }

    private static ?bool $tableReady = null;
    private static ?\PDO $tableReadyFor = null;

    /** Remember this browser for the user who just signed in with their password. */
    public static function issue(array $user, ?Request $request = null): void
    {
        if (!self::enabled()) {
            return;
        }
        $request ??= Request::current();
        $now = gmdate('Y-m-d H:i:s');
        Database::execute('DELETE FROM remember_tokens WHERE expires_at < ?', [$now]); // housekeeping
        // A user keeps at most a handful of remembered browsers; the oldest ones are dropped.
        $old = Database::fetchAll('SELECT id FROM remember_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 100 OFFSET 9', [(int) $user['id']]);
        foreach ($old as $row) {
            Database::execute('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);
        }

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + self::days() * 86400;
        Database::execute(
            'INSERT INTO remember_tokens (user_id, selector, token_hash, pw_marker, user_agent, ip, created_at, last_used_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $user['id'], $selector, hash('sha256', $validator), self::marker((string) $user['password_hash']),
                mb_substr($request?->userAgent() ?? '', 0, 255), $request?->ip() ?? '', $now, $now, gmdate('Y-m-d H:i:s', $expires)]
        );
        self::sendCookie($selector . ':' . $validator, $expires, $request);
        AuditService::record('remember_enabled', 'user', (int) $user['id'], null, ['days' => self::days()], (int) $user['id']);
    }

    /** The user this browser's cookie stands for, or null. Called when there is no signed-in session. */
    public static function restore(): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $cookie = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($cookie) || preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $cookie, $m) !== 1) {
            if ($cookie !== null) {
                self::clearCookie();
            }
            return null;
        }
        [, $selector, $validator] = $m;

        $row = Database::fetchOne('SELECT * FROM remember_tokens WHERE selector = ?', [$selector]);
        if ($row === null || (string) $row['expires_at'] < gmdate('Y-m-d H:i:s')) {
            if ($row !== null) {
                Database::execute('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);
            }
            self::clearCookie();
            return null;
        }
        if (!hash_equals((string) $row['token_hash'], hash('sha256', $validator))) {
            // The selector is right but the secret is not: someone is guessing. Forget the token.
            Database::execute('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);
            AuditService::record('remember_rejected', 'user', (int) $row['user_id'], null, ['reason' => 'wrong validator'], (int) $row['user_id']);
            self::clearCookie();
            return null;
        }

        $user = (new UserRepository())->findById((int) $row['user_id']);
        $locked = $user !== null && $user['locked_until'] !== null && (string) $user['locked_until'] > gmdate('Y-m-d H:i:s');
        if ($user === null || (int) $user['is_active'] !== 1 || $locked || !hash_equals((string) $row['pw_marker'], self::marker((string) $user['password_hash']))) {
            Database::execute('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);
            self::clearCookie();
            return null;
        }

        if ((string) $row['last_used_at'] < gmdate('Y-m-d H:i:s', time() - 3600)) {
            Database::execute('UPDATE remember_tokens SET last_used_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $row['id']]);
        }
        AuditService::record('login_success', 'user', (int) $user['id'], null, ['via' => 'remember me'], (int) $user['id']);
        return $user;
    }

    /** Forget this browser (sign out). */
    public static function forget(): void
    {
        $cookie = $_COOKIE[self::COOKIE] ?? null;
        if (is_string($cookie) && preg_match('/^([0-9a-f]{24}):/', $cookie, $m) === 1) {
            Database::execute('DELETE FROM remember_tokens WHERE selector = ?', [$m[1]]);
        }
        self::clearCookie();
    }

    /** Forget every browser of a user ("Sign out on all devices"). Returns how many were removed. */
    public static function revokeAll(int $userId): int
    {
        return Database::execute('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
    }

    public static function count(int $userId): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ? AND expires_at >= ?', [$userId, gmdate('Y-m-d H:i:s')]);
    }

    private static function marker(string $passwordHash): string
    {
        return substr(hash('sha256', $passwordHash), 0, 32);
    }

    private static function clearCookie(): void
    {
        unset($_COOKIE[self::COOKIE]);
        self::sendCookie('', time() - 86400, Request::current());
    }

    private static function sendCookie(string $value, int $expires, ?Request $request): void
    {
        self::$lastCookie = $value;
        if ($value !== '') {
            $_COOKIE[self::COOKIE] = $value;
        }
        if (!headers_sent()) {
            setcookie(self::COOKIE, $value, [
                'expires' => $expires,
                'path' => (string) Config::get('app.base_path', '') ?: '/',
                'secure' => (bool) Config::get('app.session.secure', false) || ($request?->isSecure() ?? false),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}
