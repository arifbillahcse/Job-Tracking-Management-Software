<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\Config;
use App\Core\Database;

/**
 * Idempotent seeding of reference data from config files:
 *  - permissions: synced to app/Config/permissions.php (stale keys removed)
 *  - Admin role: always holds every permission
 *  - other default roles: created once with their default permissions, never overwritten afterwards
 *  - settings: missing keys inserted, existing values untouched
 */
final class Seeder
{
    /**
     * @return array<string,int> counts of what changed
     */
    public function run(): array
    {
        return Database::transaction(function (): array {
            $summary = ['permissions_added' => 0, 'permissions_removed' => 0, 'roles_created' => 0, 'settings_added' => 0];
            $now = gmdate('Y-m-d H:i:s');

            $permissions = (array) Config::get('permissions.permissions', []);
            foreach ($permissions as $key => [$group, $description]) {
                $exists = Database::fetchOne('SELECT id FROM permissions WHERE name = ?', [$key]);
                if ($exists === null) {
                    Database::execute(
                        'INSERT INTO permissions (name, group_name, description) VALUES (?, ?, ?)',
                        [$key, $group, $description]
                    );
                    $summary['permissions_added']++;
                } else {
                    Database::execute(
                        'UPDATE permissions SET group_name = ?, description = ? WHERE id = ?',
                        [$group, $description, $exists['id']]
                    );
                }
            }

            $existing = array_column(Database::fetchAll('SELECT id, name FROM permissions'), 'name', 'id');
            foreach ($existing as $id => $name) {
                if (!array_key_exists($name, $permissions)) {
                    Database::execute('DELETE FROM role_permissions WHERE permission_id = ?', [$id]);
                    Database::execute('DELETE FROM permissions WHERE id = ?', [$id]);
                    $summary['permissions_removed']++;
                }
            }

            $ids = array_column(Database::fetchAll('SELECT id, name FROM permissions'), 'id', 'name');

            foreach ((array) Config::get('permissions.roles', []) as $roleName => $definition) {
                $role = Database::fetchOne('SELECT id FROM roles WHERE name = ?', [$roleName]);
                $isNew = $role === null;

                if ($isNew) {
                    $roleId = Database::insert(
                        'INSERT INTO roles (name, description, is_system, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                        [$roleName, $definition['description'], $definition['system'] ? 1 : 0, $now, $now]
                    );
                    $summary['roles_created']++;
                } else {
                    $roleId = (int) $role['id'];
                }

                $grants = $definition['permissions'] === '*' ? array_keys($ids) : (array) $definition['permissions'];

                // Admin is re-synced every run; other roles only receive defaults when first created.
                if ($definition['permissions'] === '*' || $isNew) {
                    foreach ($grants as $permissionKey) {
                        if (!isset($ids[$permissionKey])) {
                            continue;
                        }
                        $has = Database::fetchOne(
                            'SELECT 1 AS present FROM role_permissions WHERE role_id = ? AND permission_id = ?',
                            [$roleId, $ids[$permissionKey]]
                        );
                        if ($has === null) {
                            Database::execute(
                                'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                                [$roleId, $ids[$permissionKey]]
                            );
                        }
                    }
                }
            }

            foreach ((array) Config::get('settings', []) as $key => $value) {
                $has = Database::fetchOne('SELECT 1 AS present FROM settings WHERE setting_key = ?', [$key]);
                if ($has === null) {
                    Database::execute(
                        'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)',
                        [$key, (string) $value, $now]
                    );
                    $summary['settings_added']++;
                }
            }

            return $summary;
        });
    }

    /**
     * The default permissions (permissions.php) that an existing default role (Manager, Staff) does not hold yet.
     * Roles are created once with their defaults and never overwritten afterwards, so permissions added by a later
     * version never reach them by themselves; this lists exactly what is missing.
     *
     * @return array<string,array<int,string>> role name => permission keys
     */
    public function missingDefaults(): array
    {
        $missing = [];
        foreach ((array) Config::get('permissions.roles', []) as $roleName => $definition) {
            if ($definition['permissions'] === '*') {
                continue; // Admin is re-synced by every seed run
            }
            $role = Database::fetchOne('SELECT id FROM roles WHERE name = ?', [$roleName]);
            if ($role === null) {
                continue;
            }
            $held = array_column(Database::fetchAll(
                'SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
                [$role['id']]
            ), 'name');
            $lacking = array_values(array_diff((array) $definition['permissions'], $held));
            // Only permissions that exist in the database can be granted.
            $lacking = array_values(array_filter($lacking, static fn (string $k): bool => Database::fetchOne('SELECT id FROM permissions WHERE name = ?', [$k]) !== null));
            if ($lacking !== []) {
                $missing[$roleName] = $lacking;
            }
        }
        return $missing;
    }

    /**
     * Grants the missing default permissions (see missingDefaults()). Never removes a permission, so an administrator's
     * deliberate un-ticking of a box is respected only for permissions that were already there; new ones are added.
     *
     * @return array<string,array<int,string>> what was granted, role name => permission keys
     */
    public function syncDefaultRoles(): array
    {
        $granted = $this->missingDefaults();
        Database::transaction(static function () use ($granted): void {
            foreach ($granted as $roleName => $keys) {
                $roleId = (int) Database::fetchValue('SELECT id FROM roles WHERE name = ?', [$roleName]);
                foreach ($keys as $key) {
                    Database::execute(
                        'INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = ?',
                        [$roleId, $key]
                    );
                }
            }
        });
        return $granted;
    }
}
