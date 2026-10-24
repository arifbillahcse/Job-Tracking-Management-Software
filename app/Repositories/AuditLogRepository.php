<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\AuditCriteria;

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

    public function count(AuditCriteria $c): int
    {
        [$where, $params] = $this->where($c);
        return (int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs a WHERE {$where}", $params);
    }

    /**
     * Newest first. id is the tie-breaker, so pages never overlap.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(AuditCriteria $c, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($c);
        return Database::fetchAll(
            'SELECT a.*, u.name AS user_name, u.username AS user_username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id '
            . "WHERE {$where} ORDER BY a.id DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT a.*, u.name AS user_name, u.username AS user_username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.id = ?',
            [$id]
        );
    }

    /** @return array<int,string> */
    public function distinct(string $column): array
    {
        if (!in_array($column, ['action', 'entity'], true)) {
            throw new \InvalidArgumentException('Unsupported column');
        }
        $rows = Database::fetchAll("SELECT DISTINCT {$column} AS v FROM audit_logs WHERE {$column} IS NOT NULL ORDER BY {$column}");
        return array_column($rows, 'v');
    }

    /**
     * Everyone who has an entry, plus the users who could have one, for the filter menu.
     *
     * @return array<int,array<string,mixed>>
     */
    public function actors(): array
    {
        return Database::fetchAll('SELECT DISTINCT u.id, u.name FROM audit_logs a JOIN users u ON u.id = a.user_id ORDER BY u.name');
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function where(AuditCriteria $c): array
    {
        $sql = '1 = 1';
        $params = [];
        if ($c->action !== '') {
            $sql .= ' AND a.action = ?';
            $params[] = $c->action;
        }
        if ($c->entity !== '') {
            $sql .= ' AND a.entity = ?';
            $params[] = $c->entity;
        }
        if ($c->entityId > 0) {
            $sql .= ' AND a.entity_id = ?';
            $params[] = $c->entityId;
        }
        if ($c->userId > 0) {
            $sql .= ' AND a.user_id = ?';
            $params[] = $c->userId;
        }
        if ($c->fromUtc() !== null) {
            $sql .= ' AND a.created_at >= ?';
            $params[] = $c->fromUtc();
        }
        if ($c->toUtc() !== null) {
            $sql .= ' AND a.created_at <= ?';
            $params[] = $c->toUtc();
        }
        return [$sql, $params];
    }
}
