<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\RoleRepository;
use App\Services\RoleService;

/**
 * Role and permission administration (roles.manage).
 */
final class RoleController extends Controller
{
    private RoleService $service;
    private RoleRepository $roles;

    public function __construct()
    {
        $this->service = new RoleService();
        $this->roles = new RoleRepository();
    }

    public function index(Request $request): Response
    {
        return $this->view('roles/index', ['title' => 'Roles', 'roles' => $this->roles->allWithCounts()]);
    }

    public function create(Request $request): Response
    {
        return $this->view('roles/form', [
            'title' => 'New Role',
            'role' => null,
            'copyable' => $this->roles->allWithCounts(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|label|max:60',
            'description' => 'nullable|max:255',
            'copy_from' => 'nullable|integer',
        ], '/roles/create', ['name' => 'Role name', 'description' => 'Description']);
        if ($data instanceof Response) {
            return $data;
        }

        $result = $this->service->create((string) $data['name'], $data['description'], $data['copy_from'] === null ? null : (int) $data['copy_from']);
        if (!$result['ok']) {
            return $this->refuseForm($request, $result['code'], '/roles/create');
        }

        $this->flash('success', 'Role ' . $data['name'] . ' was created. Set its permissions in the matrix below.');
        return $this->redirect('/roles/matrix');
    }

    public function edit(Request $request, int $id): Response
    {
        $role = $this->roles->find($id);
        if ($role === null) {
            return ErrorPages::response(404);
        }

        return $this->view('roles/form', ['title' => 'Edit ' . $role['name'], 'role' => $role, 'copyable' => []]);
    }

    public function update(Request $request, int $id): Response
    {
        if ($this->roles->find($id) === null) {
            return ErrorPages::response(404);
        }

        $back = '/roles/' . $id . '/edit';
        $data = $this->validate($request, [
            'name' => 'required|label|max:60',
            'description' => 'nullable|max:255',
        ], $back, ['name' => 'Role name', 'description' => 'Description']);
        if ($data instanceof Response) {
            return $data;
        }

        $result = $this->service->update($id, (string) $data['name'], $data['description']);
        if (!$result['ok']) {
            return $this->refuseForm($request, $result['code'], $back);
        }

        $this->flash('success', $result['changed'] === [] ? 'No changes were made.' : 'Role ' . $data['name'] . ' was updated.');
        return $this->redirect('/roles');
    }

    public function destroy(Request $request, int $id): Response
    {
        $role = $this->roles->find($id);
        $result = $this->service->delete($id);

        if (!$result['ok']) {
            if ($result['code'] === 'not_found') {
                return ErrorPages::response(404);
            }
            $message = match ($result['code']) {
                'system_role' => 'The ' . $role['name'] . ' role is built in and cannot be deleted.',
                'has_users' => 'The ' . $role['name'] . ' role still has ' . $result['users'] . ' user(s) (including deleted ones). Move them to another role first.',
                default => 'That could not be done.',
            };
            $this->flash('danger', $message);
            return $this->redirect('/roles');
        }

        $this->flash('success', 'Role ' . $role['name'] . ' was deleted.');
        return $this->redirect('/roles');
    }

    public function matrix(Request $request): Response
    {
        return $this->view('roles/matrix', ['title' => 'Permission Matrix'] + $this->service->matrix());
    }

    public function saveMatrix(Request $request): Response
    {
        $perm = $request->post('perm', []);
        $result = $this->service->saveMatrix(is_array($perm) ? $perm : [], (string) $request->post('version', ''));

        if (!$result['ok']) {
            $this->flash(
                $result['code'] === 'conflict' ? 'warning' : 'danger',
                $result['code'] === 'conflict'
                    ? 'Someone else changed the permissions while you were editing. The matrix below shows the current state; make your changes again.'
                    : 'That would leave nobody able to manage users and roles. At least one active administrator must remain. Nothing was saved.'
            );
            return $this->redirect('/roles/matrix');
        }

        $this->flash('success', $result['roles_changed'] === 0
            ? 'No changes were made.'
            : 'Permissions were saved for ' . $result['roles_changed'] . ' role(s). They apply on each user\'s next request.');
        return $this->redirect('/roles/matrix');
    }

    private function refuseForm(Request $request, string $code, string $back): Response
    {
        return match ($code) {
            'duplicate_name' => $this->redirectWithErrors($request, ['name' => ['A role with that name already exists.']], $back),
            'system_role' => $this->redirectWithErrors($request, ['name' => ['Built-in roles cannot be renamed.']], $back),
            'not_found' => ErrorPages::response(404),
            default => $this->redirectWithErrors($request, ['name' => ['That could not be saved.']], $back),
        };
    }
}
