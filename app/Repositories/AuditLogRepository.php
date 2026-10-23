<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AuditLogRepository
{
    public function insert(
        ?int $userId,
        string $action,
        ?string $entity,
        ?int $entityId,
        ?string $oldValues,
        ?string $newValues,
        string $ip,
        string $userAgent
    ): void {
        Database::execute(
            'INSERT INTO audit_logs (user_id, action, entity, entity_id, old_values, new_values, ip, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $action, $entity, $entityId, $oldValues, $newValues, $ip, $userAgent, gmdate('Y-m-d H:i:s')]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function latest(int $limit = 50): array
    {
        return Database::fetchAll('SELECT * FROM audit_logs ORDER BY id DESC LIMIT ?', [$limit]);
    }
}
