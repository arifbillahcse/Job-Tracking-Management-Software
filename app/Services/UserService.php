<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Helpers\PasswordPolicy;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use PDOException;

/**
 * User administration. Every method returns ['ok' => bool, 'code' => ...] instead of throwing for
 * expected refusals, so controllers can turn each into a clear message:
 *   not_found | forbidden | self | last_admin | invalid_role | duplicate_email
 *
 * Passwords are never stored in plain text and never written to the audit log. Temporary passwords are
 * generated here, hashed, flagged "must change", and handed back once for the administrator to pass on.
 */
final class UserService
{
    public function __construct(
        private UserRepository $users = new UserRepository(),
        private RoleRepository $roles = new RoleRepository()
    ) {
    }

    /**
     * @param array<string,mixed> $input validated: name, email, role_id
     * @return array{ok:bool,code?:string,id?:int,password?:string}
     */
    public function create(array $input): array
    {
        $roleId = (int) $input['role_id'];
        $role = $this->roles->find($roleId);
        if ($role === null) {
            return ['ok' => false, 'code' => 'invalid_role'];
        }
        if (!AdminGuard::canAssignRole($roleId)) {
            return ['ok' => false, 'code' => 'forbidden'];
        }

        $name = trim((string) $input['name']);
        $password = PasswordPolicy::generate(16);

        try {
            $id = $this->users->create([
                'name' => $name,
                'email' => (string) $input['email'],
                'password_hash' => PasswordPolicy::hash($password),
                'role_id' => $roleId,
                'must_change_password' => 1,
            ]);
        } catch (PDOException $e) {
            if ($this->isDuplicateEmail($e)) {
                return ['ok' => false, 'code' => 'duplicate_email'];
            }
            throw $e;
        }

        AuditService::record('user_created', 'user', $id, null, [
            'name' => $name, 'email' => strtolower(trim((string) $input['email'])), 'role' => $role['name'],
        ]);

        return ['ok' => true, 'id' => $id, 'password' => $password];
    }

    /**
     * @param array<string,mixed> $input validated: name, email, role_id
     * @return array{ok:bool,code?:string,changed?:array<int,string>}
     */
    public function update(int $id, array $input): array
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        if (!AdminGuard::canManageUser($user)) {
            return ['ok' => false, 'code' => 'forbidden'];
        }

        $newRoleId = (int) $input['role_id'];
        $roleChanges = $newRoleId !== (int) $user['role_id'];

        if ($roleChanges) {
            if (AdminGuard::isSelf($id)) {
                return ['ok' => false, 'code' => 'self'];
            }
            if ($this->roles->find($newRoleId) === null) {
                return ['ok' => false, 'code' => 'invalid_role'];
            }
            if (!AdminGuard::canAssignRole($newRoleId)) {
                return ['ok' => false, 'code' => 'forbidden'];
            }
        }

        $fields = ['name' => trim((string) $input['name']), 'email' => strtolower(trim((string) $input['email'])), 'role_id' => $newRoleId];
        $old = [];
        $new = [];
        foreach (['name', 'email'] as $column) {
            if ((string) $user[$column] !== $fields[$column]) {
                $old[$column] = $user[$column];
                $new[$column] = $fields[$column];
            }
        }
        if ($roleChanges) {
            $old['role'] = $user['role_name'];
            $new['role'] = $this->roles->find($newRoleId)['name'];
        }
        if ($new === []) {
            return ['ok' => true, 'changed' => []];
        }

        try {
            Database::transaction(function () use ($id, $fields): void {
                $this->users->update($id, $fields);
                AdminGuard::assertAdministratorRemains();
            });
        } catch (LastAdministratorException) {
            return ['ok' => false, 'code' => 'last_admin'];
        } catch (PDOException $e) {
            if ($this->isDuplicateEmail($e)) {
                return ['ok' => false, 'code' => 'duplicate_email'];
            }
            throw $e;
        }

        AuditService::record('user_updated', 'user', $id, $old, $new);
        return ['ok' => true, 'changed' => array_keys($new)];
    }

    /**
     * @return array{ok:bool,code?:string}
     */
    public function setActive(int $id, bool $active): array
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        if (!AdminGuard::canManageUser($user)) {
            return ['ok' => false, 'code' => 'forbidden'];
        }
        if (!$active && AdminGuard::isSelf($id)) {
            return ['ok' => false, 'code' => 'self'];
        }

        try {
            Database::transaction(function () use ($id, $active): void {
                $this->users->setActive($id, $active);
                AdminGuard::assertAdministratorRemains();
            });
        } catch (LastAdministratorException) {
            return ['ok' => false, 'code' => 'last_admin'];
        }

        AuditService::record($active ? 'user_activated' : 'user_deactivated', 'user', $id, null, ['email' => $user['email']]);
        return ['ok' => true];
    }

    /**
     * Replace the password with a generated temporary one (shown once). The user must change it at next sign-in,
     * the account is unlocked, and every other session of that user ends (the password changed).
     *
     * @return array{ok:bool,code?:string,password?:string,email?:string}
     */
    public function resetPassword(int $id): array
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        if (!AdminGuard::canManageUser($user)) {
            return ['ok' => false, 'code' => 'forbidden'];
        }
        if (AdminGuard::isSelf($id)) {
            return ['ok' => false, 'code' => 'self']; // use My Account, which asks for the current password
        }

        $password = PasswordPolicy::generate(16);
        $this->users->updatePassword($id, PasswordPolicy::hash($password), true);
        $this->users->clearLock($id);

        AuditService::record('password_reset', 'user', $id, null, ['email' => $user['email']]);
        return ['ok' => true, 'password' => $password, 'email' => (string) $user['email']];
    }

    /**
     * @return array{ok:bool,code?:string}
     */
    public function unlock(int $id): array
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        if (!AdminGuard::canManageUser($user)) {
            return ['ok' => false, 'code' => 'forbidden'];
        }

        $this->users->clearLock($id);
        AuditService::record('user_unlocked', 'user', $id, null, ['email' => $user['email']]);
        return ['ok' => true];
    }

    /**
     * @return array{ok:bool,code?:string}
     */
    public function delete(int $id): array
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        if (!AdminGuard::canManageUser($user)) {
            return ['ok' => false, 'code' => 'forbidden'];
        }
        if (AdminGuard::isSelf($id)) {
            return ['ok' => false, 'code' => 'self'];
        }

        try {
            Database::transaction(function () use ($id): void {
                $this->users->softDelete($id);
                AdminGuard::assertAdministratorRemains();
            });
        } catch (LastAdministratorException) {
            return ['ok' => false, 'code' => 'last_admin'];
        }

        AuditService::record('user_deleted', 'user', $id, ['email' => $user['email'], 'name' => $user['name']]);
        return ['ok' => true];
    }

    /** Whether the signed-in administrator may act on this user at all (hides buttons they could not use). */
    public function canManage(array $user): bool
    {
        return AdminGuard::canManageUser($user);
    }

    /** Roles the signed-in administrator may assign (those whose permissions they all hold). */
    public function assignableRoles(): array
    {
        return array_values(array_filter(
            $this->roles->allWithCounts(),
            static fn (array $role): bool => AdminGuard::canAssignRole((int) $role['id'])
        ));
    }

    private function isDuplicateEmail(PDOException $e): bool
    {
        return (string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'email');
    }
}
