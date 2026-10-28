<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\JobService;
use App\Services\JobWorkflowService;
use App\Services\TripService;
use App\Validation\JobRules;
use PDOException;

/**
 * Job CRUD. Controllers stay thin: validate, call JobService, pick a response.
 * Unknown or soft-deleted ids answer 404.
 */
final class JobController extends Controller
{
    private JobService $jobs;

    public function __construct()
    {
        $this->jobs = new JobService();
    }

    public function show(Request $request, int $id): Response
    {
        \App\Services\StorageFeeService::refreshIfDue();
        $job = $this->jobs->find($id);
        if ($job === null) {
            return ErrorPages::response(404);
        }

        $workflow = new JobWorkflowService();
        $transitions = $workflow->availableTransitions($job);

        // Reopen the dialog the user was filling in (validation error) or the one a list shortcut asked for (?do=in_transit).
        $wanted = (string) (Session::flashed('open_modal') ?? $request->queryStr('do'));
        $autoOpen = in_array($wanted, array_column($transitions, 'to'), true) ? $wanted : null;

        $trips = new \App\Services\TripService();
        $showTrips = can('trips.view');

        return $this->view('jobs/show', [
            'title' => 'Job ' . $job['job_number'],
            'jobTrips' => $showTrips ? $trips->forJob($id) : [],
            'tripChoices' => $showTrips && can('trips.manage') ? $trips->choicesForJob($id) : [],
            'job' => $job,
            'showFinancials' => $this->jobs->canSeeFinancials(),
            'transitions' => $transitions,
            'timeline' => $workflow->timeline($job),
            'autoOpen' => $autoOpen,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('jobs/form', [
            'title' => 'New Job',
            'job' => null,
            'action' => '/jobs',
            'cancelTo' => '/jobs',
            'submitLabel' => 'Create job',
            'editFinancials' => $this->jobs->canEditFinancials(),
        ] + $this->assignmentChoices(null));
    }

    public function store(Request $request): Response
    {
        $data = $this->validate(
            $request,
            JobRules::rules(null, $this->jobs->canEditFinancials(), false, true, $this->jobs->canAssign()),
            '/jobs/create',
            JobRules::labels()
        );
        if ($data instanceof Response) {
            return $data;
        }
        $note = null;
        if ($this->jobs->canAssign()) {
            $assignment = (new TripService())->resolveAssignment($data['foreman_id'] ?? null, $data['trip_id'] ?? null);
            if ($assignment['errors'] !== []) {
                return $this->redirectWithErrors($request, $assignment['errors'], '/jobs/create');
            }
            $data['foreman_id'] = $assignment['foreman_id'];
            $data['trip_id'] = $assignment['trip_id'];
            $note = $assignment['note'];
        }

        try {
            $id = $this->jobs->create($data);
        } catch (PDOException $e) {
            if ($this->isDuplicateJobNumber($e)) {
                return $this->redirectWithErrors($request, ['job_number' => [$this->duplicateMessage()]], '/jobs/create');
            }
            throw $e;
        }

        $this->flash('success', 'Job ' . $data['job_number'] . ' was created.' . ($note !== null ? ' ' . $note : ''));
        return $this->redirect('/jobs/' . $id);
    }

    public function edit(Request $request, int $id): Response
    {
        $job = $this->jobs->find($id);
        if ($job === null) {
            return ErrorPages::response(404);
        }

        return $this->view('jobs/form', [
            'title' => 'Edit Job ' . $job['job_number'],
            'job' => $job,
            'action' => '/jobs/' . $id,
            'cancelTo' => '/jobs/' . $id,
            'submitLabel' => 'Save changes',
            'editFinancials' => $this->jobs->canEditFinancials(),
        ] + $this->assignmentChoices($job));
    }

    public function update(Request $request, int $id): Response
    {
        $job = $this->jobs->find($id);
        if ($job === null) {
            return ErrorPages::response(404);
        }

        $editUrl = '/jobs/' . $id . '/edit';
        $data = $this->validate(
            $request,
            JobRules::rules($id, $this->jobs->canEditFinancials(), $job['status'] === 'in_transit', true, $this->jobs->canAssign()) + JobRules::dateRules((string) $job['status']),
            $editUrl,
            JobRules::labels()
        );
        if ($data instanceof Response) {
            return $data;
        }
        $note = null;
        if ($this->jobs->canAssign()) {
            $assignment = (new TripService())->resolveAssignment($data['foreman_id'] ?? null, $data['trip_id'] ?? null, $job);
            if ($assignment['errors'] !== []) {
                return $this->redirectWithErrors($request, $assignment['errors'], $editUrl);
            }
            $data['foreman_id'] = $assignment['foreman_id'];
            $data['trip_id'] = $assignment['trip_id'];
            $note = $assignment['note'];
        }
        if (($problems = JobRules::dateProblems($data)) !== []) {
            return $this->redirectWithErrors($request, $problems, $editUrl);
        }

        try {
            $result = $this->jobs->update($id, $data, $request->str('loaded_at'));
        } catch (PDOException $e) {
            if ($this->isDuplicateJobNumber($e)) {
                return $this->redirectWithErrors($request, ['job_number' => [$this->duplicateMessage()]], $editUrl);
            }
            throw $e;
        }

        if ($result['conflict']) {
            Session::flash('alert', [
                'type' => 'warning',
                'message' => 'Someone else changed this job while you were editing. Your entries are shown below; '
                    . 'review them and save again to overwrite.',
            ]);
            return $this->redirectWithErrors($request, [], $editUrl);
        }
        if (!$result['ok']) {
            return ErrorPages::response(404);
        }

        $this->flash('success', $result['changed'] === [] ? 'No changes were made.' : 'Job ' . $data['job_number'] . ' was updated.' . ($note !== null ? ' ' . $note : ''));
        return $this->redirect('/jobs/' . $id);
    }

    /** One endpoint for every status change; JobWorkflowService decides what is allowed. */
    public function changeStatus(Request $request, int $id): Response
    {
        $to = $request->str('to');
        $back = '/jobs/' . $id;
        $result = (new JobWorkflowService())->change($id, $to, $request->allPost());

        if ($result['ok']) {
            $label = (string) \App\Core\Config::get('statuses.statuses.' . $result['to'] . '.label', $result['to']);
            $on = $result['loaded_date'] ? ' Loaded date: ' . fmt_date($result['loaded_date']) . '.' : ($result['delivery_date'] ? ' Delivery date: ' . fmt_date($result['delivery_date']) . '.' : '');
            $this->flash('success', 'Job ' . $result['job_number'] . ' is now ' . $label . '.' . $on);
            return $this->redirect($back);
        }

        switch ($result['code']) {
            case 'not_found':
                return ErrorPages::response(404);
            case 'forbidden':
                return ErrorPages::response(403);
            case 'validation':
                Session::flash('open_modal', $to);
                return $this->redirectWithErrors($request, $result['errors'], $back);
            case 'conflict':
                $this->flash('warning', 'This job was just changed by someone else. Check its current status and try again.');
                return $this->redirect($back);
            default:
                $this->flash('danger', 'That status change is not allowed for this job.');
                return $this->redirect($back);
        }
    }

    public function destroy(Request $request, int $id): Response
    {
        $job = $this->jobs->find($id);
        if ($job === null || !$this->jobs->delete($id)) {
            return ErrorPages::response(404);
        }

        $this->flash('success', 'Job ' . $job['job_number'] . ' was moved to the trash. It can be restored from the Trash page.');
        return $this->redirect('/jobs');
    }

    public function trash(Request $request): Response
    {
        return $this->view('jobs/trash', [
            'title' => 'Trash',
            'jobs' => $this->jobs->trashed(),
        ]);
    }

    public function restore(Request $request, int $id): Response
    {
        $job = $this->jobs->findTrashed($id);
        if ($job === null || !$this->jobs->restore($id)) {
            return ErrorPages::response(404);
        }

        $this->flash('success', 'Job ' . $job['job_number'] . ' was restored.');
        return $this->redirect('/jobs/' . $id);
    }

    private function isDuplicateJobNumber(PDOException $e): bool
    {
        return (string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'job_number');
    }

    private function duplicateMessage(): string
    {
        return 'That job number is already in use (it may belong to a deleted job).';
    }

    /**
     * What the job form needs to offer the Foreman and Trip choices (only for people who may manage trips).
     *
     * @param array<string,mixed>|null $job
     * @return array{canAssign:bool,foremen:array<int,array<string,mixed>>,trips:array<int,array<string,mixed>>}
     */
    private function assignmentChoices(?array $job): array
    {
        if (!$this->jobs->canAssign()) {
            return ['canAssign' => false, 'foremen' => [], 'trips' => []];
        }
        $trips = new TripService();
        return [
            'canAssign' => true,
            'foremen' => $trips->foremanOptions(isset($job['foreman_id']) ? (int) $job['foreman_id'] : null),
            'trips' => $trips->tripOptions(isset($job['trip_id']) ? (int) $job['trip_id'] : null),
        ];
    }
}
