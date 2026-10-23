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
