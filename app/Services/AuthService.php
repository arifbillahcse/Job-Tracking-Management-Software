<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Request;
use App\Helpers\PasswordPolicy;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;

/**
 * Credential checking with throttling.
 *
 * Every failure - unknown email, wrong password, inactive account - returns
 * the same message so accounts cannot be enumerated. Lockouts apply to unknown
 * emails too (by failed-attempt count), so a locked response does not reveal
 * that an account exists either.
 */
final class AuthService
{
    public const MSG_INVALID = 'Invalid email or password.';
    public const MSG_LOCKED = 'Too many failed sign-in attempts. Please wait a few minutes and try again.';

    private static ?string $dummyHash = null;

    public function __construct(
        private UserRepository $users = new UserRepository(),
        private LoginAttemptRepository $attempts = new LoginAttemptRepository()
    ) {
    }

    /**
     * @return array{ok:bool,user:?array<string,mixed>,error:?string}
     */
    public function attempt(string $email, string $password, Request $request): array
    {
        $email = strtolower(trim($email));
        $ip = $request->ip();
        $now = gmdate('Y-m-d H:i:s');

        $maxFailures = (int) Config::get('app.auth.max_failed_attempts', 5);
        $lockMinutes = (int) Config::get('app.auth.lockout_minutes', 15);
        $ipLimit = (int) Config::get('app.auth.ip_max_failures', 20);
        $ipWindow = gmdate('Y-m-d H:i:s', time() - (int) Config::get('app.auth.ip_window_minutes', 15) * 60);

        if ($ip !== '' && $this->attempts->failuresByIp($ip, $ipWindow) >= $ipLimit) {
            return $this->fail($email, $ip, self::MSG_LOCKED);
        }

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Burn comparable CPU time so response timing does not reveal unknown emails.
            password_verify($password, $this->dummyHash());
            $window = gmdate('Y-m-d H:i:s', time() - $lockMinutes * 60);
            $locked = $this->attempts->failuresByEmail($email, $window) >= $maxFailures;
            return $this->fail($email, $ip, $locked ? self::MSG_LOCKED : self::MSG_INVALID);
        }

        if ($user['locked_until'] !== null && (string) $user['locked_until'] > $now) {
            return $this->fail($email, $ip, self::MSG_LOCKED);
        }

        $valid = password_verify($password, (string) $user['password_hash']);

        if (!$valid || (int) $user['is_active'] !== 1) {
            if ($valid === false) {
                $count = $this->users->incrementFailedAttempts((int) $user['id']);
                if ($count >= $maxFailures) {
                    $this->users->lockUntil((int) $user['id'], gmdate('Y-m-d H:i:s', time() + $lockMinutes * 60));
                    AuditService::record('account_locked', 'user', (int) $user['id'], null, ['failed_attempts' => $count], (int) $user['id']);
                }
            }
            return $this->fail($email, $ip, self::MSG_INVALID);
        }

        if (PasswordPolicy::needsRehash((string) $user['password_hash'])) {
            $this->users->updatePassword((int) $user['id'], PasswordPolicy::hash($password), (bool) $user['must_change_password']);
            $user = $this->users->findById((int) $user['id']) ?? $user;
        }

        $this->users->recordLoginSuccess((int) $user['id']);
        $this->attempts->record($email, $ip, true);

        return ['ok' => true, 'user' => $user, 'error' => null];
    }

    /**
     * @return array{ok:bool,user:?array<string,mixed>,error:?string}
     */
    private function fail(string $email, string $ip, string $message): array
    {
        $this->attempts->record($email, $ip, false);
        AuditService::record('login_failed', null, null, null, ['email' => $email], null);
        return ['ok' => false, 'user' => null, 'error' => $message];
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= PasswordPolicy::hash('dummy-password-for-timing-equalisation');
    }
}
