<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Runs numbered .sql files from database/migrations in order and records each
 * in the `migrations` table. Re-running only applies files not yet applied.
 *
 * Migrations are written for MySQL/MariaDB. When the (test/dev only) SQLite
 * driver is active, SqliteCompat translates the few MySQL-specific tokens.
 */
final class Migrator
{
    public function __construct(private ?string $directory = null)
    {
        $this->directory ??= BASE_PATH . '/database/migrations';
    }

    /**
     * @return array<int,string> filenames applied by this run
     */
    public function migrate(): array
    {
        $this->ensureTable();
        $applied = array_column(Database::fetchAll('SELECT filename FROM migrations'), 'filename');
        $ran = [];

        foreach ($this->files() as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = (string) file_get_contents($file);
            if (Database::driver() === 'sqlite') {
                $sql = SqliteCompat::translate($sql);
            }

            try {
                foreach (self::statements($sql) as $statement) {
                    Database::connect()->exec($statement);
                }
            } catch (Throwable $e) {
                throw new RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
            }

            Database::execute(
                'INSERT INTO migrations (filename, applied_at) VALUES (?, ?)',
                [$name, gmdate('Y-m-d H:i:s')]
            );
            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * @return array<int,array{filename:string,applied:bool}>
     */
    public function status(): array
    {
        $this->ensureTable();
        $applied = array_column(Database::fetchAll('SELECT filename FROM migrations'), 'filename');
        return array_map(
            static fn (string $file): array => ['filename' => basename($file), 'applied' => in_array(basename($file), $applied, true)],
            $this->files()
        );
    }

    /**
     * @return array<int,string>
     */
    private function files(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    private function ensureTable(): void
    {
        Database::connect()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                filename VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            )'
        );
    }

    /**
     * Split a migration file into statements: drops "--" comment lines and splits on ";" at line end.
     *
     * @return array<int,string>
     */
    public static function statements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        $parts = preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
