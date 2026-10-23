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
