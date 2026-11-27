<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Services\CompanyService;
use App\Services\DriverService;

/**
 * Drivers (drivers.manage): the phone and licence number of the drivers (the jobs' Driver names). See DriverService.
 */
final class DriverController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim($request->queryStr('q'));
        return $this->view('drivers/index', [
            'title' => 'Drivers',
            'q' => $q,
            'drivers' => DriverService::search($q),
            'unlinked' => $q === '' ? DriverService::unlinked() : [],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('drivers/form', ['title' => 'New Driver', 'driver' => null, 'action' => '/drivers', 'prefill' => CompanyService::clean($request->queryStr('name')), 'jobCount' => 0]);
    }

    public function store(Request $request): Response
    {
        $result = DriverService::save(null, $this->input($request), false);
        if (!$result['ok']) {
            return $this->redirectWithErrors($request, array_map(static fn (string $m): array => [$m], $result['errors']), '/drivers/create');
        }
        $this->flash('success', 'Driver ' . CompanyService::clean($request->str('name')) . ' was added.');
        return $this->redirect('/drivers');
    }

    public function edit(Request $request, int $id): Response
    {
        $driver = DriverService::find($id);
        if ($driver === null) {
            return ErrorPages::response(404);
        }
        return $this->view('drivers/form', [
            'title' => 'Edit Driver', 'driver' => $driver, 'action' => '/drivers/' . $id, 'prefill' => '',
            'jobCount' => DriverService::jobCount((string) $driver['name']),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $result = DriverService::save($id, $this->input($request), $request->str('rename_jobs') === '1');
        if (($result['errors']['name'] ?? '') === 'not_found') {
            return ErrorPages::response(404);
        }
        if (!$result['ok']) {
            return $this->redirectWithErrors($request, array_map(static fn (string $m): array => [$m], $result['errors']), '/drivers/' . $id . '/edit');
        }
        $jobs = (int) ($result['jobs'] ?? 0);
        $this->flash('success', 'Driver ' . CompanyService::clean($request->str('name')) . ' was updated.' . ($jobs > 0 ? ' The driver name was changed on ' . $jobs . ' job' . ($jobs === 1 ? '' : 's') . '.' : ''));
        return $this->redirect('/drivers');
    }

    public function destroy(Request $request, int $id): Response
    {
        $driver = DriverService::find($id);
        if ($driver === null) {
            return ErrorPages::response(404);
        }
        DriverService::delete($id);
        $this->flash('success', 'The details of ' . $driver['name'] . ' were removed. Its jobs and invoices keep the driver name.');
        return $this->redirect('/drivers');
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $data = ['name' => $request->str('name')];
        foreach (array_keys(DriverService::FIELDS) as $key) {
            $data[$key] = $request->str($key);
        }
        return $data;
    }
}
