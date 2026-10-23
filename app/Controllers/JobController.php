<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Services\JobService;
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

    public function index(Request $request): Response
    {
        $perPage = Paginator::pageSizeFromSettings();
        $requested = max(1, (int) $request->query('page', 1));

        $result = $this->jobs->page($perPage, ($requested - 1) * $perPage);
        $paginator = new Paginator($result['total'], $perPage, $requested);

        // A page beyond the end (e.g. after deletions) is re-fetched at the last valid page.
        if ($paginator->page !== $requested) {
            $result = $this->jobs->page($perPage, $paginator->offset());
        }

        return $this->view('jobs/index', [
            'title' => 'All Jobs',
            'jobs' => $result['rows'],
            'paginator' => $paginator,
            'showFinancials' => $this->jobs->canSeeFinancials(),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $job = $this->jobs->find($id);
        if ($job === null) {
            return ErrorPages::response(404);
        }

        return $this->view('jobs/show', [
            'title' => 'Job ' . $job['job_number'],
            'job' => $job,
            'showFinancials' => $this->jobs->canSeeFinancials(),
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
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate(
            $request,
            JobRules::rules(null, $this->jobs->canEditFinancials()),
            '/jobs/create',
            JobRules::labels()
        );
        if ($data instanceof Response) {
            return $data;
        }

        try {
            $id = $this->jobs->create($data);
        } catch (PDOException $e) {
            if ($this->isDuplicateJobNumber($e)) {
                return $this->redirectWithErrors($request, ['job_number' => [$this->duplicateMessage()]], '/jobs/create');
            }
            throw $e;
        }

        $this->flash('success', 'Job ' . $data['job_number'] . ' was created.');
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
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        if ($this->jobs->find($id) === null) {
            return ErrorPages::response(404);
        }

        $editUrl = '/jobs/' . $id . '/edit';
        $data = $this->validate(
            $request,
            JobRules::rules($id, $this->jobs->canEditFinancials()),
            $editUrl,
            JobRules::labels()
        );
        if ($data instanceof Response) {
            return $data;
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

        $this->flash('success', $result['changed'] === [] ? 'No changes were made.' : 'Job ' . $data['job_number'] . ' was updated.');
        return $this->redirect('/jobs/' . $id);
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
}
