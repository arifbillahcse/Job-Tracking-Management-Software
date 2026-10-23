<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SettingsRepository
{
    /**
     * @return array<string,string|null>
     */
    public function all(): array
    {
        $rows = Database::fetchAll('SELECT setting_key, setting_value FROM settings');
        return array_column($rows, 'setting_value', 'setting_key');
    }

    public function set(string $key, ?string $value): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $updated = Database::execute(
            'UPDATE settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?',
            [$value, $now, $key]
        );
        if ($updated === 0 && (int) Database::fetchValue('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$key]) === 0) {
            Database::execute(
                'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)',
                [$key, $value, $now]
            );
        }
    }
}
