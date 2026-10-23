<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\UserCriteria;

final class UserRepository
{
    private const SELECT = 'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id';

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        return Database::fetchOne(self::SELECT . ' WHERE u.id = ? AND u.deleted_at IS NULL', [$id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return Database::fetchOne(
            self::SELECT . ' WHERE u.email = ? AND u.deleted_at IS NULL',
            [strtolower(trim($email))]
        );
    }

    /**
     * @param array{name:string,email:string,password_hash:string,role_id:int,is_active?:int,must_change_password?:int} $data
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return Database::insert(
            'INSERT INTO users (name, email, password_hash, role_id, is_active, must_change_password, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                trim($data['name']),
                strtolower(trim($data['email'])),
                $data['password_hash'],
                $data['role_id'],
                $data['is_active'] ?? 1,
                $data['must_change_password'] ?? 0,
                $now,
                $now,
            ]
        );
    }

    public function updatePassword(int $id, string $hash, bool $mustChange = false): void
    {
        Database::execute(
            'UPDATE users SET password_hash = ?, must_change_password = ?, updated_at = ? WHERE id = ?',
            [$hash, $mustChange ? 1 : 0, gmdate('Y-m-d H:i:s'), $id]
        );
    }

    public function recordLoginSuccess(int $id): void
    {
        $now = gmdate('Y-m-d H:i:s');
        Database::execute(
            'UPDATE users SET last_login_at = ?, failed_attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?',
            [$now, $now, $id]
        );
    }

    /** Adds one failed attempt and returns the new total. */
    public function incrementFailedAttempts(int $id): int
    {
        Database::execute('UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?', [$id]);
        return (int) Database::fetchValue('SELECT failed_attempts FROM users WHERE id = ?', [$id]);
    }

    public function lockUntil(int $id, string $datetime): void
    {
        Database::execute('UPDATE users SET locked_until = ? WHERE id = ?', [$datetime, $id]);
    }

    public function clearLock(int $id): void
    {
        Database::execute('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$id]);
    }

    /**
     * Fields an administrator may change on an account.
     *
     * @param array<string,mixed> $fields name, email, role_id
     */
    public function update(int $id, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip(['name', 'email', 'role_id']));
        if (isset($allowed['email'])) {
            $allowed['email'] = strtolower(trim((string) $allowed['email']));
        }
        $allowed['updated_at'] = gmdate('Y-m-d H:i:s');

        $assignments = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($allowed)));
        Database::execute("UPDATE users SET {$assignments} WHERE id = ? AND deleted_at IS NULL", [...array_values($allowed), $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        Database::execute(
            'UPDATE users SET is_active = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL',
            [$active ? 1 : 0, gmdate('Y-m-d H:i:s'), $id]
        );
    }

    /** Soft delete: the row stays (jobs and the audit trail point at it) and the email stays reserved. */
    public function softDelete(int $id): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        return Database::execute(
            'UPDATE users SET deleted_at = ?, updated_at = ?, is_active = 0 WHERE id = ? AND deleted_at IS NULL',
            [$now, $now, $id]
        ) === 1;
    }

    public function count(UserCriteria $criteria): int
    {
        [$where, $params] = $this->where($criteria);
        return (int) Database::fetchValue("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE {$where}", $params);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(UserCriteria $criteria, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($criteria);
        return Database::fetchAll(
            'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id '
            . "WHERE {$where} {$criteria->orderBySql()} LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function where(UserCriteria $c): array
    {
        $sql = 'u.deleted_at IS NULL';
        $params = [];

        if ($c->q !== '') {
            $like = '%' . strtr($c->q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $sql .= " AND (u.name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!')";
            array_push($params, $like, $like);
        }
        if ($c->roleId > 0) {
            $sql .= ' AND u.role_id = ?';
            $params[] = $c->roleId;
        }
        if ($c->status === 'active') {
            $sql .= ' AND u.is_active = 1';
        } elseif ($c->status === 'inactive') {
            $sql .= ' AND u.is_active = 0';
        } elseif ($c->status === 'locked') {
            $sql .= ' AND u.locked_until IS NOT NULL AND u.locked_until > ?';
            $params[] = gmdate('Y-m-d H:i:s');
        }

        return [$sql, $params];
    }

    /**
     * Active, non-deleted users whose role grants BOTH users.manage and roles.manage: the people who can
     * still repair access. The system refuses any change that would leave this at zero.
     */
    public function countAdministrators(?int $excludeUserId = null): int
    {
        $sql = "SELECT COUNT(*) FROM users u
                WHERE u.is_active = 1 AND u.deleted_at IS NULL
                  AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                              WHERE rp.role_id = u.role_id AND p.name = 'users.manage')
                  AND EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                              WHERE rp.role_id = u.role_id AND p.name = 'roles.manage')";
        $params = [];
        if ($excludeUserId !== null) {
            $sql .= ' AND u.id <> ?';
            $params[] = $excludeUserId;
        }
        return (int) Database::fetchValue($sql, $params);
    }

    public function isAdministrator(int $userId): bool
    {
        return $this->countAdministrators() - $this->countAdministrators($userId) === 1;
    }
}
