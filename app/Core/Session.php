<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Secure session wrapper: HttpOnly + SameSite cookies, strict mode, a private
 * save path, idle and absolute timeouts, user-agent binding and one-request flash data.
 */
final class Session
{
    private static bool $started = false;

    public static function start(Request $request): void
    {
        if (self::$started) {
            return;
        }

        $savePath = BASE_PATH . '/storage/sessions';
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0700, true);
        }

        $absoluteHours = (int) Config::get('app.session.absolute_hours', 8);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        ini_set('session.gc_maxlifetime', (string) ($absoluteHours * 3600));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }

        session_cache_limiter(''); // caching headers are set centrally by SecurityHeadersMiddleware
        session_name((string) Config::get('app.session.name', 'JT_SESSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (string) Config::get('app.base_path', '') ?: '/',
            'secure' => (bool) Config::get('app.session.secure', false) || $request->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::$started = true;

        self::enforceLifetime($request);
        self::rotateFlash();
    }

    private static function enforceLifetime(Request $request): void
    {
        $now = time();
        $fingerprint = hash('sha256', $request->userAgent());

        if (isset($_SESSION['_created'])) {
            $idleLimit = (int) Config::get('app.session.idle_minutes', 30) * 60;
            $absoluteLimit = (int) Config::get('app.session.absolute_hours', 8) * 3600;

            $expired = ($now - (int) ($_SESSION['_last'] ?? $now)) > $idleLimit
                || ($now - (int) $_SESSION['_created']) > $absoluteLimit;
            $hijacked = !hash_equals((string) ($_SESSION['_ua'] ?? ''), $fingerprint);

            if ($expired || $hijacked) {
                $wasLoggedIn = isset($_SESSION['user_id']);
                self::invalidate();
                $_SESSION['_created'] = $now;
                $_SESSION['_ua'] = $fingerprint;
                if ($wasLoggedIn && $expired) {
                    self::flash('warning', 'Your session expired. Please sign in again.');
                }
            }
        } else {
            $_SESSION['_created'] = $now;
            $_SESSION['_ua'] = $fingerprint;
        }

        $_SESSION['_last'] = $now;
    }

    /** Flash data set during the previous request becomes readable now and is dropped after this request. */
    private static function rotateFlash(): void
    {
        $_SESSION['_flash'] = $_SESSION['_flash_next'] ?? [];
        unset($_SESSION['_flash_next']);
    }

    /** Clear all data and issue a new session id (used on logout and expiry). */
    public static function invalidate(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /** New session id, same data. Call on login and on privilege changes. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    /** Store a value that is available during the next request only. */
    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash_next'][$key] = $value;
    }

    public static function flashed(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash'][$key] ?? $default;
    }

    /** Keep the current flash data for one more request (redirect chains). */
    public static function reflash(): void
    {
        $_SESSION['_flash_next'] = ($_SESSION['_flash'] ?? []) + ($_SESSION['_flash_next'] ?? []);
    }
}
