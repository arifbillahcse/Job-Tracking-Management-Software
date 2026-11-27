<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Services\CompanyService;

/**
 * Companies (companies.manage): the address, phone, DOT and MC number of the companies (the jobs' brokers). See CompanyService.
 */
final class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim($request->queryStr('q'));
        return $this->view('companies/index', [
            'title' => 'Companies',
            'q' => $q,
            'companies' => CompanyService::search($q),
            'unlinked' => $q === '' ? CompanyService::unlinked() : [],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('companies/form', ['title' => 'New Company', 'company' => null, 'action' => '/companies', 'prefill' => CompanyService::clean($request->queryStr('name')), 'jobCount' => 0]);
    }

    public function store(Request $request): Response
    {
        $result = CompanyService::save(null, $this->input($request), false);
        if (!$result['ok']) {
            return $this->redirectWithErrors($request, array_map(static fn (string $m): array => [$m], $result['errors']), '/companies/create');
        }
        $this->flash('success', 'Company ' . CompanyService::clean($request->str('name')) . ' was added.');
        return $this->redirect('/companies');
    }

    public function edit(Request $request, int $id): Response
    {
        $company = CompanyService::find($id);
        if ($company === null) {
            return ErrorPages::response(404);
        }
        return $this->view('companies/form', [
            'title' => 'Edit Company', 'company' => $company, 'action' => '/companies/' . $id, 'prefill' => '',
            'jobCount' => CompanyService::jobCount((string) $company['name']),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $result = CompanyService::save($id, $this->input($request), $request->str('rename_jobs') === '1');
        if (($result['errors']['name'] ?? '') === 'not_found') {
            return ErrorPages::response(404);
        }
        if (!$result['ok']) {
            return $this->redirectWithErrors($request, array_map(static fn (string $m): array => [$m], $result['errors']), '/companies/' . $id . '/edit');
        }
        $jobs = (int) ($result['jobs'] ?? 0);
        $this->flash('success', 'Company ' . CompanyService::clean($request->str('name')) . ' was updated.' . ($jobs > 0 ? ' The broker was changed on ' . $jobs . ' job' . ($jobs === 1 ? '' : 's') . '.' : ''));
        return $this->redirect('/companies');
    }

    public function destroy(Request $request, int $id): Response
    {
        $company = CompanyService::find($id);
        if ($company === null) {
            return ErrorPages::response(404);
        }
        CompanyService::delete($id);
        $this->flash('success', 'The details of ' . $company['name'] . ' were removed. Its jobs and invoices keep the broker name.');
        return $this->redirect('/companies');
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $data = ['name' => $request->str('name')];
        foreach (array_keys(CompanyService::FIELDS) as $key) {
            $data[$key] = $request->str($key);
        }
        return $data;
    }
}
