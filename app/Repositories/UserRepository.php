<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

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
}
