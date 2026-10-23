<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * SQL for jobs. Soft-deleted rows are excluded everywhere except trashed()/findTrashed().
 */
final class JobRepository
{
    private const DETAIL_SELECT = 'SELECT j.*, cu.name AS created_by_name, uu.name AS updated_by_name
        FROM jobs j
        LEFT JOIN users cu ON cu.id = j.created_by
        LEFT JOIN users uu ON uu.id = j.updated_by';

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return Database::fetchOne(self::DETAIL_SELECT . ' WHERE j.id = ? AND j.deleted_at IS NULL', [$id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findTrashed(int $id): ?array
    {
        return Database::fetchOne(self::DETAIL_SELECT . ' WHERE j.id = ? AND j.deleted_at IS NOT NULL', [$id]);
    }

    /**
     * @param array<string,mixed> $row column => value (already whitelisted by JobService)
     */
    public function create(array $row, ?int $userId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $row += ['created_by' => $userId, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now];

        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO jobs (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        return Database::insert($sql, array_values($row));
    }

    /**
     * @param array<string,mixed> $row column => value (already whitelisted by JobService)
     */
    public function update(int $id, array $row, ?int $userId): void
    {
        $row += ['updated_by' => $userId, 'updated_at' => gmdate('Y-m-d H:i:s')];

        $assignments = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($row)));
        Database::execute(
            "UPDATE jobs SET {$assignments} WHERE id = ? AND deleted_at IS NULL",
            [...array_values($row), $id]
        );
    }

    public function softDelete(int $id, ?int $userId): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        return Database::execute(
            'UPDATE jobs SET deleted_at = ?, updated_at = ?, updated_by = ? WHERE id = ? AND deleted_at IS NULL',
            [$now, $now, $userId, $id]
        ) === 1;
    }

    public function restore(int $id, ?int $userId): bool
    {
        return Database::execute(
            'UPDATE jobs SET deleted_at = NULL, updated_at = ?, updated_by = ? WHERE id = ? AND deleted_at IS NOT NULL',
            [gmdate('Y-m-d H:i:s'), $userId, $id]
        ) === 1;
    }

    public function count(): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM jobs WHERE deleted_at IS NULL');
    }

    /**
     * Newest first. Search, filters and sorting arrive with the list engine in Phase 3.
     *
     * @return array<int,array<string,mixed>>
     */
    public function page(int $limit, int $offset): array
    {
        return Database::fetchAll(
            'SELECT * FROM jobs WHERE deleted_at IS NULL ORDER BY id DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function trashed(): array
    {
        return Database::fetchAll(
            'SELECT j.*, u.name AS updated_by_name FROM jobs j LEFT JOIN users u ON u.id = j.updated_by
             WHERE j.deleted_at IS NOT NULL ORDER BY j.deleted_at DESC, j.id DESC'
        );
    }

    public function jobNumberExists(string $jobNumber): bool
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM jobs WHERE job_number = ?', [$jobNumber]) > 0;
    }
}
