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
}
