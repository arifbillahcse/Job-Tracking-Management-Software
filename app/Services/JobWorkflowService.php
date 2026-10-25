<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Validator;
use App\Repositories\JobRepository;
use App\Repositories\StatusHistoryRepository;

/**
 * Status changes. The rules live in app/Config/statuses.php; this class enforces them:
 * the transition must exist, the user must hold the permission for that transition, the data it
 * needs (carrier, delivery date, reason) must validate, and the status update plus its history row
 * are written in one transaction. Nothing outside this class changes a job's status.
 */
final class JobWorkflowService
{
    private const CARRIER = ['carrier_name', 'driver_name', 'driver_phone'];

    public function __construct(
        private JobRepository $jobs = new JobRepository(),
        private StatusHistoryRepository $history = new StatusHistoryRepository()
    ) {
    }

    /**
     * Transitions the current user may perform on this job (what to show as buttons).
     *
     * @param array<string,mixed> $job
     * @return array<int,array<string,mixed>> each: to, label, permission, style, form, note
     */
    public function availableTransitions(array $job): array
    {
        $available = [];
        foreach ((array) Config::get('statuses.transitions.' . $job['status'], []) as $to => $definition) {
            if (Auth::can((string) $definition['permission'])) {
                $available[] = ['to' => (string) $to] + $definition;
            }
        }
        return $available;
    }

    /**
     * Whether a job can be put in $to from where it is now by someone holding the permission (list-row shortcuts).
     *
     * @param array<string,mixed> $job
     */
    public function canMove(array $job, string $to): bool
    {
        $definition = Config::get('statuses.transitions.' . $job['status'] . '.' . $to);
        return is_array($definition) && Auth::can((string) $definition['permission']);
    }

    /**
     * @param array<string,mixed> $input submitted form data (carrier fields, delivered_on, note)
     * @return array{ok:bool,code?:string,errors?:array<string,array<int,string>>,from?:string,to?:string,job_number?:string}
     *         code: not_found | invalid | forbidden | validation | conflict
     */
    public function change(int $id, string $to, array $input): array
    {
        $job = $this->jobs->find($id);
        if ($job === null) {
            return ['ok' => false, 'code' => 'not_found'];
        }

        $from = (string) $job['status'];
        $definition = Config::get("statuses.transitions.{$from}.{$to}");
        if (!is_array($definition)) {
            return ['ok' => false, 'code' => 'invalid', 'from' => $from, 'to' => $to];
        }

        if (!Auth::can((string) $definition['permission'])) {
            Logger::warning('Status change denied', ['user_id' => Auth::id(), 'job_id' => $id, 'from' => $from, 'to' => $to]);
            AuditService::record('access_denied', 'job', $id, null, ['permission' => $definition['permission'], 'transition' => "{$from}->{$to}"]);
            return ['ok' => false, 'code' => 'forbidden'];
        }

        $rules = ['note' => ($definition['note'] === 'required' ? 'required' : 'nullable') . '|max:1000'];

        $validator = Validator::make($input, $rules, ['note' => 'Note']);
        $errors = $validator->errors();
        $data = $validator->validated();

        if ($errors !== []) {
            return ['ok' => false, 'code' => 'validation', 'errors' => $errors];
        }

        [$changes, $details] = $this->plan($job, $to, (string) $definition['form'], $data);
        $note = $data['note'] ?? null;

        $moved = Database::transaction(function () use ($id, $from, $to, $changes, $note, $details): bool {
            if (!$this->jobs->changeStatus($id, $from, $changes, Auth::id())) {
                return false; // someone else moved the job first
            }
            $this->history->insert($id, $from, $to, $note, $details, Auth::id());
            return true;
        });

        if (!$moved) {
            return ['ok' => false, 'code' => 'conflict'];
        }

        AuditService::record(
            'job_status_changed',
            'job',
            $id,
            ['status' => $from],
            ['status' => $to, 'note' => $note] + ($details ?? [])
        );

        // Dates and fees follow the status: re-assess this job's storage fee now (a delivery freezes it).
        StorageFeeService::settle($id);

        return [
            'ok' => true, 'from' => $from, 'to' => $to, 'job_number' => (string) $job['job_number'],
            'loaded_date' => $changes['loaded_date'] ?? null, 'delivery_date' => $changes['delivery_date'] ?? null,
        ];
    }

    /**
     * Newest first: every status change, ending with the creation of the job.
     *
     * @param array<string,mixed> $job
     * @return array<int,array<string,mixed>>
     */
    public function timeline(array $job): array
    {
        $events = [];
        foreach ($this->history->forJob((int) $job['id']) as $row) {
            $details = $row['details'] === null ? [] : json_decode((string) $row['details'], true);
            $events[] = [
                'type' => 'status',
                'from' => (string) $row['from_status'],
                'to' => (string) $row['to_status'],
                'note' => $row['note'],
                'details' => is_array($details) ? $details : [],
                'by' => $row['changed_by_name'],
                'at' => (string) $row['created_at'],
            ];
        }
        $events[] = ['type' => 'created', 'by' => $job['created_by_name'] ?? null, 'at' => (string) $job['created_at']];
        return $events;
    }

    /**
     * Columns to write and the details to keep in the history row for a transition.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $data validated input
     * @return array{0:array<string,mixed>,1:array<string,mixed>|null}
     */
    private function plan(array $job, string $to, string $form, array $data): array
    {
        $changes = ['status' => $to];
        $details = null;

        $today = Clock::today();

        switch ($to) {
            case 'in_transit':
                $changes['delivered_at'] = null;
                $changes['delivery_date'] = null;
                if ($job['status'] === 'delivered') {
                    // Re-opened after delivery: it was loaded before, so the loaded date stays.
                    $details = ['reopened' => true, 'delivery_date_cleared' => $job['delivery_date'] ?? null];
                } else {
                    $changes['loaded_date'] = $today; // the click is the loading: today's date, in the business time zone
                    $changes['in_transit_at'] = gmdate('Y-m-d H:i:s');
                    $details = ['loaded_date' => $today];
                }
                break;

            case 'delivered':
                $changes['delivery_date'] = $today;
                $changes['delivered_at'] = $today . ' 00:00:00';
                $details = ['delivery_date' => $today];
                break;

            case 'booked':
                // Back to Booked (resume / reopen): the job has to be moved again. What was cleared stays in the history.
                $cleared = [];
                foreach ([...self::CARRIER, 'in_transit_at', 'delivered_at', 'loaded_date', 'delivery_date'] as $column) {
                    if ($job[$column] !== null && $job[$column] !== '') {
                        $cleared[$column] = $job[$column];
                    }
                    $changes[$column] = null;
                }
                $details = $cleared === [] ? null : ['cleared' => $cleared];
                break;
        }

        return [$changes, $details];
    }
}
