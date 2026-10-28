<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Services\TripCriteria;

/**
 * SQL for trips and their job rows. Soft-deleted trips are excluded everywhere.
 */
final class TripRepository
{
    /** The expense columns of a job row, added up in SQL (assumption T1: "Total expenses" is just this sum). */
    public const EXPENSE_SUM = 'COALESCE(tj.diesel, 0) + COALESCE(tj.hotels, 0) + COALESCE(tj.helper, 0) + COALESCE(tj.misc, 0) + COALESCE(tj.commission, 0) + COALESCE(tj.extra, 0)';

    public const MONEY = ['diesel', 'hotels', 'helper', 'misc', 'commission', 'extra'];

    private const SELECT = 'SELECT t.*, f.name AS foreman_name,
            (SELECT COUNT(*) FROM trip_jobs tj WHERE tj.trip_id = t.id) AS job_count,
            (SELECT COALESCE(SUM(' . self::EXPENSE_SUM . '), 0) FROM trip_jobs tj WHERE tj.trip_id = t.id) AS total_expenses
        FROM trips t JOIN foremen f ON f.id = t.foreman_id';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return Database::fetchOne(self::SELECT . ' WHERE t.id = ? AND t.deleted_at IS NULL', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(int $tripId): array
    {
        $rows = Database::fetchAll(
            'SELECT tj.*, CASE WHEN j.deleted_at IS NULL THEN 0 ELSE 1 END AS job_deleted
             FROM trip_jobs tj LEFT JOIN jobs j ON j.id = tj.job_id WHERE tj.trip_id = ? ORDER BY tj.sort_order ASC, tj.id ASC',
            [$tripId]
        );
        return $rows;
    }

    /** @return array<int,array<string,mixed>> the live trips a job appears on */
    public function forJob(int $jobId): array
    {
        return Database::fetchAll(
            'SELECT t.id, t.trip_number, t.begins_on, f.name AS foreman_name FROM trip_jobs tj
             JOIN trips t ON t.id = tj.trip_id JOIN foremen f ON f.id = t.foreman_id
             WHERE tj.job_id = ? AND t.deleted_at IS NULL ORDER BY t.begins_on DESC, t.id DESC',
            [$jobId]
        );
    }

    /** @return array<int,array<string,mixed>> the newest live trips that do not carry this job yet (for "add to a trip") */
    public function choicesForJob(int $jobId, int $limit = 50): array
    {
        return Database::fetchAll(
            'SELECT t.id, t.trip_number, t.begins_on, f.name AS foreman_name FROM trips t JOIN foremen f ON f.id = t.foreman_id
             WHERE t.deleted_at IS NULL AND NOT EXISTS (SELECT 1 FROM trip_jobs x WHERE x.trip_id = t.id AND x.job_id = ?)
             ORDER BY t.begins_on DESC, t.id DESC LIMIT ?',
            [$jobId, $limit]
        );
    }

    /**
     * Adds one job row at the end of a trip and marks the trip as changed (so a form opened earlier warns about the conflict).
     *
     * @param array<string,mixed> $row
     */
    public function appendRow(int $tripId, array $row, ?int $userId): void
    {
        Database::transaction(function () use ($tripId, $row, $userId): void {
            $next = (int) Database::fetchValue('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM trip_jobs WHERE trip_id = ?', [$tripId]);
            Database::insert(
                'INSERT INTO trip_jobs (trip_id, job_id, sort_order, job_number, customer_name, job_date, from_location, bh, diesel, hotels, helper, misc, commission, extra, remark)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL)',
                [$tripId, $row['job_id'], $next, $row['job_number'], $row['customer_name'], $row['job_date'], $row['from_location']]
            );
            Database::execute('UPDATE trips SET updated_by = ?, updated_at = ? WHERE id = ?', [$userId, gmdate('Y-m-d H:i:s'), $tripId]);
        });
    }

    /**
     * Trips that began in the last $days days: how many, job lines on them and the sum of the expense columns.
     *
     * @return array{trips:int,jobs:int,expenses:string}
     */
    public function recentFigures(string $since): array
    {
        $row = Database::fetchOne(
            'SELECT COUNT(DISTINCT t.id) AS trips, COUNT(tj.id) AS jobs, COALESCE(SUM(' . self::EXPENSE_SUM . '), 0) AS expenses
             FROM trips t LEFT JOIN trip_jobs tj ON tj.trip_id = t.id WHERE t.deleted_at IS NULL AND t.begins_on >= ?',
            [$since]
        ) ?? [];
        return ['trips' => (int) ($row['trips'] ?? 0), 'jobs' => (int) ($row['jobs'] ?? 0), 'expenses' => number_format((float) ($row['expenses'] ?? 0), 2, '.', '')];
    }

    public function count(TripCriteria $c): int
    {
        [$where, $params] = $this->where($c);
        return (int) Database::fetchValue("SELECT COUNT(*) FROM trips t JOIN foremen f ON f.id = t.foreman_id WHERE {$where}", $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function search(TripCriteria $c, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($c);
        return Database::fetchAll(
            self::SELECT . " WHERE {$where} ORDER BY t.begins_on DESC, t.id DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );
    }

    /** Number of job rows (not trips) in the trips matching the criteria: the size of an export. */
    public function countJobRows(TripCriteria $c): int
    {
        [$where, $params] = $this->where($c);
        return (int) Database::fetchValue(
            "SELECT COUNT(*) FROM trip_jobs tj JOIN trips t ON t.id = tj.trip_id JOIN foremen f ON f.id = t.foreman_id WHERE {$where}",
            $params
        );
    }

    /**
     * One line per job row, trip details repeated on every line (the shape of the client's sheet), in the list's order.
     *
     * @return array<int,array<string,mixed>>
     */
    public function jobRows(TripCriteria $c, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($c);
        return Database::fetchAll(
            "SELECT t.trip_number, f.name AS foreman_name, t.begins_on, t.ends_on, t.collected_by, tj.*
             FROM trip_jobs tj JOIN trips t ON t.id = tj.trip_id JOIN foremen f ON f.id = t.foreman_id
             WHERE {$where} ORDER BY t.begins_on DESC, t.id DESC, tj.sort_order ASC, tj.id ASC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );
    }

    /**
     * Per foreman: live trips, job rows and the sum of each expense column, for trips beginning in the date range
     * (and only the chosen foreman, if any). Foremen with no matching trip are not listed.
     *
     * @return array<int,array<string,mixed>>
     */
    public function foremanReport(TripCriteria $c): array
    {
        $where = 't.deleted_at IS NULL';
        $params = [];
        if ($c->foremanId > 0) {
            $where .= ' AND t.foreman_id = ?';
            $params[] = $c->foremanId;
        }
        if ($c->from !== '') {
            $where .= ' AND t.begins_on >= ?';
            $params[] = $c->from;
        }
        if ($c->to !== '') {
            $where .= ' AND t.begins_on <= ?';
            $params[] = $c->to;
        }
        $sums = implode(', ', array_map(static fn (string $m): string => "COALESCE(SUM(tj.{$m}), 0) AS {$m}", self::MONEY));

        return Database::fetchAll(
            "SELECT f.id, f.name, f.active, COUNT(DISTINCT t.id) AS trips, COUNT(tj.id) AS jobs, {$sums}
             FROM trips t JOIN foremen f ON f.id = t.foreman_id LEFT JOIN trip_jobs tj ON tj.trip_id = t.id
             WHERE {$where} GROUP BY f.id, f.name, f.active ORDER BY f.name ASC",
            $params
        );
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function where(TripCriteria $c): array
    {
        $sql = 't.deleted_at IS NULL';
        $params = [];
        $like = static fn (string $text): string => '%' . strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';

        foreach (array_slice(preg_split('/\s+/', $c->q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $word) {
            $sql .= " AND (t.trip_number LIKE ? ESCAPE '!' OR f.name LIKE ? ESCAPE '!' OR t.collected_by LIKE ? ESCAPE '!'
                OR EXISTS (SELECT 1 FROM trip_jobs x WHERE x.trip_id = t.id AND (x.job_number LIKE ? ESCAPE '!' OR x.customer_name LIKE ? ESCAPE '!')))";
            array_push($params, ...array_fill(0, 5, $like($word)));
        }
        if ($c->foremanId > 0) {
            $sql .= ' AND t.foreman_id = ?';
            $params[] = $c->foremanId;
        }
        if ($c->from !== '') {
            $sql .= ' AND t.begins_on >= ?';
            $params[] = $c->from;
        }
        if ($c->to !== '') {
            $sql .= ' AND t.begins_on <= ?';
            $params[] = $c->to;
        }
        return [$sql, $params];
    }

    /**
     * @param array<string,mixed> $header
     * @param array<int,array<string,mixed>> $rows
     */
    public function create(array $header, array $rows, ?int $userId): int
    {
        return (int) Database::transaction(function () use ($header, $rows, $userId): int {
            $now = gmdate('Y-m-d H:i:s');
            $id = Database::insert(
                'INSERT INTO trips (trip_number, foreman_id, begins_on, ends_on, collected_by, notes, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$header['trip_number'], $header['foreman_id'], $header['begins_on'], $header['ends_on'], $header['collected_by'], $header['notes'], $userId, $userId, $now, $now]
            );
            $this->insertRows($id, $rows);
            return $id;
        });
    }

    /**
     * Replaces the header and every job row in one transaction.
     *
     * @param array<string,mixed> $header
     * @param array<int,array<string,mixed>> $rows
     */
    public function update(int $id, array $header, array $rows, ?int $userId): void
    {
        Database::transaction(function () use ($id, $header, $rows, $userId): void {
            Database::execute(
                'UPDATE trips SET trip_number = ?, foreman_id = ?, begins_on = ?, ends_on = ?, collected_by = ?, notes = ?, updated_by = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL',
                [$header['trip_number'], $header['foreman_id'], $header['begins_on'], $header['ends_on'], $header['collected_by'], $header['notes'], $userId, gmdate('Y-m-d H:i:s'), $id]
            );
            Database::execute('DELETE FROM trip_jobs WHERE trip_id = ?', [$id]);
            $this->insertRows($id, $rows);
        });
    }

    public function softDelete(int $id, ?int $userId): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        return Database::execute('UPDATE trips SET deleted_at = ?, updated_at = ?, updated_by = ? WHERE id = ? AND deleted_at IS NULL', [$now, $now, $userId, $id]) === 1;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function insertRows(int $tripId, array $rows): void
    {
        $order = 0;
        foreach ($rows as $row) {
            Database::insert(
                'INSERT INTO trip_jobs (trip_id, job_id, sort_order, job_number, customer_name, job_date, from_location, bh, diesel, hotels, helper, misc, commission, extra, remark)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$tripId, $row['job_id'], $order++, $row['job_number'], $row['customer_name'], $row['job_date'], $row['from_location'], $row['bh'],
                    $row['diesel'], $row['hotels'], $row['helper'], $row['misc'], $row['commission'], $row['extra'], $row['remark']]
            );
        }
    }

    /** @return array<string,mixed>|null the live job with this number (any letter case), or null */
    public function findJobByNumber(string $number): ?array
    {
        $column = Database::driver() === 'sqlite' ? 'job_number COLLATE NOCASE' : 'job_number';
        return Database::fetchOne(
            "SELECT id, job_number, customer_first_name, customer_last_name, pickup_date, from_location FROM jobs WHERE {$column} = ? AND deleted_at IS NULL",
            [$number]
        );
    }

    public function numberExists(string $tripNumber, ?int $exceptId = null): bool
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM trips WHERE trip_number = ? AND id <> ?', [$tripNumber, $exceptId ?? 0]) > 0;
    }
}
