<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\RoleRepository;

/**
 * Role and permission administration. Refusals come back as ['ok' => false, 'code' => ...]:
 *   not_found | system_role | duplicate_name | has_users | conflict | last_admin
 *
 * Roles only group permissions; the code checks permission keys, never role names. The Admin role is a
 * system role: it cannot be renamed or deleted and always holds every permission.
 */
final class RoleService
{
    public function __construct(private RoleRepository $roles = new RoleRepository())
    {
    }

    /**
     * @param int|null $copyFromId start with another role's permissions (only those the current user holds)
     * @return array{ok:bool,code?:string,id?:int}
     */
    public function create(string $name, ?string $description, ?int $copyFromId): array
    {
        if ($this->roles->nameTaken($name)) {
            return ['ok' => false, 'code' => 'duplicate_name'];
        }

        $source = $copyFromId !== null ? $this->roles->find($copyFromId) : null;
        if ($copyFromId !== null && $source === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }

        $id = Database::transaction(function () use ($name, $description, $source): int {
            $id = $this->roles->create($name, $description);
            if ($source !== null) {
                $byName = array_column($this->roles->allPermissions(), 'id', 'name');
                foreach ($this->roles->permissionKeys((int) $source['id']) as $permission) {
                    if (Auth::can($permission) && isset($byName[$permission])) {
                        $this->roles->grant($id, (int) $byName[$permission]);
                    }
                }
            }
            return $id;
        });

        PermissionService::flush();
        AuditService::record('role_created', 'role', $id, null, [
            'name' => $name,
            'copied_from' => $source['name'] ?? null,
            'permissions' => $this->roles->permissionKeys($id),
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * @return array{ok:bool,code?:string,changed?:array<int,string>}
     */
    public function update(int $id, string $name, ?string $description): array
    {
        $role = $this->roles->find($id);
        if ($role === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        if ((int) $role['is_system'] === 1 && $name !== (string) $role['name']) {
            return ['ok' => false, 'code' => 'system_role'];
        }
        if ($this->roles->nameTaken($name, $id)) {
            return ['ok' => false, 'code' => 'duplicate_name'];
        }

        $old = [];
        $new = [];
        if ((string) $role['name'] !== $name) {
            $old['name'] = $role['name'];
            $new['name'] = $name;
        }
        if ((string) ($role['description'] ?? '') !== (string) ($description ?? '')) {
            $old['description'] = $role['description'];
            $new['description'] = $description;
        }
        if ($new === []) {
            return ['ok' => true, 'changed' => []];
        }

        $this->roles->update($id, $name, $description);
        AuditService::record('role_updated', 'role', $id, $old, $new);
        return ['ok' => true, 'changed' => array_keys($new)];
    }

    /**
     * @return array{ok:bool,code?:string,users?:int}
     */
    public function delete(int $id): array
    {
        $role = $this->roles->find($id);
        if ($role === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }
        if ((int) $role['is_system'] === 1) {
            return ['ok' => false, 'code' => 'system_role'];
        }

        // Deleted users still point at the role (the foreign key), so they count too.
        $row = null;
        foreach ($this->roles->allWithCounts() as $candidate) {
            if ((int) $candidate['id'] === $id) {
                $row = $candidate;
            }
        }
        if ($row !== null && (int) $row['user_count_all'] > 0) {
            return ['ok' => false, 'code' => 'has_users', 'users' => (int) $row['user_count_all']];
        }

        $permissions = $this->roles->permissionKeys($id);
        $this->roles->delete($id);
        PermissionService::flush();
        AuditService::record('role_deleted', 'role', $id, ['name' => $role['name'], 'permissions' => $permissions]);
        return ['ok' => true];
    }

    /**
     * Everything the matrix screen needs.
     *
     * @return array{roles:array<int,array<string,mixed>>,groups:array<string,array<int,array<string,mixed>>>,granted:array<int,array<int,bool>>,editable:array<int,bool>,version:string}
     */
    public function matrix(): array
    {
        $roles = $this->roles->allWithCounts();
        $grants = $this->roles->grants();

        $groups = [];
        $editable = [];
        foreach ($this->roles->allPermissions() as $permission) {
            // A user can only grant or revoke permissions they hold themselves.
            $permission['can_edit'] = Auth::can((string) $permission['name']);
            $groups[(string) $permission['group_name']][] = $permission;
            $editable[(int) $permission['id']] = $permission['can_edit'];
        }

        $granted = [];
        foreach ($grants as $roleId => $permissionIds) {
            foreach ($permissionIds as $permissionId) {
                $granted[$roleId][$permissionId] = true;
            }
        }

        return [
            'roles' => $roles,
            'groups' => $groups,
            'granted' => $granted,
            'editable' => $editable,
            'version' => $this->version($grants),
        ];
    }

    /**
     * Save the matrix. Rules:
     *  - system roles (Admin) are never changed, whatever is posted;
     *  - a permission the current user does not hold cannot be granted or revoked by them;
     *  - if the matrix changed since the page was opened (version differs) nothing is saved;
     *  - the change is rolled back if it would leave no active administrator.
     *
     * @param array<mixed> $submitted role id => list of permission ids (as posted)
     * @return array{ok:bool,code?:string,roles_changed?:int}
     */
    public function saveMatrix(array $submitted, string $version): array
    {
        $grants = $this->roles->grants();
        if (!hash_equals($this->version($grants), $version)) {
            return ['ok' => false, 'code' => 'conflict'];
        }

        $permissions = $this->roles->allPermissions();
        $nameById = array_column($permissions, 'name', 'id');
        $validIds = array_map('intval', array_keys($nameById));
        $held = [];
        foreach ($nameById as $id => $name) {
            $held[(int) $id] = Auth::can((string) $name);
        }

        $changes = [];
        try {
            Database::transaction(function () use ($grants, $submitted, $validIds, $held, $nameById, &$changes): void {
                foreach ($this->roles->allWithCounts() as $role) {
                    $roleId = (int) $role['id'];
                    if ((int) $role['is_system'] === 1) {
                        continue;
                    }

                    $current = array_map('intval', $grants[$roleId] ?? []);
                    $posted = array_map('intval', is_array($submitted[$roleId] ?? null) ? $submitted[$roleId] : []);
                    $desired = array_values(array_intersect($posted, $validIds));

                    // Permissions the user does not hold keep whatever state they have now.
                    $desired = array_values(array_unique(array_merge(
                        array_filter($desired, static fn (int $id): bool => $held[$id]),
                        array_filter($current, static fn (int $id): bool => !$held[$id])
                    )));

                    $added = array_diff($desired, $current);
                    $removed = array_diff($current, $desired);
                    if ($added === [] && $removed === []) {
                        continue;
                    }

                    foreach ($added as $permissionId) {
                        $this->roles->grant($roleId, $permissionId);
                    }
                    foreach ($removed as $permissionId) {
                        $this->roles->revoke($roleId, $permissionId);
                    }
                    $changes[$roleId] = [
                        'name' => (string) $role['name'],
                        'added' => array_map(static fn (int $id): string => (string) $nameById[$id], array_values($added)),
                        'removed' => array_map(static fn (int $id): string => (string) $nameById[$id], array_values($removed)),
                    ];
                }
                AdminGuard::assertAdministratorRemains();
            });
        } catch (LastAdministratorException) {
            return ['ok' => false, 'code' => 'last_admin'];
        }

        PermissionService::flush(); // takes effect on the very next request, no re-login needed
        foreach ($changes as $roleId => $change) {
            AuditService::record('role_permissions_changed', 'role', $roleId, ['removed' => $change['removed']], ['role' => $change['name'], 'added' => $change['added']]);
        }

        return ['ok' => true, 'roles_changed' => count($changes)];
    }

    /**
     * Fingerprint of the current role/permission grants, used to detect that someone else changed them.
     *
     * @param array<int,array<int,int>> $grants
     */
    private function version(array $grants): string
    {
        $pairs = [];
        foreach ($grants as $roleId => $permissionIds) {
            foreach ($permissionIds as $permissionId) {
                $pairs[] = $roleId . ':' . $permissionId;
            }
        }
        sort($pairs, SORT_STRING);
        return hash('sha256', implode(',', $pairs));
    }
}
