<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * SQL for foremen. A foreman is deactivated, never deleted, so old trips keep their name.
 */
final class ForemanRepository
{
    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM foremen WHERE id = ?', [$id]);
    }

    /**
     * Every foreman with the number of live trips and the sum of the expense columns of those trips.
     *
     * @return array<int,array<string,mixed>>
     */
    public function allWithTotals(): array
    {
        return Database::fetchAll(
            'SELECT f.*,
                (SELECT COUNT(*) FROM trips t WHERE t.foreman_id = f.id AND t.deleted_at IS NULL) AS trip_count,
                (SELECT COALESCE(SUM(' . TripRepository::EXPENSE_SUM . '), 0) FROM trip_jobs tj JOIN trips t ON t.id = tj.trip_id
                    WHERE t.foreman_id = f.id AND t.deleted_at IS NULL) AS total_expenses
             FROM foremen f ORDER BY f.active DESC, f.name ASC'
        );
    }

    /** @return array<int,array<string,mixed>> every foreman, active or not, for a filter (old jobs may point at an inactive one) */
    public function allForFilter(): array
    {
        return Database::fetchAll('SELECT id, name, active FROM foremen ORDER BY active DESC, name ASC');
    }

    /** @return array<int,array<string,mixed>> foremen for a pick list (the active ones, plus $includeId even if inactive) */
    public function options(?int $includeId = null): array
    {
        return Database::fetchAll(
            'SELECT id, name, active FROM foremen WHERE active = 1 OR id = ? ORDER BY name ASC',
            [$includeId ?? 0]
        );
    }

    /** @param array<string,mixed> $row */
    public function create(array $row): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return Database::insert(
            'INSERT INTO foremen (name, phone, active, created_at, updated_at) VALUES (?, ?, 1, ?, ?)',
            [$row['name'], $row['phone'], $now, $now]
        );
    }

    /** @param array<string,mixed> $row */
    public function update(int $id, array $row): void
    {
        Database::execute('UPDATE foremen SET name = ?, phone = ?, updated_at = ? WHERE id = ?', [$row['name'], $row['phone'], gmdate('Y-m-d H:i:s'), $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        Database::execute('UPDATE foremen SET active = ?, updated_at = ? WHERE id = ?', [$active ? 1 : 0, gmdate('Y-m-d H:i:s'), $id]);
    }
}
