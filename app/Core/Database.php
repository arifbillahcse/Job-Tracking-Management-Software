<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * PDO wrapper. Every query goes through prepared statements with native
 * (non-emulated) preparation, so values are never interpolated into SQL.
 *
 * MySQL/MariaDB is the production target. SQLite is supported for the
 * automated tests and local development only.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $driver = (string) Config::get('app.db.driver', 'mysql');

        if ($driver === 'sqlite') {
            $path = (string) Config::get('app.db.database');
            if ($path !== ':memory:' && !str_starts_with($path, '/')) {
                $path = BASE_PATH . '/' . $path;
            }
            $pdo = new PDO('sqlite:' . $path, null, null, self::options());
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::get('app.db.host'),
                Config::get('app.db.port'),
                Config::get('app.db.database')
            );
            $pdo = new PDO(
                $dsn,
                (string) Config::get('app.db.username'),
                (string) Config::get('app.db.password'),
                self::options()
            );
            // One plain statement each, so every one is valid on MySQL and on MariaDB (PDO's init-command
            // attribute can only carry a single statement).
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }

        return self::$pdo = $pdo;
    }

    /** Swap the connection (tests). */
    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function driver(): string
    {
        return self::connect()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string,mixed> $params
     */
    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return int affected rows
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /**
     * @param array<int|string,mixed> $params
     */
    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::connect()->lastInsertId();
    }

    /**
     * Run $callback inside a transaction; rolls back and rethrows on failure.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connect();
        if ($pdo->inTransaction()) {
            return $callback();
        }
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<int|string,mixed> $params
     */
    private static function run(string $sql, array $params): PDOStatement
    {
        $stmt = self::connect()->prepare($sql);
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * @return array<int,mixed>
     */
    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }
}
