<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ErrorPages;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Helpers\Paginator;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AdminGuard;
use App\Services\UserCriteria;
use App\Services\UserService;

/**
 * User administration (users.manage). Thin: validate, call UserService, turn its result code into a message.
 */
final class UserController extends Controller
{
    private const MESSAGES = [
        'self' => 'You cannot do that to your own account. Ask another administrator, or use My Account for your own password.',
        'last_admin' => 'That would leave nobody able to manage users and roles. At least one active administrator must remain.',
        'forbidden' => 'You can only manage users, and assign roles, whose permissions you hold yourself.',
        'invalid_role' => 'Please choose a valid role.',
    ];

    private UserService $service;
    private UserRepository $users;

    public function __construct()
    {
        $this->service = new UserService();
        $this->users = new UserRepository();
    }

    public function index(Request $request): Response
    {
        $criteria = UserCriteria::fromRequest($request);
        $total = $this->users->count($criteria);
        $paginator = new Paginator($total, UserCriteria::PER_PAGE, $criteria->page);
        $rows = $total === 0 ? [] : $this->users->search($criteria, UserCriteria::PER_PAGE, $paginator->offset());

        foreach ($rows as &$row) {
            $row['manageable'] = $this->service->canManage($row);
            $row['self'] = AdminGuard::isSelf((int) $row['id']);
        }
        unset($row);

        return $this->view('users/index', [
            'title' => 'Users',
            'users' => $rows,
            'criteria' => $criteria,
            'paginator' => $paginator,
            'roles' => (new RoleRepository())->allWithCounts(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('users/form', [
            'title' => 'New User',
            'user' => null,
            'roles' => $this->service->assignableRoles(),
            'isSelf' => false,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'role_id' => 'required|integer|exists:roles,id',
        ], '/users/create', ['name' => 'Name', 'email' => 'Email', 'role_id' => 'Role']);
        if ($data instanceof Response) {
            return $data;
        }

        $result = $this->service->create($data);
        if (!$result['ok']) {
            return $this->refuseForm($request, $result['code'], '/users/create');
        }

        $this->flash('success', 'User ' . $data['name'] . ' was created. They must choose a new password at first sign-in.');
        Session::flash('secret', ['label' => 'Temporary password for ' . strtolower((string) $data['email']), 'value' => $result['password']]);
        return $this->redirect('/users/' . $result['id'] . '/edit');
    }

    public function edit(Request $request, int $id): Response
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            return ErrorPages::response(404);
        }
        if (!$this->service->canManage($user)) {
            return ErrorPages::response(403, 'You can only manage users whose permissions you hold yourself.');
        }

        return $this->view('users/form', [
            'title' => 'Edit ' . $user['name'],
            'user' => $user,
            'roles' => $this->service->assignableRoles(),
            'isSelf' => AdminGuard::isSelf($id),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        if ($this->users->findById($id) === null) {
            return ErrorPages::response(404);
        }

        $back = '/users/' . $id . '/edit';
        $data = $this->validate($request, [
            'name' => 'required|max:120',
            'email' => 'required|email|max:190|unique:users,email,' . $id,
            'role_id' => 'required|integer|exists:roles,id',
        ], $back, ['name' => 'Name', 'email' => 'Email', 'role_id' => 'Role']);
        if ($data instanceof Response) {
            return $data;
        }

        $result = $this->service->update($id, $data);
        if (!$result['ok']) {
            return $this->refuseForm($request, $result['code'], $back);
        }

        $this->flash('success', $result['changed'] === [] ? 'No changes were made.' : 'User ' . $data['name'] . ' was updated.');
        return $this->redirect($back);
    }

    public function activate(Request $request, int $id): Response
    {
        return $this->setActive($id, true);
    }

    public function deactivate(Request $request, int $id): Response
    {
        return $this->setActive($id, false);
    }

    public function resetPassword(Request $request, int $id): Response
    {
        $result = $this->service->resetPassword($id);
        if (!$result['ok']) {
            return $this->refuse($result['code'], '/users/' . $id . '/edit');
        }

        $this->flash('success', 'The password was reset. The user must choose a new one at next sign-in, and any open sessions were ended.');
        Session::flash('secret', ['label' => 'Temporary password for ' . $result['email'], 'value' => $result['password']]);
        return $this->redirect('/users/' . $id . '/edit');
    }

    public function unlock(Request $request, int $id): Response
    {
        $result = $this->service->unlock($id);
        if (!$result['ok']) {
            return $this->refuse($result['code'], '/users/' . $id . '/edit');
        }

        $this->flash('success', 'The account was unlocked.');
        return $this->redirect('/users/' . $id . '/edit');
    }

    public function destroy(Request $request, int $id): Response
    {
        $user = $this->users->findById($id);
        $result = $this->service->delete($id);
        if (!$result['ok']) {
            return $this->refuse($result['code'], '/users/' . $id . '/edit');
        }

        $this->flash('success', 'User ' . $user['name'] . ' was deleted. Their jobs and history are kept, and the email address stays reserved.');
        return $this->redirect('/users');
    }

    private function setActive(int $id, bool $active): Response
    {
        $user = $this->users->findById($id);
        $result = $this->service->setActive($id, $active);
        if (!$result['ok']) {
            return $this->refuse($result['code'], '/users/' . $id . '/edit');
        }

        $this->flash('success', 'User ' . $user['name'] . ($active ? ' was activated.' : ' was deactivated and signed out.'));
        return $this->redirect('/users/' . $id . '/edit');
    }

    /** Turn a refusal from the service into a response. */
    private function refuse(string $code, string $back): Response
    {
        if ($code === 'not_found') {
            return ErrorPages::response(404);
        }
        $this->flash('danger', self::MESSAGES[$code] ?? 'That could not be done.');
        return $this->redirect($back);
    }

    /** Same, for create/update forms: field-level problems go back to the form with the typed values. */
    private function refuseForm(Request $request, string $code, string $back): Response
    {
        return match ($code) {
            'duplicate_email' => $this->redirectWithErrors($request, ['email' => ['That email address is already in use (it may belong to a deleted user).']], $back),
            'invalid_role', 'forbidden' => $this->redirectWithErrors($request, ['role_id' => [self::MESSAGES[$code]]], $back),
            default => $this->refuse($code, $back),
        };
    }
}
