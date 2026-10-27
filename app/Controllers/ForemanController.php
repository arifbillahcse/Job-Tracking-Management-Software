<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ForemanRepository;
use App\Services\AuditService;

/**
 * Foremen (foremen.manage). A foreman is deactivated, never deleted, so old trips keep the name.
 */
final class ForemanController extends Controller
{
    private ForemanRepository $foremen;

    public function __construct()
    {
        $this->foremen = new ForemanRepository();
    }

    public function index(Request $request): Response
    {
        $rows = $this->foremen->allWithTotals();
        if (!can('trips.financials')) {
            $rows = array_map(static function (array $r): array {
                unset($r['total_expenses']);
                return $r;
            }, $rows);
        }
        return $this->view('foremen/index', ['title' => 'Foremen', 'foremen' => $rows]);
    }

    public function create(Request $request): Response
    {
        return $this->view('foremen/form', ['title' => 'New Foreman', 'foreman' => null, 'action' => '/foremen']);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, $this->rules(null), '/foremen/create', $this->labels());
        if ($data instanceof Response) {
            return $data;
        }
        $id = $this->foremen->create($data);
        AuditService::record('foreman_created', 'foreman', $id, null, ['name' => $data['name']]);
        $this->flash('success', 'Foreman ' . $data['name'] . ' was added.');
        return $this->redirect('/foremen');
    }

    public function edit(Request $request, int $id): Response
    {
        $foreman = $this->foremen->find($id);
        if ($foreman === null) {
            return ErrorPages::response(404);
        }
        return $this->view('foremen/form', ['title' => 'Edit Foreman', 'foreman' => $foreman, 'action' => '/foremen/' . $id]);
    }

    public function update(Request $request, int $id): Response
    {
        $foreman = $this->foremen->find($id);
        if ($foreman === null) {
            return ErrorPages::response(404);
        }
        $data = $this->validate($request, $this->rules($id), '/foremen/' . $id . '/edit', $this->labels());
        if ($data instanceof Response) {
            return $data;
        }
        $this->foremen->update($id, $data);
        AuditService::record('foreman_updated', 'foreman', $id, ['name' => $foreman['name'], 'phone' => $foreman['phone']], ['name' => $data['name'], 'phone' => $data['phone']]);
        $this->flash('success', 'Foreman ' . $data['name'] . ' was updated.');
        return $this->redirect('/foremen');
    }

    public function toggle(Request $request, int $id): Response
    {
        $foreman = $this->foremen->find($id);
        if ($foreman === null) {
            return ErrorPages::response(404);
        }
        $activate = (int) $foreman['active'] !== 1;
        $this->foremen->setActive($id, $activate);
        AuditService::record($activate ? 'foreman_activated' : 'foreman_deactivated', 'foreman', $id, null, ['name' => $foreman['name']]);
        $this->flash('success', 'Foreman ' . $foreman['name'] . ($activate ? ' is active again.' : ' was deactivated. Their trips are kept, and they no longer appear in the trip form.'));
        return $this->redirect('/foremen');
    }

    /** @return array<string,string> */
    private function rules(?int $ignoreId): array
    {
        return [
            'name' => 'required|max:150|unique:foremen,name' . ($ignoreId !== null ? ',' . $ignoreId : ''),
            'phone' => 'nullable|phone|max:30',
        ];
    }

    /** @return array<string,string> */
    private function labels(): array
    {
        return ['name' => 'Name', 'phone' => 'Phone'];
    }
}
