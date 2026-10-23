<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RoleRepository;

/**
 * Permission lookups. A role's permission keys are loaded once per request,
 * so revoking a permission takes effect on the user's very next request.
 */
final class PermissionService
{
    /** @var array<int,array<int,string>> */
    private static array $cache = [];

    public static function roleHas(int $roleId, string $permission): bool
    {
        if (!isset(self::$cache[$roleId])) {
            self::$cache[$roleId] = (new RoleRepository())->permissionKeys($roleId);
        }
        return in_array($permission, self::$cache[$roleId], true);
    }

    /**
     * @return array<int,string>
     */
    public static function forRole(int $roleId): array
    {
        self::roleHas($roleId, '');
        return self::$cache[$roleId];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
