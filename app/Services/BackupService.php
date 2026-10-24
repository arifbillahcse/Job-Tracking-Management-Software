<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use RuntimeException;

/**
 * Database backups into storage/backups (outside the web root, directory 0700, files 0600).
 *
 * MySQL/MariaDB: runs `mysqldump` without a shell (argument list, no interpolation) and passes the password in the
 * MYSQL_PWD environment variable so it never appears in the process list. The dump is gzip-compressed while it streams,
 * and a backup that does not end with mysqldump's "Dump completed" marker is rejected and deleted, so an interrupted
 * dump can never be mistaken for a good one.
 * SQLite (development only): VACUUM INTO.
 */
final class BackupService
{
    private string $directory;

    /** @var (callable(array<int,string>,array<string,string>,resource):int)|null */
    private $runner;

    /**
     * @param (callable(array<int,string>,array<string,string>,resource):int)|null $runner executes argv with env, writes stdout to the stream, returns the exit code (tests inject a fake)
     */
    public function __construct(?string $directory = null, ?callable $runner = null)
    {
        $this->directory = rtrim($directory ?? BASE_PATH . '/storage/backups', '/');
        $this->runner = $runner;
    }

    /**
     * @return array{path:string,bytes:int,driver:string}
     */
    public function create(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->ensureDirectory();
        $driver = (string) Config::get('app.db.driver', 'mysql');
        $stamp = $now->format('Ymd-His');

        if ($driver === 'sqlite') {
            $path = "{$this->directory}/job-tracker-{$stamp}.sqlite";
            Database::connect()->exec('VACUUM INTO ' . Database::connect()->quote($path));
            chmod($path, 0600);
            return ['path' => $path, 'bytes' => (int) filesize($path), 'driver' => 'sqlite'];
        }

        $path = "{$this->directory}/job-tracker-{$stamp}.sql.gz";
        $partial = $path . '.partial';
        [$argv, $env] = self::mysqlCommand((array) Config::get('app.db'));

        $gz = gzopen($partial, 'wb9');
        if ($gz === false) {
            throw new RuntimeException('Cannot write to ' . $this->directory);
        }
        chmod($partial, 0600);
        $stream = fopen('php://temp', 'w+b');
        $run = $this->runner ?? [self::class, 'runProcess'];
        $code = $run($argv, $env, $stream);
        if ($code !== 0) {
            // MySQL 8 needs --no-tablespaces for a user without the PROCESS privilege; some MariaDB versions do not know the flag.
            $retry = array_values(array_diff($argv, ['--no-tablespaces']));
            ftruncate($stream, 0);
            rewind($stream);
            $code = $run($retry, $env, $stream);
        }
        rewind($stream);
        $tail = '';
        while (!feof($stream)) {
            $chunk = (string) fread($stream, 1048576);
            gzwrite($gz, $chunk);
            $tail = substr($tail . $chunk, -200);
        }
        gzclose($gz);
        fclose($stream);

        if ($code !== 0) {
            @unlink($partial);
            throw new RuntimeException("mysqldump failed (exit code {$code}). Is it installed and are the DB_* settings right?");
        }
        if (!str_contains($tail, '-- Dump completed')) {
            @unlink($partial);
            throw new RuntimeException('The dump did not finish cleanly, so it was discarded.');
        }

        rename($partial, $path);
        return ['path' => $path, 'bytes' => (int) filesize($path), 'driver' => 'mysql'];
    }

    /**
     * Delete all but the newest $keep backups. Returns how many were removed.
     */
    public function prune(int $keep): int
    {
        $files = glob($this->directory . '/job-tracker-*.{sql.gz,sqlite}', GLOB_BRACE) ?: [];
        rsort($files); // names hold a sortable UTC timestamp
        $removed = 0;
        foreach (array_slice($files, max(1, $keep)) as $old) {
            if (@unlink($old)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * @param array<string,mixed> $db
     * @return array{0:array<int,string>,1:array<string,string>} argv and extra environment
     */
    public static function mysqlCommand(array $db): array
    {
        $argv = [
            'mysqldump', '--single-transaction', '--quick', '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--host=' . (string) ($db['host'] ?? '127.0.0.1'),
            '--port=' . (string) ($db['port'] ?? 3306),
            '--user=' . (string) ($db['username'] ?? ''),
            (string) ($db['database'] ?? ''),
        ];
        return [$argv, ['MYSQL_PWD' => (string) ($db['password'] ?? '')]];
    }

    /**
     * @param array<int,string> $argv
     * @param array<string,string> $env
     * @param resource $stream
     */
    public static function runProcess(array $argv, array $env, $stream): int
    {
        $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start mysqldump.');
        }
        stream_copy_to_stream($pipes[1], $stream);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 && $error !== '') {
            fwrite(STDERR, preg_replace('/(password|pwd)=\S+/i', '$1=***', $error) . PHP_EOL);
        }
        return $code;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create ' . $this->directory);
        }
        @chmod($this->directory, 0700);
    }
}
