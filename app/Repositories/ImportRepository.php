<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ImportRepository
{
    /** Columns update() may set. Column names are interpolated into SQL, so they must never come from outside this list. */
    private const UPDATABLE = [
        'status', 'total_rows', 'valid_rows', 'invalid_rows', 'imported_rows', 'skipped_rows',
        'headers', 'mapping', 'ignored', 'warnings', 'sample', 'stored_name', 'committed_at',
    ];

    private const JSON = ['headers', 'mapping', 'ignored', 'warnings', 'sample'];

    /**
     * @param array<string,mixed> $data
     */
    public function create(int $userId, string $originalName, string $storedName, string $format, array $data): int
    {
        return Database::insert(
            'INSERT INTO import_batches (user_id, original_name, stored_name, file_format, status, total_rows, valid_rows, invalid_rows,
                headers, mapping, ignored, warnings, sample, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId, $originalName, $storedName, $format, 'previewed',
                (int) $data['total'], (int) $data['valid'], (int) $data['invalid'],
                json_encode($data['headers']), json_encode($data['mapping']), json_encode($data['ignored']),
                json_encode($data['warnings']), json_encode($data['sample']),
                gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Batches are private to the user who uploaded them.
     *
     * @return array<string,mixed>|null
     */
    public function findForUser(int $id, int $userId): ?array
    {
        $row = Database::fetchOne('SELECT * FROM import_batches WHERE id = ? AND user_id = ?', [$id, $userId]);
        return $row === null ? null : $this->decode($row);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recentForUser(int $userId, int $limit): array
    {
        return array_map(
            fn (array $row): array => $this->decode($row),
            Database::fetchAll('SELECT * FROM import_batches WHERE user_id = ? ORDER BY id DESC LIMIT ?', [$userId, $limit])
        );
    }

    /** Move previewed -> $to atomically; false if the batch was not in the expected state (double submit, other tab). */
    public function transition(int $id, string $from, string $to): bool
    {
        return Database::execute('UPDATE import_batches SET status = ? WHERE id = ? AND status = ?', [$to, $id, $from]) === 1;
    }

    /**
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        $unknown = array_diff(array_keys($fields), self::UPDATABLE);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Cannot update import column(s): ' . implode(', ', $unknown));
        }
        $assignments = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($fields)));
        Database::execute("UPDATE import_batches SET {$assignments} WHERE id = ?", [...array_map(
            static fn (mixed $v): mixed => is_array($v) ? json_encode($v) : $v,
            array_values($fields)
        ), $id]);
    }

    /**
     * @param array<int,array{row:int,job_number:?string,messages:array<int,string>,raw:array<int,string>}> $errors
     */
    public function replaceErrors(int $batchId, array $errors): void
    {
        Database::execute('DELETE FROM import_errors WHERE batch_id = ?', [$batchId]);
        foreach ($errors as $error) {
            Database::execute(
                'INSERT INTO import_errors (batch_id, row_no, job_number, messages, raw_data) VALUES (?, ?, ?, ?, ?)',
                [$batchId, $error['row'], $error['job_number'] === null ? null : mb_substr($error['job_number'], 0, 60), json_encode($error['messages']), json_encode($error['raw'])]
            );
        }
    }

    /**
     * @return array<int,array{row_no:int,job_number:?string,messages:array<int,string>,raw:array<int,string>}>
     */
    public function errors(int $batchId, ?int $limit = null): array
    {
        $sql = 'SELECT row_no, job_number, messages, raw_data FROM import_errors WHERE batch_id = ? ORDER BY row_no ASC, id ASC';
        $params = [$batchId];
        if ($limit !== null) {
            $sql .= ' LIMIT ?';
            $params[] = $limit;
        }
        return array_map(static fn (array $row): array => [
            'row_no' => (int) $row['row_no'],
            'job_number' => $row['job_number'],
            'messages' => (array) json_decode((string) $row['messages'], true),
            'raw' => (array) json_decode((string) $row['raw_data'], true),
        ], Database::fetchAll($sql, $params));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode(array $row): array
    {
        foreach (self::JSON as $column) {
            $row[$column] = $row[$column] === null ? [] : (array) json_decode((string) $row[$column], true);
        }
        return $row;
    }
}
