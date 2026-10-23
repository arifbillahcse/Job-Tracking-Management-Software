<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Repositories\JobRepository;

/**
 * Business rules for jobs: which fields a user may write, financial-field
 * visibility, change detection, optimistic locking and audit entries.
 */
final class JobService
{
    /** Columns any editor may write. Status, carrier and dispatch fields are NOT here: they change only through the Phase 3 workflow. */
    private const EDITABLE = [
        'job_number', 'customer_first_name', 'customer_last_name', 'pickup_date', 'first_available_date',
        'from_location', 'to_location', 'job_location', 'cf', 'notes',
    ];

    /** Columns only users with jobs.edit_financials may write. */
    private const FINANCIAL = ['balance', 'storage_fee'];

    private const DECIMAL = ['cf', 'balance', 'storage_fee'];

    public function __construct(private JobRepository $jobs = new JobRepository())
    {
    }

    /** Seeing the figures needs either financial permission (editing implies being able to see). */
    public function canSeeFinancials(): bool
    {
        return Auth::can('jobs.view_financials') || Auth::can('jobs.edit_financials');
    }

    public function canEditFinancials(): bool
    {
        return Auth::can('jobs.edit_financials');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $job = $this->jobs->find($id);
        return $job === null ? null : $this->forViewer($job);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findTrashed(int $id): ?array
    {
        $job = $this->jobs->findTrashed($id);
        return $job === null ? null : $this->forViewer($job);
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function page(int $limit, int $offset): array
    {
        return [
            'rows' => array_map(fn (array $job): array => $this->forViewer($job), $this->jobs->page($limit, $offset)),
            'total' => $this->jobs->count(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function trashed(): array
    {
        return array_map(fn (array $job): array => $this->forViewer($job), $this->jobs->trashed());
    }

    /**
     * Remove figures the current user is not allowed to see. Done in the service, not just
     * in the template, so a forgotten template check can never leak them.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public function forViewer(array $job): array
    {
        if (!$this->canSeeFinancials()) {
            unset($job['balance'], $job['storage_fee']);
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $input validated form data
     */
    public function create(array $input): int
    {
        $row = $this->writable($input);
        $row['status'] = 'booked';

        if ($this->canEditFinancials()) {
            $row['balance'] = $this->decimal($input['balance'] ?? null) ?? '0.00';
            $row['storage_fee'] = $this->decimal($input['storage_fee'] ?? null) ?? '0.00';
        }

        $id = $this->jobs->create($row, Auth::id());
        AuditService::record('job_created', 'job', $id, null, $row);
        return $id;
    }

    /**
     * @param array<string,mixed> $input validated form data
     * @param string $loadedAt the job's updated_at as it was when the edit form was opened
     * @return array{ok:bool,conflict:bool,changed:array<int,string>}
     */
    public function update(int $id, array $input, string $loadedAt): array
    {
        $current = $this->jobs->find($id);
        if ($current === null) {
            return ['ok' => false, 'conflict' => false, 'changed' => []];
        }

        // Optimistic locking: someone else saved this job after the form was opened.
        if ($loadedAt === '' || $loadedAt !== (string) $current['updated_at']) {
            return ['ok' => false, 'conflict' => true, 'changed' => []];
        }

        $row = $this->writable($input);
        if ($this->canEditFinancials()) {
            foreach (self::FINANCIAL as $column) {
                $row[$column] = $this->decimal($input[$column] ?? null) ?? '0.00';
            }
        }

        $old = [];
        $new = [];
        foreach ($row as $column => $value) {
            $before = $this->normalize($column, $current[$column] ?? null);
            if ($before !== $value) {
                $old[$column] = $before;
                $new[$column] = $value;
            }
        }

        if ($new === []) {
            return ['ok' => true, 'conflict' => false, 'changed' => []];
        }

        $this->jobs->update($id, $new, Auth::id());
        AuditService::record('job_updated', 'job', $id, $old, $new);

        return ['ok' => true, 'conflict' => false, 'changed' => array_keys($new)];
    }

    public function delete(int $id): bool
    {
        $job = $this->jobs->find($id);
        if ($job === null || !$this->jobs->softDelete($id, Auth::id())) {
            return false;
        }
        AuditService::record('job_deleted', 'job', $id, ['job_number' => $job['job_number'], 'status' => $job['status']]);
        return true;
    }

    public function restore(int $id): bool
    {
        $job = $this->jobs->findTrashed($id);
        if ($job === null || !$this->jobs->restore($id, Auth::id())) {
            return false;
        }
        AuditService::record('job_restored', 'job', $id, null, ['job_number' => $job['job_number']]);
        return true;
    }

    /**
     * Whitelist and normalise the editable columns; anything else in the input (status, carrier, ...) is dropped.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function writable(array $input): array
    {
        $row = [];
        foreach (self::EDITABLE as $column) {
            $row[$column] = $this->normalize($column, $input[$column] ?? null);
        }
        return $row;
    }

    private function normalize(string $column, mixed $value): ?string
    {
        if (in_array($column, self::DECIMAL, true)) {
            return $this->decimal($value);
        }
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return number_format((float) $value, 2, '.', '');
    }
}
