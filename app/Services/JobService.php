<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Repositories\JobRepository;
use App\Validation\JobRules;

/**
 * Business rules for jobs: which fields a user may write, financial-field
 * visibility, change detection, optimistic locking and audit entries.
 */
final class JobService
{
    /** Columns any editor may write. Status, carrier and dispatch fields are NOT here: they change only through the Phase 3 workflow. */
    private const EDITABLE = [
        'job_number', 'customer_first_name', 'customer_last_name', 'pickup_date', 'first_available_date',
        'from_location', 'to_location', 'job_location', 'cf', 'estimated_cf', 'notes', 'customer_phone',
        'job_lot', 'color',
    ];

    /** Columns only users with jobs.edit_financials may write. */
    private const FINANCIAL = ['balance', 'storage_fee_override', 'estimated_total', 'deposit', 'grand_total', 'bh_total', 'paid'];

    /** Writable only while the job is In Transit (to correct a typo); otherwise they change through the workflow. */
    private const CARRIER = ['carrier_name', 'driver_name', 'driver_phone'];

    private const DECIMAL = ['cf', 'estimated_cf', 'balance', 'storage_fee', 'storage_fee_override', 'estimated_total', 'deposit', 'grand_total', 'bh_total', 'paid'];

    public function __construct(private JobRepository $jobs = new JobRepository())
    {
    }

    /** Seeing the figures needs either financial permission (editing implies being able to see). */
    public function canSeeFinancials(): bool
    {
        return Auth::can('jobs.view_financials') || Auth::can('jobs.edit_financials');
    }

    /** Assigning a foreman or a trip changes trips, so it needs the permission to manage them. */
    public function canAssign(): bool
    {
        return Auth::can('trips.manage');
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

    /** Number of jobs matching the criteria. */
    public function count(JobCriteria $criteria): int
    {
        return $this->jobs->count($criteria);
    }

    /**
     * One page of jobs for a list screen, with financial fields stripped for users who may not see them.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(JobCriteria $criteria, int $limit, int $offset): array
    {
        return array_map(fn (array $job): array => $this->forViewer($job), $this->jobs->fetch($criteria, $limit, $offset));
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
            unset($job['balance'], $job['storage_fee'], $job['storage_fee_override'], $job['storage_rate']);
            foreach (JobRules::MONEY_FIELDS as $field) {
                unset($job[$field]);
            }
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
            $row += $this->financialRow($input); // an empty storage fee override means automatic
        }
        if ($this->canAssign()) {
            $row += $this->assignmentRow($input);
        }

        $id = $this->jobs->create($row, Auth::id());
        AuditService::record('job_created', 'job', $id, null, $row);
        StorageFeeService::settle($id);
        if (($row['trip_id'] ?? null) !== null) {
            (new TripService())->ensureRow($id, (int) $row['trip_id']);
        }
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
            $row += $this->financialRow($input);
        }
        if ($this->canAssign()) {
            $row += $this->assignmentRow($input);
        }
        if ((string) $current['status'] === 'in_transit') {
            foreach (self::CARRIER as $column) {
                $row[$column] = $this->normalize($column, $input[$column] ?? null);
            }
        }
        // Dates that exist once a job has moved on can be corrected (the workflow sets them to today on the click).
        if (in_array((string) $current['status'], ['in_transit', 'delivered'], true)) {
            $row['loaded_date'] = $this->normalize('loaded_date', $input['loaded_date'] ?? null);
        }
        if ((string) $current['status'] === 'delivered') {
            $row['delivery_date'] = $this->normalize('delivery_date', $input['delivery_date'] ?? ($current['delivery_date'] ?? null));
            if ($row['delivery_date'] !== null) {
                $row['delivered_at'] = $row['delivery_date'] . ' 00:00:00';
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
        StorageFeeService::settle($id); // CF, dates or an override may have changed the fee
        if (isset($new['trip_id']) && $new['trip_id'] !== null) {
            (new TripService())->ensureRow($id, (int) $new['trip_id']);
        }

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

    /**
     * The money columns of the form. Balance is never empty (0.00); the others stay empty when nothing is typed.
     *
     * @param array<string,mixed> $input
     * @return array<string,?string>
     */
    private function financialRow(array $input): array
    {
        $row = [];
        foreach (self::FINANCIAL as $column) {
            $row[$column] = $this->decimal($input[$column] ?? null);
        }
        $row['balance'] ??= '0.00';
        return $row;
    }

    /**
     * The job's foreman and trip (already resolved and checked by TripService::resolveAssignment()).
     *
     * @param array<string,mixed> $input
     * @return array{foreman_id:?string,trip_id:?string}
     */
    private function assignmentRow(array $input): array
    {
        $id = static fn (mixed $v): ?string => ($v === null || $v === '' || !ctype_digit((string) $v) || (int) $v < 1) ? null : (string) (int) $v;
        return ['foreman_id' => $id($input['foreman_id'] ?? null), 'trip_id' => $id($input['trip_id'] ?? null)];
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
