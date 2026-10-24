<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Housekeeping for data that only accumulates: sign-in attempts, abandoned import previews, old import records,
 * stray upload files and old log files. Dry run by default. The audit log is never touched unless a retention
 * period of at least a year is passed explicitly.
 */
final class MaintenanceService
{
    public const ATTEMPT_DAYS = 30;
    public const IMPORT_RECORD_DAYS = 90;
    public const LOG_DAYS = 60;
    public const MIN_AUDIT_DAYS = 365;

    private string $storage;

    public function __construct(?string $storage = null)
    {
        $this->storage = rtrim($storage ?? BASE_PATH . '/storage', '/');
    }

    /**
     * @return array<string,array{label:string,found:int,removed:int}>
     */
    public function run(bool $apply, ?int $auditDays = null, ?DateTimeImmutable $now = null): array
    {
        if ($auditDays !== null && $auditDays < self::MIN_AUDIT_DAYS) {
            throw new \InvalidArgumentException('Audit entries must be kept for at least ' . self::MIN_AUDIT_DAYS . ' days.');
        }
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = static fn (string $interval): string => $now->modify('-' . $interval)->format('Y-m-d H:i:s');

        $report = [];
        $report['login_attempts'] = $this->simple(
            'Sign-in attempts older than ' . self::ATTEMPT_DAYS . ' days',
            'login_attempts',
            'created_at < ?',
            [$cutoff(self::ATTEMPT_DAYS . ' days')],
            $apply
        );
        $report['import_previews'] = $this->expirePreviews($apply, $cutoff(ImportService::EXPIRY_HOURS . ' hours'), $cutoff('1 day'));
        $report['import_records'] = $this->importRecords($apply, $cutoff(self::IMPORT_RECORD_DAYS . ' days'));
        $report['import_files'] = $this->strayFiles($apply, $now->getTimestamp() - 3600);
        $report['logs'] = $this->oldLogs($apply, $now->modify('-' . self::LOG_DAYS . ' days')->format('Y-m-d'));
        if ($auditDays !== null) {
            $report['audit'] = $this->simple(
                'Audit entries older than ' . $auditDays . ' days',
                'audit_logs',
                'created_at < ?',
                [$cutoff($auditDays . ' days')],
                $apply
            );
        }
        return $report;
    }

    /**
     * @param array<int,mixed> $params
     * @return array{label:string,found:int,removed:int}
     */
    private function simple(string $label, string $table, string $where, array $params, bool $apply): array
    {
        $found = (int) Database::fetchValue("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
        $removed = $apply && $found > 0 ? Database::execute("DELETE FROM {$table} WHERE {$where}", $params) : 0;
        return ['label' => $label, 'found' => $found, 'removed' => $removed];
    }

    /**
     * Previews nobody confirmed (or a confirm that died half-way): mark expired and delete the stored file.
     *
     * @return array{label:string,found:int,removed:int}
     */
    private function expirePreviews(bool $apply, string $previewCutoff, string $stuckCutoff): array
    {
        $rows = Database::fetchAll(
            "SELECT id, stored_name FROM import_batches WHERE (status = 'previewed' AND created_at < ?) OR (status = 'importing' AND created_at < ?)",
            [$previewCutoff, $stuckCutoff]
        );
        $removed = 0;
        if ($apply) {
            foreach ($rows as $row) {
                if (!empty($row['stored_name'])) {
                    @unlink($this->storage . '/imports/' . basename((string) $row['stored_name']));
                }
                Database::execute("UPDATE import_batches SET status = 'expired', stored_name = NULL WHERE id = ?", [$row['id']]);
                $removed++;
            }
        }
        return ['label' => 'Abandoned import previews', 'found' => count($rows), 'removed' => $removed];
    }

    /**
     * @return array{label:string,found:int,removed:int}
     */
    private function importRecords(bool $apply, string $cutoff): array
    {
        $where = "status IN ('committed', 'cancelled', 'expired') AND created_at < ?";
        $ids = array_column(Database::fetchAll("SELECT id FROM import_batches WHERE {$where}", [$cutoff]), 'id');
        $removed = 0;
        if ($apply && $ids !== []) {
            Database::transaction(static function () use ($ids, &$removed): void {
                foreach ($ids as $id) {
                    Database::execute('DELETE FROM import_errors WHERE batch_id = ?', [$id]);
                    $removed += Database::execute('DELETE FROM import_batches WHERE id = ?', [$id]);
                }
            });
        }
        return ['label' => 'Finished import records older than ' . self::IMPORT_RECORD_DAYS . ' days', 'found' => count($ids), 'removed' => $removed];
    }

    /**
     * Files in storage/imports that no batch refers to (and are not brand new, so an upload in progress is safe).
     *
     * @return array{label:string,found:int,removed:int}
     */
    private function strayFiles(bool $apply, int $olderThan): array
    {
        $directory = $this->storage . '/imports';
        $known = array_flip(array_filter(array_column(
            Database::fetchAll('SELECT stored_name FROM import_batches WHERE stored_name IS NOT NULL'),
            'stored_name'
        )));
        $found = 0;
        $removed = 0;
        foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $name) {
            $path = $directory . '/' . $name;
            if ($name[0] === '.' || !is_file($path) || isset($known[$name]) || (int) filemtime($path) >= $olderThan) {
                continue;
            }
            $found++;
            if ($apply && @unlink($path)) {
                $removed++;
            }
        }
        return ['label' => 'Stray files in storage/imports', 'found' => $found, 'removed' => $removed];
    }

    /**
     * @return array{label:string,found:int,removed:int}
     */
    private function oldLogs(bool $apply, string $beforeDay): array
    {
        $found = 0;
        $removed = 0;
        foreach (glob($this->storage . '/logs/app-*.log') ?: [] as $path) {
            if (preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', $path, $m) !== 1 || $m[1] >= $beforeDay) {
                continue;
            }
            $found++;
            if ($apply && @unlink($path)) {
                $removed++;
            }
        }
        return ['label' => 'Log files older than ' . self::LOG_DAYS . ' days', 'found' => $found, 'removed' => $removed];
    }
}
