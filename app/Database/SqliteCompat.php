<?php

declare(strict_types=1);

namespace App\Database;

/**
 * DEVELOPMENT / TEST ONLY. Production runs on MySQL/MariaDB. This rewrites the
 * handful of MySQL-specific tokens used in our migrations so the same files can
 * be applied to SQLite for automated tests where no MySQL server is available.
 */
final class SqliteCompat
{
    public static function translate(string $sql): string
    {
        // `id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` -> SQLite rowid alias
        $sql = preg_replace(
            '/\b(?:TINYINT|SMALLINT|MEDIUMINT|BIGINT|INT)(?:\(\d+\))?(?:\s+UNSIGNED)?\s+NOT\s+NULL\s+AUTO_INCREMENT\s+PRIMARY\s+KEY/i',
            'INTEGER PRIMARY KEY AUTOINCREMENT',
            $sql
        ) ?? $sql;

        // Trailing table options: ENGINE=InnoDB DEFAULT CHARSET=... COLLATE=...
        $sql = preg_replace('/\)\s*ENGINE\s*=[^;]*;/i', ');', $sql) ?? $sql;

        return $sql;
    }
}
