<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Request;
use App\Repositories\AuditLogRepository;
use Throwable;

/**
 * Single entry point for audit entries. A failure to write the audit row is
 * logged but never breaks the user's action.
 */
final class AuditService
{
    /** Plain-English names for the actions the application records. Unknown actions fall back to a readable form. */
    public const LABELS = [
        'login_success' => 'Signed in', 'login_failed' => 'Failed sign-in', 'logout' => 'Signed out',
        'account_locked' => 'Account locked', 'access_denied' => 'Access denied',
        'password_changed' => 'Password changed', 'password_reset' => 'Password reset by an administrator',
        'profile_updated' => 'Profile updated',
        'remember_enabled' => 'Kept signed in on a device', 'remember_revoked' => 'Forgot remembered devices', 'remember_rejected' => 'Refused a remember-me cookie',
        'job_created' => 'Job created', 'job_updated' => 'Job updated', 'job_status_changed' => 'Job status changed',
        'job_deleted' => 'Job deleted', 'job_restored' => 'Job restored',
        'jobs_imported' => 'Jobs imported', 'jobs_exported' => 'Jobs exported',
        'user_created' => 'User created', 'user_updated' => 'User updated', 'user_deleted' => 'User deleted',
        'user_activated' => 'User activated', 'user_deactivated' => 'User deactivated', 'user_unlocked' => 'User unlocked',
        'role_created' => 'Role created', 'role_updated' => 'Role updated', 'role_deleted' => 'Role deleted',
        'role_permissions_changed' => 'Role permissions changed', 'settings_updated' => 'Settings changed',
        'trip_created' => 'Trip created', 'trip_updated' => 'Trip updated', 'trip_deleted' => 'Trip deleted', 'trips_exported' => 'Trips exported',
        'foreman_created' => 'Foreman created', 'foreman_updated' => 'Foreman updated', 'foreman_activated' => 'Foreman activated', 'foreman_deactivated' => 'Foreman deactivated',
        'maintenance_cleanup' => 'Maintenance cleanup', 'backup_created' => 'Backup created',
    ];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }

    private const SECRET_KEYS = '/password|passwd|token|secret|csrf/i';

    /**
     * @param array<string,mixed>|null $old
     * @param array<string,mixed>|null $new
     */
    public static function record(
        string $action,
        ?string $entity = null,
        ?int $entityId = null,
        ?array $old = null,
        ?array $new = null,
        ?int $userId = null
    ): void {
        try {
            $request = Request::current();
            (new AuditLogRepository())->insert(
                $userId ?? Auth::id(),
                $action,
                $entity,
                $entityId,
                $old === null ? null : self::encode($old),
                $new === null ? null : self::encode($new),
                $request?->ip() ?? 'cli',
                $request?->userAgent() ?? 'cli'
            );
        } catch (Throwable $e) {
            Logger::error('Audit write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function encode(array $values): string
    {
        return (string) json_encode(self::redact($values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (preg_match(self::SECRET_KEYS, (string) $key)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }
        return $values;
    }
}
