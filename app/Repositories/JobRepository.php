<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Core\Database;
use App\Services\JobBucket;
use App\Services\JobCriteria;

/**
 * SQL for jobs. Soft-deleted rows are excluded everywhere except trashed()/findTrashed().
 */
final class JobRepository
{
    private const DETAIL_SELECT = 'SELECT j.*, cu.name AS created_by_name, uu.name AS updated_by_name
        FROM jobs j
        LEFT JOIN users cu ON cu.id = j.created_by
        LEFT JOIN users uu ON uu.id = j.updated_by';

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return Database::fetchOne(self::DETAIL_SELECT . ' WHERE j.id = ? AND j.deleted_at IS NULL', [$id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findTrashed(int $id): ?array
    {
        return Database::fetchOne(self::DETAIL_SELECT . ' WHERE j.id = ? AND j.deleted_at IS NOT NULL', [$id]);
    }

    /**
     * @param array<string,mixed> $row column => value (already whitelisted by JobService)
     */
    public function create(array $row, ?int $userId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $row += ['created_by' => $userId, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now];

        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO jobs (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        return Database::insert($sql, array_values($row));
    }

    /**
     * @param array<string,mixed> $row column => value (already whitelisted by JobService)
     */
    public function update(int $id, array $row, ?int $userId): void
    {
        $row += ['updated_by' => $userId, 'updated_at' => gmdate('Y-m-d H:i:s')];

        $assignments = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($row)));
        Database::execute(
            "UPDATE jobs SET {$assignments} WHERE id = ? AND deleted_at IS NULL",
            [...array_values($row), $id]
        );
    }

    public function softDelete(int $id, ?int $userId): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        return Database::execute(
            'UPDATE jobs SET deleted_at = ?, updated_at = ?, updated_by = ? WHERE id = ? AND deleted_at IS NULL',
            [$now, $now, $userId, $id]
        ) === 1;
    }

    public function restore(int $id, ?int $userId): bool
    {
        return Database::execute(
            'UPDATE jobs SET deleted_at = NULL, updated_at = ?, updated_by = ? WHERE id = ? AND deleted_at IS NOT NULL',
            [gmdate('Y-m-d H:i:s'), $userId, $id]
        ) === 1;
    }

    /** Jobs matching the criteria (every job that is not deleted when no criteria are given). */
    public function count(?JobCriteria $criteria = null): int
    {
        [$where, $params] = $this->where($criteria ?? JobCriteria::forBucket(JobBucket::ALL));
        return (int) Database::fetchValue("SELECT COUNT(*) FROM jobs j WHERE {$where}", $params);
    }

    /**
     * One page of jobs: section + search + filters + whitelisted ordering.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetch(JobCriteria $criteria, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($criteria);
        return Database::fetchAll(
            "SELECT j.* FROM jobs j WHERE {$where} {$criteria->orderBySql()} LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );
    }

    /**
     * WHERE clause for a criteria object. The section condition comes from JobBucket (shared with the
     * dashboard); everything else is a bound parameter. LIKE input is escaped with "!" as the escape
     * character because it behaves identically on MySQL and SQLite.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function where(JobCriteria $c): array
    {
        [$sql, $params] = JobBucket::condition($c->bucket, 'j');

        $like = static fn (string $text): string => '%' . strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';

        if ($c->q !== '') {
            $fields = ['j.job_number', 'j.customer_first_name', 'j.customer_last_name', 'j.from_location', 'j.to_location',
                'j.job_location', 'j.carrier_name', 'j.driver_name', 'j.driver_phone'];
            // Every word must match somewhere, so "ada smith" finds first name Ada + last name Smith.
            foreach (array_slice(preg_split('/\s+/', $c->q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $word) {
                $sql .= ' AND (' . implode(' OR ', array_map(static fn (string $f): string => "{$f} LIKE ? ESCAPE '!'", $fields)) . ')';
                array_push($params, ...array_fill(0, count($fields), $like($word)));
            }
        }

        if ($c->status !== '') {
            $sql .= ' AND j.status = ?';
            $params[] = $c->status;
        }

        $column = $c->dateColumn();
        if ($c->dateFrom !== '') {
            $sql .= " AND {$column} >= ?";
            $params[] = $c->dateFrom . ($column === 'j.delivered_at' ? ' 00:00:00' : '');
        }
        if ($c->dateTo !== '') {
            if ($column === 'j.delivered_at') {
                $sql .= " AND {$column} < ?";
                $params[] = Clock::addDays($c->dateTo, 1) . ' 00:00:00';
            } else {
                $sql .= " AND {$column} <= ?";
                $params[] = $c->dateTo;
            }
        }

        foreach ([['j.from_location', $c->fromLocation], ['j.to_location', $c->toLocation]] as [$col, $value]) {
            if ($value !== '') {
                $sql .= " AND {$col} LIKE ? ESCAPE '!'";
                $params[] = $like($value);
            }
        }

        if ($c->carrier !== '') {
            $sql .= " AND (j.carrier_name LIKE ? ESCAPE '!' OR j.driver_name LIKE ? ESCAPE '!')";
            array_push($params, $like($c->carrier), $like($c->carrier));
        }

        if ($c->balance === 'due') {
            $sql .= ' AND j.balance > 0';
        } elseif ($c->balance === 'paid') {
            $sql .= ' AND j.balance <= 0';
        }

        return [$sql, $params];
    }

    /**
     * Status change guarded by the status the caller saw: returns false (and changes nothing) if
     * another user moved the job in the meantime.
     *
     * @param array<string,mixed> $changes column => value, must include 'status'
     */
    public function changeStatus(int $id, string $expectedFrom, array $changes, ?int $userId): bool
    {
        $changes += ['updated_by' => $userId, 'updated_at' => gmdate('Y-m-d H:i:s')];
        $assignments = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($changes)));

        return Database::execute(
            "UPDATE jobs SET {$assignments} WHERE id = ? AND status = ? AND deleted_at IS NULL",
            [...array_values($changes), $id, $expectedFrom]
        ) === 1;
    }

    /**
     * @return array<string,int> status => number of live jobs
     */
    public function statusCounts(): array
    {
        $rows = Database::fetchAll('SELECT status, COUNT(*) AS n FROM jobs WHERE deleted_at IS NULL GROUP BY status');
        return array_map('intval', array_column($rows, 'n', 'status'));
    }

    /** @return array{balance:string,storage_fee:string} */
    public function moneyTotals(): array
    {
        return [
            // Balance still owed on every job that is not cancelled (delivered jobs can still owe money).
            'balance' => (string) Database::fetchValue("SELECT COALESCE(SUM(balance), 0) FROM jobs WHERE deleted_at IS NULL AND status <> 'cancelled'"),
            // Storage fees on jobs that have not been delivered or cancelled.
            'storage_fee' => (string) Database::fetchValue("SELECT COALESCE(SUM(storage_fee), 0) FROM jobs WHERE deleted_at IS NULL AND status IN ('booked', 'in_transit', 'on_hold')"),
        ];
    }

    /**
     * Ready-for-delivery jobs split at today: overdue ones (past date) or the soonest upcoming ones.
     * Built on the same Ready condition as the list page and the dashboard count.
     *
     * @return array<int,array<string,mixed>>
     */
    public function readySlice(bool $overdue, int $limit): array
    {
        [$where, $params] = JobBucket::condition(JobBucket::READY, 'j');
        $where .= $overdue ? ' AND j.first_available_date < ?' : ' AND j.first_available_date >= ?';
        $params[] = Clock::today();

        return Database::fetchAll(
            "SELECT j.* FROM jobs j WHERE {$where} ORDER BY j.first_available_date ASC, j.id ASC LIMIT ?",
            [...$params, $limit]
        );
    }

    /** Ready-for-delivery jobs whose available date has already passed. */
    public function overdueCount(): int
    {
        [$where, $params] = JobBucket::condition(JobBucket::READY, 'j');
        return (int) Database::fetchValue(
            "SELECT COUNT(*) FROM jobs j WHERE {$where} AND j.first_available_date < ?",
            [...$params, Clock::today()]
        );
    }

    /**
     * Newly created jobs, newest first (dashboard activity feed).
     *
     * @return array<int,array<string,mixed>>
     */
    public function recentlyCreated(int $limit): array
    {
        return Database::fetchAll(
            'SELECT j.id, j.job_number, j.customer_first_name, j.customer_last_name, j.created_at, u.name AS created_by_name
             FROM jobs j LEFT JOIN users u ON u.id = j.created_by
             WHERE j.deleted_at IS NULL ORDER BY j.id DESC LIMIT ?',
            [$limit]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function trashed(): array
    {
        return Database::fetchAll(
            'SELECT j.*, u.name AS updated_by_name FROM jobs j LEFT JOIN users u ON u.id = j.updated_by
             WHERE j.deleted_at IS NOT NULL ORDER BY j.deleted_at DESC, j.id DESC'
        );
    }

    public function jobNumberExists(string $jobNumber): bool
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM jobs WHERE job_number = ?', [$jobNumber]) > 0;
    }
}
