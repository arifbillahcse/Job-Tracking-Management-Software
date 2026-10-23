<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

/**
 * The rules that stop administration from locking people out or escalating privileges.
 *
 *  - Least privilege: you can only assign a role, or manage a user, whose permissions are all
 *    permissions you hold yourself. (A Manager with users.manage cannot make anyone an Admin.)
 *  - Never zero administrators: at least one active person must keep users.manage AND roles.manage.
 *  - You cannot change your own role, deactivate or delete yourself, or reset your own password here.
 */
final class AdminGuard
{
    /**
     * @return array<int,string>
     */
    public static function permissionsOfRole(int $roleId): array
    {
        return (new RoleRepository())->permissionKeys($roleId);
    }

    /** True when the signed-in user holds every permission of the role. */
    public static function canAssignRole(int $roleId): bool
    {
        foreach (self::permissionsOfRole($roleId) as $permission) {
            if (!Auth::can($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $user
     */
    public static function canManageUser(array $user): bool
    {
        return self::canAssignRole((int) $user['role_id']);
    }

    public static function isSelf(int $userId): bool
    {
        return Auth::id() === $userId;
    }

    /**
     * Call inside a transaction after a change: rolls everything back if nobody could administer the system any more.
     *
     * @throws LastAdministratorException
     */
    public static function assertAdministratorRemains(): void
    {
        if ((new UserRepository())->countAdministrators() < 1) {
            throw new LastAdministratorException('At least one active administrator must remain.');
        }
    }
}
