<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class RoleRepository
{
    /**
     * @return array<string,mixed>|null
     */
    public function findByName(string $name): ?array
    {
        return Database::fetchOne('SELECT * FROM roles WHERE name = ?', [$name]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM roles WHERE id = ?', [$id]);
    }

    /** Case-insensitive name lookup (MySQL's collation is already case-insensitive; SQLite's is not). */
    public function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM roles WHERE LOWER(name) = LOWER(?)';
        $params = [$name];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return (int) Database::fetchValue($sql, $params) > 0;
    }

    /**
     * Every role with how many users it has (live and deleted) and how many permissions it grants.
     *
     * @return array<int,array<string,mixed>>
     */
    public function allWithCounts(): array
    {
        return Database::fetchAll(
            'SELECT r.*,
                (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.deleted_at IS NULL) AS user_count,
                (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count_all,
                (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
             FROM roles r ORDER BY r.is_system DESC, r.name ASC'
        );
    }

    /**
     * @return array<int,array<string,mixed>> id, name, group_name, description
     */
    public function allPermissions(): array
    {
        return Database::fetchAll('SELECT id, name, group_name, description FROM permissions ORDER BY group_name, name');
    }

    /**
     * @return array<int,array<int,int>> role id => permission ids
     */
    public function grants(): array
    {
        $grants = [];
        foreach (Database::fetchAll('SELECT role_id, permission_id FROM role_permissions') as $row) {
            $grants[(int) $row['role_id']][] = (int) $row['permission_id'];
        }
        return $grants;
    }

    public function create(string $name, ?string $description): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return Database::insert(
            'INSERT INTO roles (name, description, is_system, created_at, updated_at) VALUES (?, ?, 0, ?, ?)',
            [$name, $description, $now, $now]
        );
    }

    public function update(int $id, string $name, ?string $description): void
    {
        Database::execute(
            'UPDATE roles SET name = ?, description = ?, updated_at = ? WHERE id = ?',
            [$name, $description, gmdate('Y-m-d H:i:s'), $id]
        );
    }

    public function delete(int $id): void
    {
        Database::execute('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
        Database::execute('DELETE FROM roles WHERE id = ? AND is_system = 0', [$id]);
    }

    public function grant(int $roleId, int $permissionId): void
    {
        Database::execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $permissionId]);
    }

    public function revoke(int $roleId, int $permissionId): void
    {
        Database::execute('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?', [$roleId, $permissionId]);
    }

    /**
     * @return array<int,string> permission keys held by the role
     */
    public function permissionKeys(int $roleId): array
    {
        $rows = Database::fetchAll(
            'SELECT p.name FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = ?',
            [$roleId]
        );
        return array_column($rows, 'name');
    }
}
