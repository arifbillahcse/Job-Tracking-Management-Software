<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Repositories\ForemanRepository;
use App\Services\TripCriteria;
use App\Services\TripExportService;
use App\Services\TripService;

/**
 * Foreman trips. Thin: TripService validates and decides; this picks the response.
 * Unknown or deleted ids answer 404.
 */
final class TripController extends Controller
{
    private TripService $trips;

    public function __construct()
    {
        $this->trips = new TripService();
    }

    public function index(Request $request): Response
    {
        $criteria = TripCriteria::fromRequest($request);
        $total = $this->trips->count($criteria);
        $paginator = new Paginator($total, TripCriteria::PER_PAGE, $criteria->page);

        return $this->view('trips/index', [
            'title' => 'Trips',
            'trips' => $total === 0 ? [] : $this->trips->search($criteria, TripCriteria::PER_PAGE, $paginator->offset()),
            'criteria' => $criteria,
            'paginator' => $paginator,
            'foremen' => (new ForemanRepository())->options(),
            'showMoney' => $this->trips->canSeeFinancials(),
        ]);
    }

    /** The foreman-wise report: totals per foreman for trips beginning in a date range. */
    public function report(Request $request): Response
    {
        $criteria = TripCriteria::fromRequest($request);
        return $this->view('trips/report', [
            'title' => 'Trips by foreman',
            'report' => $this->trips->foremanReport($criteria),
            'criteria' => $criteria,
            'foremen' => (new ForemanRepository())->options($criteria->foremanId > 0 ? $criteria->foremanId : null),
        ]);
    }

    /** CSV of the trip list the user is looking at (same search and filters). */
    public function export(Request $request): Response
    {
        if (!can('trips.view')) {
            return ErrorPages::response(403);
        }
        $criteria = TripCriteria::fromRequest($request);
        $service = new TripExportService();
        if ($service->tooLarge($criteria)) {
            $this->flash('danger', 'That is too many rows to export in one file. Narrow the list with a search or filters and try again.');
            $params = array_filter($criteria->linkParams(), static fn (mixed $v): bool => $v !== '' && $v !== null);
            return $this->redirect('/trips' . ($params === [] ? '' : '?' . http_build_query($params)));
        }
        return $service->csv($criteria, $this->trips->canSeeFinancials());
    }

    public function exportReport(Request $request): Response
    {
        if (!can('trips.view')) {
            return ErrorPages::response(403);
        }
        $criteria = TripCriteria::fromRequest($request);
        return (new TripExportService())->reportCsv($this->trips->foremanReport($criteria), $criteria);
    }

    public function show(Request $request, int $id): Response
    {
        $trip = $this->trips->find($id);
        if ($trip === null) {
            return ErrorPages::response(404);
        }
        return $this->view('trips/show', ['title' => 'Trip ' . $trip['trip_number'], 'trip' => $trip, 'showMoney' => $this->trips->canSeeFinancials()]);
    }

    public function create(Request $request): Response
    {
        return $this->form('New Trip', null, '/trips', '/trips', 'Create trip', trim($request->queryStr('job')));
    }

    public function store(Request $request): Response
    {
        $prepared = $this->trips->prepare($request->allPost());
        if ($prepared['errors'] !== []) {
            return $this->redirectWithErrors($request, $prepared['errors'], '/trips/create');
        }

        $id = $this->trips->create($prepared['header'], $prepared['rows']);
        $this->flash('success', 'Trip ' . $prepared['header']['trip_number'] . ' was created.');
        return $this->redirect('/trips/' . $id);
    }

    public function edit(Request $request, int $id): Response
    {
        $trip = $this->trips->find($id);
        if ($trip === null) {
            return ErrorPages::response(404);
        }
        return $this->form('Edit Trip ' . $trip['trip_number'], $trip, '/trips/' . $id, '/trips/' . $id, 'Save changes');
    }

    public function update(Request $request, int $id): Response
    {
        if ($this->trips->find($id) === null) {
            return ErrorPages::response(404);
        }

        $editUrl = '/trips/' . $id . '/edit';
        $prepared = $this->trips->prepare($request->allPost(), $id, $this->trips->existingRows($id));
        if ($prepared['errors'] !== []) {
            return $this->redirectWithErrors($request, $prepared['errors'], $editUrl);
        }

        $result = $this->trips->update($id, $prepared['header'], $prepared['rows'], $request->str('loaded_at'));
        if ($result['conflict']) {
            Session::flash('alert', [
                'type' => 'warning',
                'message' => 'Someone else changed this trip while you were editing. Your entries are shown below; review them and save again to overwrite.',
            ]);
            return $this->redirectWithErrors($request, [], $editUrl);
        }
        if (!$result['ok']) {
            return ErrorPages::response(404);
        }

        $this->flash('success', $result['changed'] ? 'Trip ' . $prepared['header']['trip_number'] . ' was updated.' : 'No changes were made.');
        return $this->redirect('/trips/' . $id);
    }

    /** Adds the job ($id) to the end of an existing trip (posted as "trip"), from the Job Details page. */
    public function addJob(Request $request, int $id): Response
    {
        $jobId = $id;
        $id = (int) $request->str('trip');
        $result = $this->trips->addJob($id, $jobId);
        if ($result === 'no_job') {
            return ErrorPages::response(404);
        }
        $back = '/jobs/' . $jobId;
        if ($result === 'no_trip') {
            $this->flash('warning', 'Choose a trip to add the job to.');
            return $this->redirect($back);
        }
        if ($result === 'already') {
            $this->flash('warning', 'That job is already on the trip.');
            return $this->redirect($back);
        }
        $this->flash('success', 'The job was added to trip ' . ($this->trips->find($id)['trip_number'] ?? '') . '. Open the trip to enter its expenses.');
        return $this->redirect($back);
    }

    /** The "Add job" box on the trip page: a job typed by its number joins this trip (and gets its foreman). */
    public function addJobToTrip(Request $request, int $id): Response
    {
        $number = trim($request->str('job_number'));
        $result = $this->trips->addJobByNumber($id, $number);
        if ($result === 'no_trip') {
            return ErrorPages::response(404);
        }
        $back = '/trips/' . $id;
        $message = match ($result) {
            'ok' => ['success', 'Job ' . $number . ' was added to the trip.'],
            'already' => ['warning', 'Job ' . $number . ' is already on this trip.'],
            default => ['danger', $number === '' ? 'Type the job number to add.' : 'No job with the number "' . mb_substr($number, 0, 40) . '" was found (deleted jobs cannot be added).'],
        };
        $this->flash($message[0], $message[1]);
        return $this->redirect($back);
    }

    public function destroy(Request $request, int $id): Response
    {
        $number = $this->trips->delete($id);
        if ($number === null) {
            return ErrorPages::response(404);
        }
        $this->flash('success', 'Trip ' . $number . ' was deleted. The jobs on it are not affected.');
        return $this->redirect('/trips');
    }

    /** @param array<string,mixed>|null $trip */
    private function form(string $title, ?array $trip, string $action, string $cancelTo, string $submitLabel, string $prefillJob = ''): Response
    {
        return $this->view('trips/form', [
            'title' => $title,
            'trip' => $trip,
            'action' => $action,
            'cancelTo' => $cancelTo === '/trips' ? '/trips' : $cancelTo,
            'submitLabel' => $submitLabel,
            'foremen' => (new ForemanRepository())->options($trip === null ? null : (int) $trip['foreman_id']),
            'editMoney' => $this->trips->canSeeFinancials(),
            'prefillJob' => mb_substr($prefillJob, 0, 40),
        ]);
    }
}
