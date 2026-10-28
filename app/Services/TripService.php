<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Validator;
use App\Repositories\TripRepository;
use App\Validation\TripRules;

/**
 * Business rules for foreman trips: validation of the header and the job rows, who may see or change the
 * money, optimistic locking and audit entries.
 *
 * Job rows are matched to real jobs by job number. The job number, customer name and "from" are copied onto the row
 * so the trip still reads correctly if the job is edited later. A user without trips.financials never sees the
 * expense columns, and saving a trip never wipes expenses they could not see.
 */
final class TripService
{
    private const TEXT = ['job_number', 'job_date', 'bh', 'remark'];

    /** Job rows one trip may hold. Each row posts up to 12 fields and PHP drops everything past max_input_vars (default 1000), so more would be cut off silently. */
    public const MAX_ROWS = 60;
    /** Rows (blank spares included) read from one request at all, so a hostile body cannot make the server validate thousands of rows. */
    private const MAX_POSTED = 120;

    public function __construct(private TripRepository $trips = new TripRepository())
    {
    }

    public function canSeeFinancials(): bool
    {
        return Auth::can('trips.financials');
    }

    /** @return array<string,mixed>|null the trip with its rows (expenses removed when the user may not see them) */
    public function find(int $id): ?array
    {
        $trip = $this->trips->find($id);
        if ($trip === null) {
            return null;
        }
        $rows = $this->trips->rows($id);
        $trip = $this->forViewer($trip);
        $trip['rows'] = array_map(fn (array $r): array => $this->rowForViewer($r), $rows);
        $trip['totals'] = $this->canSeeFinancials() ? TripTotals::forRows($rows) : null;
        return $trip;
    }

    /**
     * The foreman-wise report: one line per foreman plus the grand total. Expense columns are left out
     * (counts only) for a user without trips.financials.
     *
     * @return array{rows:array<int,array<string,mixed>>,totals:array<string,string|int>,money:bool}
     */
    public function foremanReport(TripCriteria $criteria): array
    {
        $money = $this->canSeeFinancials();
        $rows = [];
        $cents = array_fill_keys([...TripRepository::MONEY, 'total'], 0);
        $trips = 0;
        $jobs = 0;
        foreach ($this->trips->foremanReport($criteria) as $r) {
            $line = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'active' => (int) $r['active'], 'trips' => (int) $r['trips'], 'jobs' => (int) $r['jobs']];
            $trips += $line['trips'];
            $jobs += $line['jobs'];
            if ($money) {
                $lineCents = 0;
                foreach (TripRepository::MONEY as $m) {
                    $c = (int) round((float) $r[$m] * 100);
                    $line[$m] = number_format($c / 100, 2, '.', '');
                    $cents[$m] += $c;
                    $lineCents += $c;
                }
                $line['total'] = number_format($lineCents / 100, 2, '.', '');
                $cents['total'] += $lineCents;
            }
            $rows[] = $line;
        }
        $totals = ['trips' => $trips, 'jobs' => $jobs];
        if ($money) {
            foreach ($cents as $k => $c) {
                $totals[$k] = number_format($c / 100, 2, '.', '');
            }
        }
        return ['rows' => $rows, 'totals' => $totals, 'money' => $money];
    }

    /** @return array<int,array<string,mixed>> the live trips a job is on (for the Job Details page) */
    public function forJob(int $jobId): array
    {
        return $this->trips->forJob($jobId);
    }

    /** @return array<int,array<string,mixed>> trips the job could still be added to */
    public function choicesForJob(int $jobId): array
    {
        return $this->trips->choicesForJob($jobId);
    }

    /**
     * Adds a job to the end of an existing trip (no expenses yet). The row copies the job's number, customer and From, and its
     * Date is the job's pickup date.
     *
     * @return string 'ok', 'no_trip', 'no_job' or 'already'
     */
    public function addJob(int $tripId, int $jobId): string
    {
        $trip = $this->trips->find($tripId);
        if ($trip === null) {
            return 'no_trip';
        }
        $job = \App\Core\Database::fetchOne('SELECT id, job_number, customer_first_name, customer_last_name, pickup_date, from_location FROM jobs WHERE id = ? AND deleted_at IS NULL', [$jobId]);
        if ($job === null) {
            return 'no_job';
        }
        foreach ($this->trips->rows($tripId) as $row) {
            if ((int) $row['job_id'] === $jobId) {
                return 'already';
            }
        }
        $this->trips->appendRow($tripId, [
            'job_id' => $jobId, 'job_number' => (string) $job['job_number'],
            'customer_name' => trim($job['customer_first_name'] . ' ' . $job['customer_last_name']),
            'job_date' => $job['pickup_date'], 'from_location' => $job['from_location'],
        ], Auth::id());
        AuditService::record('trip_updated', 'trip', $tripId, ['jobs' => (int) $trip['job_count']], ['jobs_added' => [(string) $job['job_number']]]);
        return 'ok';
    }

    /**
     * Dashboard figures for trips that began in the last 30 days; the expense total only with trips.financials.
     *
     * @return array{trips:int,jobs:int,expenses:?string}
     */
    public function dashboardFigures(): array
    {
        $f = $this->trips->recentFigures(\App\Core\Clock::addDays(\App\Core\Clock::today(), -30));
        return ['trips' => $f['trips'], 'jobs' => $f['jobs'], 'expenses' => $this->canSeeFinancials() ? $f['expenses'] : null];
    }

    /** @return array<int,array<string,mixed>> the stored rows, expenses included (for prepare(): hidden expenses are kept) */
    public function existingRows(int $id): array
    {
        return $this->trips->rows($id);
    }

    /** @return array<int,array<string,mixed>> */
    public function search(TripCriteria $criteria, int $limit, int $offset): array
    {
        return array_map(fn (array $t): array => $this->forViewer($t), $this->trips->search($criteria, $limit, $offset));
    }

    public function count(TripCriteria $criteria): int
    {
        return $this->trips->count($criteria);
    }

    /**
     * Validate a posted trip form. Returns the clean header and rows, or the errors (keyed by field; row fields are "rows.N.field").
     *
     * @param array<string,mixed> $post
     * @param array<int,array<string,mixed>> $existingRows the trip's current rows (edit only), so hidden expenses are kept
     * @return array{header:?array<string,mixed>,rows:array<int,array<string,mixed>>,errors:array<string,array<int,string>>}
     */
    public function prepare(array $post, ?int $ignoreId = null, array $existingRows = []): array
    {
        $validator = Validator::make($post, TripRules::header($ignoreId), TripRules::labels());
        $errors = $validator->passes() ? [] : $validator->errors();
        $header = $validator->passes() ? $validator->validated() : null;
        if ($header !== null && !empty($header['begins_on']) && !empty($header['ends_on']) && (string) $header['ends_on'] < (string) $header['begins_on']) {
            $errors['ends_on'][] = 'Trip ends cannot be before trip begins.';
        }

        $withMoney = $this->canSeeFinancials();
        $existing = [];
        foreach ($existingRows as $r) {
            $existing[(int) $r['id']] = $r;
        }

        $rows = [];
        $seen = [];
        $posted = is_array($post['rows'] ?? null) ? $post['rows'] : [];
        if (count($posted) > self::MAX_POSTED) {
            $errors['rows'][] = 'Too many rows were sent. A trip holds at most ' . self::MAX_ROWS . ' jobs; split a bigger trip into two.';
            $posted = [];
        }
        $number = 0;
        foreach ($posted as $index => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $clean = [];
            foreach ([...self::TEXT, ...TripRepository::MONEY] as $field) {
                $value = $raw[$field] ?? '';
                $clean[$field] = is_scalar($value) ? trim((string) $value) : '';
            }
            if (implode('', $clean) === '') {
                continue; // a blank spare row
            }
            $number++;
            if ($number > self::MAX_ROWS) {
                $errors['rows'] = ['A trip holds at most ' . self::MAX_ROWS . ' jobs (PHP would cut off more); split a bigger trip into two.'];
                break;
            }
            $key = 'rows.' . (string) $index . '.';
            $labels = array_map(static fn (string $l): string => "Row {$number} {$l}", TripRules::labels());

            $rowValidator = Validator::make($clean, TripRules::row($withMoney), $labels);
            if (!$rowValidator->passes()) {
                foreach ($rowValidator->errors() as $field => $messages) {
                    $errors[$key . $field] = $messages;
                }
                continue;
            }
            $valid = $rowValidator->validated();

            $job = $this->trips->findJobByNumber((string) $valid['job_number']);
            if ($job === null) {
                $errors[$key . 'job_number'][] = "Row {$number}: job \"{$valid['job_number']}\" was not found (check the job number; deleted jobs cannot be added).";
                continue;
            }
            if (isset($seen[(int) $job['id']])) {
                $errors[$key . 'job_number'][] = "Row {$number}: job {$job['job_number']} is already on this trip.";
                continue;
            }
            $seen[(int) $job['id']] = true;

            $keep = $existing[(int) ($raw['id'] ?? 0)] ?? null;
            $row = [
                'job_id' => (int) $job['id'],
                'job_number' => (string) $job['job_number'],
                'customer_name' => trim($job['customer_first_name'] . ' ' . $job['customer_last_name']),
                'job_date' => ($valid['job_date'] ?? null) ?: ($job['pickup_date'] ?? null),
                'from_location' => $job['from_location'],
                'bh' => $valid['bh'] ?? null,
                'remark' => $valid['remark'] ?? null,
            ];
            foreach (TripRepository::MONEY as $field) {
                $row[$field] = $withMoney
                    ? $this->decimal($valid[$field] ?? null)
                    : ($keep !== null && ($keep[$field] ?? null) !== null ? $this->decimal($keep[$field]) : null);
            }
            $rows[] = $row;
        }

        if ($rows === [] && !array_filter($errors, static fn (string $k): bool => str_starts_with($k, 'rows.'), ARRAY_FILTER_USE_KEY)) {
            $errors['rows'][] = 'Add at least one job to the trip.';
        }

        return ['header' => $errors === [] ? $this->normalizeHeader((array) $header) : null, 'rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param array<string,mixed> $header clean header
     * @param array<int,array<string,mixed>> $rows clean rows from prepare()
     */
    public function create(array $header, array $rows): int
    {
        $id = $this->trips->create($header, $rows, Auth::id());
        AuditService::record('trip_created', 'trip', $id, null, ['trip_number' => $header['trip_number'], 'foreman_id' => $header['foreman_id'], 'jobs' => array_column($rows, 'job_number')]);
        return $id;
    }

    /**
     * @param array<string,mixed> $header
     * @param array<int,array<string,mixed>> $rows
     * @return array{ok:bool,conflict:bool,changed:bool}
     */
    public function update(int $id, array $header, array $rows, string $loadedAt): array
    {
        $current = $this->trips->find($id);
        if ($current === null) {
            return ['ok' => false, 'conflict' => false, 'changed' => false];
        }
        if ($loadedAt === '' || $loadedAt !== (string) $current['updated_at']) {
            return ['ok' => false, 'conflict' => true, 'changed' => false];
        }

        $oldRows = $this->trips->rows($id);
        $old = [];
        $new = [];
        foreach ($header as $column => $value) {
            $before = $this->normalizeHeader($current)[$column] ?? null;
            if ($before !== $value) {
                $old[$column] = $before;
                $new[$column] = $value;
            }
        }
        $sig = static fn (array $list): array => array_map(static fn (array $r): array => [
            (int) $r['job_id'], (string) ($r['job_date'] ?? ''), (string) ($r['bh'] ?? ''), (string) ($r['remark'] ?? ''),
            ...array_map(static fn (string $f): string => ($r[$f] ?? null) === null ? '' : number_format((float) $r[$f], 2, '.', ''), TripRepository::MONEY),
        ], $list);
        $rowsChanged = $sig($oldRows) !== $sig($rows);
        if ($rowsChanged) {
            $old['jobs'] = array_column($oldRows, 'job_number');
            $new['jobs'] = array_column($rows, 'job_number');
            if ($this->canSeeFinancials()) {
                $old['total_expenses'] = TripTotals::forRows($oldRows)['total'];
                $new['total_expenses'] = TripTotals::forRows($rows)['total'];
            }
        }
        if ($new === []) {
            return ['ok' => true, 'conflict' => false, 'changed' => false];
        }

        $this->trips->update($id, $header, $rows, Auth::id());
        AuditService::record('trip_updated', 'trip', $id, $old, $new);
        return ['ok' => true, 'conflict' => false, 'changed' => true];
    }

    public function delete(int $id): ?string
    {
        $trip = $this->trips->find($id);
        if ($trip === null || !$this->trips->softDelete($id, Auth::id())) {
            return null;
        }
        AuditService::record('trip_deleted', 'trip', $id, ['trip_number' => $trip['trip_number'], 'jobs' => (int) $trip['job_count']]);
        return (string) $trip['trip_number'];
    }

    /**
     * @param array<string,mixed> $trip
     * @return array<string,mixed>
     */
    private function forViewer(array $trip): array
    {
        if (!$this->canSeeFinancials()) {
            unset($trip['total_expenses']);
        }
        return $trip;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function rowForViewer(array $row): array
    {
        if (!$this->canSeeFinancials()) {
            foreach (TripRepository::MONEY as $field) {
                unset($row[$field]);
            }
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $header
     * @return array<string,mixed>
     */
    private function normalizeHeader(array $header): array
    {
        $text = static fn (mixed $v): ?string => ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
        return [
            'trip_number' => (string) ($header['trip_number'] ?? ''),
            'foreman_id' => (int) ($header['foreman_id'] ?? 0),
            'begins_on' => $text($header['begins_on'] ?? null),
            'ends_on' => $text($header['ends_on'] ?? null),
            'collected_by' => $text($header['collected_by'] ?? null),
            'notes' => $text($header['notes'] ?? null),
        ];
    }

    private function decimal(mixed $value): ?string
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : number_format((float) $value, 2, '.', '');
    }
}
