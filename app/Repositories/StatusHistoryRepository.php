<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class StatusHistoryRepository
{
    /**
     * @param array<string,mixed>|null $details
     */
    public function insert(
        int $jobId,
        string $from,
        string $to,
        ?string $note,
        ?array $details,
        ?int $userId,
        ?string $createdAt = null
    ): void {
        Database::execute(
            'INSERT INTO job_status_history (job_id, from_status, to_status, note, details, changed_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $jobId,
                $from,
                $to,
                $note,
                $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $userId,
                $createdAt ?? gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forJob(int $jobId): array
    {
        return Database::fetchAll(
            'SELECT h.*, u.name AS changed_by_name
             FROM job_status_history h LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.job_id = ? ORDER BY h.id DESC',
            [$jobId]
        );
    }

    /**
     * Latest status changes across all live jobs (dashboard activity feed).
     *
     * @return array<int,array<string,mixed>>
     */
    public function latest(int $limit): array
    {
        return Database::fetchAll(
            'SELECT h.id, h.job_id, h.from_status, h.to_status, h.note, h.created_at, u.name AS changed_by_name,
                    j.job_number, j.customer_first_name, j.customer_last_name
             FROM job_status_history h
             JOIN jobs j ON j.id = h.job_id AND j.deleted_at IS NULL
             LEFT JOIN users u ON u.id = h.changed_by
             ORDER BY h.id DESC LIMIT ?',
            [$limit]
        );
    }
}
