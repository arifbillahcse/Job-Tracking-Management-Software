<?php

declare(strict_types=1);

/**
 * Phase 4 tests: user management, role management, the permission matrix and the safeguards.
 * Included by tests/run.php (after phase3.php), which provides the fixtures and helpers.
 */

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;
use App\Helpers\PasswordPolicy;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AdminGuard;
use App\Services\AuthService;
use App\Services\LastAdministratorException;
use App\Services\PermissionService;
use App\Services\RoleService;
use App\Services\UserCriteria;
use App\Services\UserService;

// ---------------------------------------------------------------- helpers

function role_id(string $name): int
{
    return (int) (new RoleRepository())->findByName($name)['id'];
}

function perm_id(string $name): int
{
    return (int) Database::fetchValue('SELECT id FROM permissions WHERE name = ?', [$name]);
}

function has_perm(string $role, string $permission): bool
{
    PermissionService::flush();
    return PermissionService::roleHas(role_id($role), $permission);
}

/** @return array<int,string> */
function role_perms(string $role): array
{
    $keys = (new RoleRepository())->permissionKeys(role_id($role));
    sort($keys);
    return $keys;
}

function user_row(int $id): ?array
{
    return Database::fetchOne('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]);
}

function audit_count(string $action): int
{
    return (int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]);
}

/** Everything the app has persisted that could leak a secret: audit table and log files. */
function secrets_leaked(string $secret): bool
{
    $inAudit = (int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs WHERE old_values LIKE ? OR new_values LIKE ?', ['%' . $secret . '%', '%' . $secret . '%']) > 0;
    $inLogs = false;
    foreach (glob(BASE_PATH . '/storage/logs/*.log') ?: [] as $file) {
        $inLogs = $inLogs || str_contains((string) file_get_contents($file), $secret);
    }
    return $inAudit || $inLogs;
}

/** Fresh database with an Admin, Manager and Staff user and a UserAdmin role (users.manage + everything Staff has). */
function admin_world(): array
{
    fresh_db();
    fresh_session();
    Clock::fake('2026-10-23');
    $w = [
        'admin' => make_user('Admin', 'admin@example.com'),
        'manager' => make_user('Manager', 'manager@example.com'),
        'staff' => make_user('Staff', 'staff@example.com'),
    ];
    make_role('UserAdmin', ['users.manage', 'dashboard.view', 'jobs.view', 'jobs.create', 'jobs.edit', 'jobs.status']);
    $w['uadmin'] = make_user('UserAdmin', 'uadmin@example.com');
    return $w;
}

// ================================================================ validator additions

section('Users: validator additions');
admin_world();
check(Validator::make(['r' => (string) role_id('Staff')], ['r' => 'exists:roles,id'])->passes() && Validator::make(['r' => '9999'], ['r' => 'exists:roles,id'])->fails(), 'exists rule checks the table');
throws(static fn () => Validator::make(['r' => '1'], ['r' => 'exists:roles;DROP,id']), 'exists rejects unsafe identifiers');
check(Validator::make(['n' => 'Night Dispatch-2'], ['n' => 'label'])->passes() && Validator::make(['n' => '<b>x</b>'], ['n' => 'label'])->fails() && Validator::make(['n' => '-lead'], ['n' => 'label'])->fails(), 'label rule allows readable names only');

// ================================================================ admin guard

section('Safeguards: who counts as an administrator');
$w = admin_world();
$repo = new UserRepository();
check($repo->countAdministrators() === 1, 'one administrator to start with (the Admin user)');
Database::execute('UPDATE users SET is_active = 0 WHERE id = ?', [$w['admin']['id']]);
check($repo->countAdministrators() === 0, 'an inactive Admin does not count');
Database::execute('UPDATE users SET is_active = 1, deleted_at = ? WHERE id = ?', ['2026-01-01 00:00:00', $w['admin']['id']]);
check($repo->countAdministrators() === 0, 'a deleted Admin does not count');
Database::execute('UPDATE users SET deleted_at = NULL WHERE id = ?', [$w['admin']['id']]);
make_role('HalfAdmin', ['users.manage']);
make_user('HalfAdmin', 'half@example.com');
check($repo->countAdministrators() === 1, 'a role with only users.manage is not an administrator (needs roles.manage too)');
check($repo->countAdministrators((int) $w['admin']['id']) === 0, 'excluding a user from the count works');
check($repo->isAdministrator((int) $w['admin']['id']) && !$repo->isAdministrator((int) $w['staff']['id']), 'isAdministrator');
Database::execute('UPDATE users SET is_active = 0 WHERE id = ?', [$w['admin']['id']]);
throws(static fn () => AdminGuard::assertAdministratorRemains(), 'the guard throws when nobody could administer the system');
Database::execute('UPDATE users SET is_active = 1 WHERE id = ?', [$w['admin']['id']]);

// ================================================================ user service

section('Users: create');
$w = admin_world();
sign_in($w['admin']);
$svc = new UserService();
$staffRole = role_id('Staff');
$r = $svc->create(['name' => 'Nora Newhire', 'username' => username_of('Nora@Example.com'), 'email' => 'Nora@Example.com', 'role_id' => $staffRole]);
$nora = user_row((int) $r['id']);
check($r['ok'] && strlen($r['password']) === 16 && PasswordPolicy::check($r['password']) === null, 'creates the user with a generated password that meets the policy');
check($nora['email'] === 'nora@example.com' && $nora['role_name'] === 'Staff' && (int) $nora['must_change_password'] === 1 && (int) $nora['is_active'] === 1, 'email lower-cased, role set, active, must change password');
check($nora['password_hash'] !== $r['password'] && password_verify($r['password'], $nora['password_hash']) && str_starts_with($nora['password_hash'], '$argon2id$'), 'the password is stored only as an Argon2id hash');
check(!secrets_leaked($r['password']), 'the temporary password is not in the audit log or any log file');
$a = Database::fetchOne("SELECT * FROM audit_logs WHERE action = 'user_created' ORDER BY id DESC LIMIT 1");
check($a !== null && str_contains((string) $a['new_values'], 'nora@example.com') && str_contains((string) $a['new_values'], 'Staff'), 'creation is audited (who, which role)');
check($svc->create(['name' => 'Dup', 'username' => 'dup-user', 'email' => 'NORA@example.com', 'role_id' => $staffRole])['code'] === 'duplicate_email', 'a duplicate email (any case) is refused');
check($svc->create(['name' => 'Bad', 'username' => username_of('bad@example.com'), 'email' => 'bad@example.com', 'role_id' => 99999])['code'] === 'invalid_role', 'an unknown role is refused');
check($svc->create(['name' => 'Adm', 'username' => username_of('newadmin@example.com'), 'email' => 'newadmin@example.com', 'role_id' => role_id('Admin')])['ok'], 'an Admin can create another Admin');

section('Safeguards: least privilege (you can only grant what you hold)');
sign_in($w['uadmin']);
$assignable = array_column($svc->assignableRoles(), 'name');
sort($assignable);
check($assignable === ['Staff', 'UserAdmin'], 'a user with users.manage can assign only roles whose permissions they all hold');
check($svc->create(['name' => 'X', 'username' => username_of('x1@example.com'), 'email' => 'x1@example.com', 'role_id' => role_id('Admin')])['code'] === 'forbidden', 'cannot create an Admin');
check($svc->create(['name' => 'X', 'username' => username_of('x2@example.com'), 'email' => 'x2@example.com', 'role_id' => role_id('Manager')])['code'] === 'forbidden', 'cannot create a Manager (needs permissions they lack)');
check($svc->create(['name' => 'Okay', 'username' => username_of('x3@example.com'), 'email' => 'x3@example.com', 'role_id' => role_id('Staff')])['ok'], 'can create a Staff user');
check($svc->update((int) $w['admin']['id'], ['name' => 'Hacked', 'username' => username_of('admin@example.com'), 'email' => 'admin@example.com', 'role_id' => role_id('Admin')])['code'] === 'forbidden', 'cannot edit an Admin');
check($svc->update((int) $w['manager']['id'], ['name' => 'Hacked', 'username' => username_of('manager@example.com'), 'email' => 'manager@example.com', 'role_id' => role_id('Manager')])['code'] === 'forbidden', 'cannot edit a Manager');
check($svc->resetPassword((int) $w['admin']['id'])['code'] === 'forbidden', 'cannot reset an Admin\'s password');
check($svc->setActive((int) $w['admin']['id'], false)['code'] === 'forbidden' && $svc->delete((int) $w['manager']['id'])['code'] === 'forbidden' && $svc->unlock((int) $w['admin']['id'])['code'] === 'forbidden', 'cannot deactivate, delete or unlock users above their level');
check(user_row((int) $w['admin']['id'])['name'] === (string) $w['admin']['name'] && (int) user_row((int) $w['admin']['id'])['is_active'] === 1, 'nothing about those users changed');
check($svc->update((int) $w['staff']['id'], ['name' => 'Staff Renamed', 'username' => username_of('staff@example.com'), 'email' => 'staff@example.com', 'role_id' => role_id('Staff')])['ok'], 'can edit a Staff user');
check($svc->update((int) $w['staff']['id'], ['name' => 'Staff Renamed', 'username' => username_of('staff@example.com'), 'email' => 'staff@example.com', 'role_id' => role_id('Manager')])['code'] === 'forbidden' && user_row((int) $w['staff']['id'])['role_name'] === 'Staff', 'cannot promote a Staff user to a role above their own level');

section('Users: update');
sign_in($w['admin']);
$id = (int) $w['staff']['id'];
$r = $svc->update($id, ['name' => '  Stacy Staff ', 'username' => 'staff', 'email' => 'Stacy@Example.com', 'role_id' => role_id('Manager')]);
$u = user_row($id);
check($r['ok'] && $u['name'] === 'Stacy Staff' && $u['email'] === 'stacy@example.com' && $u['role_name'] === 'Manager' && $r['changed'] === ['name', 'email', 'role'], 'name, email and role updated; trimmed and lower-cased');
$a = Database::fetchOne("SELECT * FROM audit_logs WHERE action = 'user_updated' ORDER BY id DESC LIMIT 1");
$old = json_decode((string) $a['old_values'], true);
$new = json_decode((string) $a['new_values'], true);
check($old['role'] === 'Staff' && $new['role'] === 'Manager' && $new['email'] === 'stacy@example.com', 'the audit entry records old and new values (role by name)');
$count = audit_count('user_updated');
check($svc->update($id, ['name' => 'Stacy Staff', 'username' => 'staff', 'email' => 'stacy@example.com', 'role_id' => role_id('Manager')])['changed'] === [] && audit_count('user_updated') === $count, 'saving without changes is a no-op and writes no audit entry');
check($svc->update($id, ['name' => 'S', 'username' => 'staff', 'email' => 'nora@example.com', 'role_id' => role_id('Manager')])['code'] === 'duplicate_email', 'changing to an email that is taken is refused');
check(user_row($id)['email'] === 'stacy@example.com', '...and nothing was saved');
check($svc->update(999999, ['name' => 'x', 'username' => username_of('x@example.com'), 'email' => 'x@example.com', 'role_id' => $staffRole])['code'] === 'not_found', 'unknown user -> not_found');
check($svc->update($id, ['name' => 'S', 'username' => 'staff', 'email' => 'stacy@example.com', 'role_id' => 99999])['code'] === 'invalid_role', 'unknown role -> invalid_role');
$self = (int) $w['admin']['id'];
check($svc->update($self, ['name' => 'Admin Renamed', 'username' => username_of('admin@example.com'), 'email' => 'admin@example.com', 'role_id' => role_id('Staff')])['code'] === 'self' && user_row($self)['role_name'] === 'Admin', 'you cannot change your own role');
check($svc->update($self, ['name' => 'Admin Renamed', 'username' => username_of('admin@example.com'), 'email' => 'admin@example.com', 'role_id' => role_id('Admin')])['ok'] && user_row($self)['name'] === 'Admin Renamed', 'you can edit your own name and email');

section('Users: activate, deactivate, reset password, unlock, delete');
$victim = make_user('Staff', 'victim@example.com', 'a long enough passphrase');
$vid = (int) $victim['id'];
check($svc->setActive($vid, false)['ok'] && (int) user_row($vid)['is_active'] === 0, 'deactivate');
$req = Request::make('POST', '/login', [], [], ['REMOTE_ADDR' => '203.0.113.77']);
check(!(new AuthService())->attempt('victim', 'a long enough passphrase', $req)['ok'], 'a deactivated user cannot sign in, even with the right password');
check($svc->setActive($vid, true)['ok'] && (new AuthService())->attempt('victim', 'a long enough passphrase', $req)['ok'], 'reactivating restores access');
check(audit_count('user_deactivated') === 1 && audit_count('user_activated') === 1, 'both are audited');
check($svc->setActive($self, false)['code'] === 'self' && (int) user_row($self)['is_active'] === 1, 'you cannot deactivate yourself');

$oldHash = user_row($vid)['password_hash'];
for ($i = 0; $i < 5; $i++) {
    (new AuthService())->attempt('victim', 'wrong-guess-' . $i, $req);
}
check(user_row($vid)['locked_until'] !== null, 'the account is locked after repeated failures');
check($svc->unlock($vid)['ok'] && user_row($vid)['locked_until'] === null && (int) user_row($vid)['failed_attempts'] === 0, 'unlock clears the lock and the failure counter');
check((new AuthService())->attempt('victim', 'a long enough passphrase', Request::make('POST', '/login', [], [], ['REMOTE_ADDR' => '203.0.113.78']))['ok'], '...so the user can sign in again');
check(audit_count('user_unlocked') === 1, 'unlocking is audited');

for ($i = 0; $i < 5; $i++) {
    (new AuthService())->attempt('victim', 'wrong-guess-' . $i, Request::make('POST', '/login', [], [], ['REMOTE_ADDR' => '203.0.113.79']));
}
$r = $svc->resetPassword($vid);
$v = user_row($vid);
check($r['ok'] && $v['password_hash'] !== $oldHash && password_verify($r['password'], $v['password_hash']) && (int) $v['must_change_password'] === 1, 'reset: new hash, forced change at next sign-in');
check($v['locked_until'] === null && (int) $v['failed_attempts'] === 0, '...and a locked account is unlocked');
check(PasswordPolicy::check($r['password']) === null && $r['username'] === 'victim', '...with a policy-compliant temporary password');
check(!secrets_leaked($r['password']) && audit_count('password_reset') === 1, 'the reset is audited without the password');
check(!(new AuthService())->attempt('victim', 'a long enough passphrase', Request::make('POST', '/login', [], [], ['REMOTE_ADDR' => '203.0.113.80']))['ok'], 'the old password stops working');
check($svc->resetPassword($self)['code'] === 'self', 'an admin cannot reset their own password here (My Account asks for the current one)');

check($svc->delete($self)['code'] === 'self' && user_row($self)['deleted_at'] === null, 'you cannot delete yourself');
$del = make_user('Staff', 'doomed@example.com');
$jobId = mk_job(['job_number' => 'DEL-1']);
Database::execute('UPDATE jobs SET created_by = ? WHERE id = ?', [$del['id'], $jobId]);
check($svc->delete((int) $del['id'])['ok'] && user_row((int) $del['id'])['deleted_at'] !== null, 'delete is a soft delete (the row stays)');
check((new UserRepository())->findById((int) $del['id']) === null && (new UserRepository())->findByUsername('doomed') === null, 'a deleted user is invisible to the app');
check(!(new AuthService())->attempt('doomed', 'correct horse battery', Request::make('POST', '/login', [], [], ['REMOTE_ADDR' => '203.0.113.81']))['ok'], 'a deleted user cannot sign in');
check($svc->create(['name' => 'Again', 'username' => 'again-user', 'email' => 'doomed@example.com', 'role_id' => $staffRole])['code'] === 'duplicate_email', 'the deleted user\'s email stays reserved');
check((int) Database::fetchValue('SELECT created_by FROM jobs WHERE id = ?', [$jobId]) === (int) $del['id'], 'their jobs keep pointing at them (history preserved)');
check($svc->delete((int) $del['id'])['code'] === 'not_found', 'deleting twice -> not_found');
check(audit_count('user_deleted') === 1, 'deletion is audited');

section('Users: list criteria');
$crit = static fn (array $q): UserCriteria => UserCriteria::fromRequest(Request::make('GET', '/users', [], $q));
check($crit([])->sort === 'name' && $crit([])->dir === 'asc' && $crit([])->status === '' && $crit([])->roleId === 0, 'defaults');
foreach (['id;DROP TABLE users', 'password_hash', 'u.id', ''] as $evil) {
    check($crit(['sort' => $evil])->sort === 'name', 'unknown sort "' . $evil . '" falls back to name');
}
check($crit(['status' => 'bogus'])->status === '' && $crit(['status' => 'locked'])->status === 'locked', 'status is one of a fixed set');
check($crit(['dir' => 'x', 'sort' => 'username'])->dir === 'asc' && $crit(['dir' => 'DESC', 'sort' => 'username'])->dir === 'desc', 'direction is asc/desc only');
check($crit(['q' => str_repeat('a', 300)])->q === str_repeat('a', 100) && $crit(['q' => ['x']])->q === '' && $crit(['page' => '-5'])->page === 1 && $crit(['role' => 'abc'])->roleId === 0, 'search text, page and role are sanitised');
foreach ([['sort' => 'name'], ['sort' => 'email', 'dir' => 'desc'], ['sort' => 'role'], ['sort' => 'last_login'], ['sort' => 'created']] as $q) {
    $rows = (new UserRepository())->search($crit($q), 100, 0);
    check(count($rows) === (new UserRepository())->count($crit($q)) && $rows !== [], 'sort by ' . $q['sort'] . ' works');
}
check(count((new UserRepository())->search($crit(['q' => 'nora']), 10, 0)) === 1 && count((new UserRepository())->search($crit(['q' => 'NORA@EXAMPLE']), 10, 0)) === 1, 'search by name or email, any case');
check((new UserRepository())->count($crit(['q' => '%'])) === 0 && (new UserRepository())->count($crit(['q' => "'; DROP TABLE users;--"])) === 0, 'LIKE wildcards and SQL text are inert');
check(count(array_filter((new UserRepository())->search($crit([]), 100, 0), static fn (array $r): bool => $r['email'] === 'doomed@example.com')) === 0, 'deleted users are not listed');

// ================================================================ role service

section('Roles: create, rename, delete');
$w = admin_world();
sign_in($w['admin']);
$roles = new RoleService();
check($roles->create('staff', null, null)['code'] === 'duplicate_name', 'role names are unique, case-insensitively');
$r = $roles->create('Night Dispatch', 'Dispatchers on the night shift', role_id('Staff'));
check($r['ok'] && role_perms('Night Dispatch') === role_perms('Staff'), 'a new role can start as a copy of another role');
check(audit_count('role_created') === 1, 'role creation is audited');
$r2 = $roles->create('Empty Role', null, null);
check($r2['ok'] && role_perms('Empty Role') === [], 'or start with no permissions');
check($roles->create('X', null, 99999)['code'] === 'not_found', 'copying from an unknown role fails');
check($roles->update(role_id('Staff'), 'Staff', 'Updated description')['ok'] && (new RoleRepository())->find(role_id('Staff'))['description'] === 'Updated description', 'a description can be edited');
check($roles->update(role_id('Admin'), 'Boss', null)['code'] === 'system_role' && (new RoleRepository())->find(role_id('Admin')) !== null, 'the Admin role cannot be renamed');
check($roles->update(role_id('Admin'), 'Admin', 'Everything')['ok'], '...but its description can be edited');
check($roles->update(role_id('Night Dispatch'), 'Staff', null)['code'] === 'duplicate_name', 'renaming to an existing role is refused');
check($roles->update(role_id('Night Dispatch'), 'Night Desk', 'x')['ok'] && audit_count('role_updated') === 3, 'rename works and is audited');
check($roles->update(999999, 'x', null)['code'] === 'not_found', 'unknown role -> not_found');
check($roles->delete(role_id('Admin'))['code'] === 'system_role', 'the Admin role cannot be deleted');
check($roles->delete(role_id('Staff'))['code'] === 'has_users' && $roles->delete(role_id('Staff'))['users'] === 1, 'a role with users cannot be deleted');
(new UserService())->delete((int) $w['staff']['id']);
check($roles->delete(role_id('Staff'))['code'] === 'has_users', 'deleted users still count (they keep a reference to the role)');
check($roles->delete(role_id('Night Desk'))['ok'] && (new RoleRepository())->findByName('Night Desk') === null, 'an unused role can be deleted');
check((int) Database::fetchValue("SELECT COUNT(*) FROM role_permissions WHERE role_id NOT IN (SELECT id FROM roles)") === 0, '...and takes its permission rows with it');
check(audit_count('role_deleted') === 1 && $roles->delete(999999)['code'] === 'not_found', 'deletion audited; unknown role -> not_found');

section('Roles: least privilege when copying');
make_role('RoleAdmin', ['roles.manage', 'jobs.view']);
$ra = make_user('RoleAdmin', 'roleadmin@example.com');
sign_in($ra);
$r = $roles->create('Copycat', null, role_id('Manager'));
check($r['ok'] && role_perms('Copycat') === ['jobs.view'], 'copying a role only copies the permissions the current user holds');

section('Roles: the permission matrix');
$w = admin_world();
sign_in($w['admin']);
$m = $roles->matrix();
check(count($m['roles']) === 4 && array_keys($m['groups']) !== [] && isset($m['groups']['Jobs']), 'matrix lists every role and permissions grouped by area');
$allPermIds = array_map('intval', array_column(array_merge(...array_values($m['groups'])), 'id'));
check(count($allPermIds) === count(Config::get('permissions.permissions')), 'every permission is in the matrix');
check($m['version'] === $roles->matrix()['version'], 'the version is stable');
$staff = role_id('Staff');
$post = static function (array $changes) use ($roles, $m): array {
    // posted state = current grants with the requested changes ([role name => [perm => bool]])
    $posted = [];
    foreach ($roles->matrix()['roles'] as $role) {
        $current = [];
        foreach ((new RoleRepository())->permissionKeys((int) $role['id']) as $p) {
            $current[$p] = true;
        }
        foreach ($changes[$role['name']] ?? [] as $p => $on) {
            $current[$p] = $on;
        }
        $posted[(int) $role['id']] = array_map('perm_id', array_keys(array_filter($current)));
    }
    return $posted;
};
$before = $roles->matrix()['version'];
$r = $roles->saveMatrix($post(['Staff' => ['jobs.view_financials' => true, 'jobs.create' => false]]), $before);
check($r['ok'] && $r['roles_changed'] === 1 && has_perm('Staff', 'jobs.view_financials') && !has_perm('Staff', 'jobs.create'), 'ticking and unticking boxes updates exactly those permissions');
check(has_perm('Staff', 'jobs.view') && count(role_perms('Manager')) === 16, 'other permissions and roles are untouched');
$a = Database::fetchOne("SELECT * FROM audit_logs WHERE action = 'role_permissions_changed' ORDER BY id DESC LIMIT 1");
check(str_contains((string) $a['new_values'], 'jobs.view_financials') && str_contains((string) $a['old_values'], 'jobs.create'), 'the audit entry lists what was added and removed');
check($roles->matrix()['version'] !== $before, 'the version changes when permissions change');
check($roles->saveMatrix($post([]), $before)['code'] === 'conflict', 'a stale matrix (someone else saved first) is refused');
check(has_perm('Staff', 'jobs.view_financials'), '...and changes nothing');
check($roles->saveMatrix($post([]), $roles->matrix()['version']) === ['ok' => true, 'roles_changed' => 0], 'saving without changes is a no-op');
$posted = $post([]);
$posted[role_id('Admin')] = [perm_id('jobs.view')]; // a forged attempt to strip the Admin role
$r = $roles->saveMatrix($posted, $roles->matrix()['version']);
check(count(role_perms('Admin')) === count(Config::get('permissions.permissions')), 'the Admin role can never be changed through the matrix, even by a forged post');
$posted = $post([]);
$posted[$staff][] = 999999;
$posted[$staff][] = 'junk';
$posted[424242] = [1, 2, 3];
check($roles->saveMatrix($posted, $roles->matrix()['version'])['ok'] && !in_array('junk', role_perms('Staff'), true), 'unknown permission ids and unknown roles are ignored');

section('Safeguards: matrix and the last administrator');
$w = admin_world();
make_role('CustomAdmin', ['users.manage', 'roles.manage', 'dashboard.view']);
$ca = make_user('CustomAdmin', 'ca@example.com');
Database::execute('UPDATE users SET is_active = 0 WHERE id = ?', [$w['admin']['id']]); // only CustomAdmin can administer now
sign_in($ca);
check(PermissionService::roleHas(role_id('CustomAdmin'), 'roles.manage') && (new UserRepository())->countAdministrators() === 1, 'precondition: CustomAdmin is the only administrator');
$posted = [];
foreach ($roles->matrix()['roles'] as $role) {
    $posted[(int) $role['id']] = array_map('perm_id', (new RoleRepository())->permissionKeys((int) $role['id']));
}
$posted[role_id('CustomAdmin')] = array_values(array_diff($posted[role_id('CustomAdmin')], [perm_id('users.manage')])); // untick users.manage on their own role
$r = $roles->saveMatrix($posted, $roles->matrix()['version']);
check(!$r['ok'] && $r['code'] === 'last_admin', 'removing users.manage from the only administrator role is refused');
check(has_perm('CustomAdmin', 'users.manage'), '...and rolled back completely');
$posted[role_id('CustomAdmin')] = array_values(array_diff($posted[role_id('CustomAdmin')], [perm_id('dashboard.view')]));
$posted[role_id('CustomAdmin')][] = perm_id('users.manage');
check($roles->saveMatrix($posted, $roles->matrix()['version'])['ok'] && !has_perm('CustomAdmin', 'dashboard.view') && has_perm('CustomAdmin', 'users.manage'), 'harmless changes to that same role are fine');

section('Safeguards: matrix respects what you hold');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'a2@example.com');
$stf = make_user('Staff', 'stf2@example.com');
make_role('RoleAdmin', ['roles.manage', 'jobs.view', 'dashboard.view']);
$ra = make_user('RoleAdmin', 'ra2@example.com');
sign_in($ra);
$view = $roles->matrix();
$editable = [];
foreach ($view['groups'] as $perms) {
    foreach ($perms as $p) {
        $editable[$p['name']] = $p['can_edit'];
    }
}
check($editable['jobs.view'] && $editable['roles.manage'] && !$editable['jobs.create'] && !$editable['users.manage'], 'the matrix marks permissions the user does not hold as not editable');
$posted = [];
foreach ($view['roles'] as $role) {
    $posted[(int) $role['id']] = array_map('perm_id', (new RoleRepository())->permissionKeys((int) $role['id']));
}
$posted[role_id('Staff')][] = perm_id('users.manage');                                    // try to grant a permission they lack
$posted[role_id('Staff')] = array_values(array_diff($posted[role_id('Staff')], [perm_id('jobs.create')])); // try to revoke one they lack
$posted[role_id('Staff')] = array_values(array_diff($posted[role_id('Staff')], [perm_id('jobs.view')]));   // revoke one they hold: allowed
$r = $roles->saveMatrix($posted, $view['version']);
check($r['ok'] && !has_perm('Staff', 'users.manage') && has_perm('Staff', 'jobs.create') && !has_perm('Staff', 'jobs.view'), 'cannot grant or revoke permissions they lack; can change ones they hold');
check(str_contains((string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'role_permissions_changed' ORDER BY id DESC LIMIT 1") . (string) Database::fetchValue("SELECT old_values FROM audit_logs WHERE action = 'role_permissions_changed' ORDER BY id DESC LIMIT 1"), 'jobs.view'), 'the change is audited');

// ================================================================ routes: the role x route sheet

section('Access control: every route, every role');
$w = admin_world();
$jobId = mk_job(['job_number' => 'SHEET-1']);
$sheetRoles = [
    'Admin' => role_id('Admin'), 'Manager' => role_id('Manager'), 'Staff' => role_id('Staff'),
    'UserAdmin' => role_id('UserAdmin'),
    'JobsViewOnly' => make_role('JobsViewOnly', ['jobs.view']) ? role_id('JobsViewOnly') : 0,
    'NoPermissions' => make_role('NoPermissions', []) ? role_id('NoPermissions') : 0,
];
$targets = ['jobs' => $jobId, 'users' => (int) $w['staff']['id'], 'roles' => role_id('Staff')];
$resolve = static function (string $pattern) use ($targets): ?string {
    $pattern = str_replace(['{format}', '{bucket}'], ['csv', 'all'], $pattern);
    $path = preg_replace_callback('/\{id:int\}/', static fn (): string => '@@', $pattern) ?? $pattern;
    if (str_contains($path, '@@')) {
        $kind = explode('/', trim($path, '/'))[0];
        if (!isset($targets[$kind])) {
            return null;
        }
        $path = str_replace('@@', (string) $targets[$kind], $path);
    }
    return $path;
};
$routes = real_router()->routes();
$tested = 0;
$bad = [];
foreach ($sheetRoles as $roleName => $rid) {
    fresh_session();
    $u = make_user($roleName, strtolower($roleName) . '-sheet@example.com');
    sign_in($u);
    foreach ($routes as $route) {
        if ($route->isPublic) {
            continue;
        }
        $path = $resolve($route->pattern);
        if ($path === null) {
            continue;
        }
        $allowed = $route->permission === null || PermissionService::roleHas($rid, $route->permission);
        // Import batches belong to the user who uploaded them, so for a permitted role "not found" is the correct answer here.
        $ownerScoped = str_starts_with($route->pattern, '/jobs/import/{id');
        if ($route->method === 'GET') {
            $status = call('GET', $path)->status();
            $ok = $allowed ? in_array($status, $ownerScoped ? [200, 302, 404] : [200, 302], true) : $status === 403;
        } else {
            if ($allowed) {
                continue; // allowed POSTs have their own behaviour (validation, redirects); here we prove the refusals
            }
            $status = call('POST', $path, [])->status();
            $ok = $status === 403;
        }
        $tested++;
        if (!$ok) {
            $bad[] = "{$roleName} {$route->method} {$path} -> {$status} (expected " . ($allowed ? '200/302' : '403') . ')';
        }
    }
}
check($tested > 150, "the sheet covered {$tested} role x route combinations");
check($bad === [], 'every role is allowed exactly what its permissions say, and refused everything else' . ($bad ? ' - problems: ' . implode('; ', array_slice($bad, 0, 5)) : ''));
fresh_session();
check(call('GET', '/users')->status() === 302 && call('GET', '/roles/matrix')->status() === 302 && call('POST', '/users/1/delete')->status() === 302 && real_router()->dispatch(Request::make('POST', '/users/1/delete'))->status() === 403, 'signed-out visitors cannot reach administration (redirected to sign in; a POST without a token is refused)');

// ================================================================ pages

section('Pages: navigation per role');
$w = admin_world();
$nav = static function (array $u): string {
    fresh_session();
    sign_in($u);
    $html = call('GET', '/account')->body();
    return plain((string) preg_replace('#.*<aside(.*?)</aside>.*#s', '$1', $html));
};
$adminNav = $nav($w['admin']);
check(str_contains($adminNav, 'Administration') && str_contains($adminNav, 'Users') && str_contains($adminNav, 'Roles & Permissions') && str_contains($adminNav, 'Jobs') && str_contains($adminNav, 'My Account'), 'Admin sees Users and Roles & Permissions under an Administration heading');
foreach (['manager', 'staff'] as $who) {
    $n = $nav($w[$who]);
    // A Manager may change the storage fee rate (settings.storage_fee), so sees that one entry under Administration; no other.
    check(!str_contains($n, 'Users') && !str_contains($n, 'Roles') && !str_contains($n, 'Audit Log') && !preg_match('/\\bSettings\\b/', $n) && str_contains($n, 'All Jobs') && (str_contains($n, 'Storage Fee') === ($who === 'manager')), ucfirst($who) . ' sees no administration entries except Storage Fee for a Manager');
}
$n = $nav($w['uadmin']);
check(str_contains($n, 'Users') && !str_contains($n, 'Roles & Permissions'), 'a user with only users.manage sees Users but not Roles');

section('Pages: user list');
$w = admin_world();
$repo = new UserRepository();
for ($i = 1; $i <= 30; $i++) {
    $repo->create(['name' => sprintf('Bulk User %02d', $i), 'username' => sprintf('bulk%02d', $i), 'email' => sprintf('bulk%02d@example.com', $i), 'password_hash' => 'x', 'role_id' => role_id('Staff')]);
}
$repo->create(['name' => '<script>alert(1)</script>', 'username' => username_of('xss@example.com'), 'email' => 'xss@example.com', 'password_hash' => 'x', 'role_id' => role_id('Staff')]);
$lockedId = $repo->create(['name' => 'Locked Larry', 'username' => username_of('larry@example.com'), 'email' => 'larry@example.com', 'password_hash' => 'x', 'role_id' => role_id('Staff')]);
Database::execute('UPDATE users SET locked_until = ? WHERE id = ?', ['2099-01-01 00:00:00', $lockedId]);
$inactiveId = $repo->create(['name' => 'Inactive Ivy', 'username' => username_of('ivy@example.com'), 'email' => 'ivy@example.com', 'password_hash' => 'x', 'role_id' => role_id('Manager'), 'is_active' => 0]);
fresh_session();
sign_in($w['admin']);
$r = call('GET', '/users');
$body = $r->body();
check($r->status() === 200 && preg_match('/<td(?![^>]*data-label)/', $body) === 0, 'list renders; every cell has a data-label (card layout on phones)');
check(str_contains($body, 'aria-label="Pagination"') && str_contains(plain($body), 'Showing 1–25 of 37'), 'paginated at 25 per page');
check(str_contains(plain(call('GET', '/users', [], ['page' => '2'])->body()), 'Showing 26–37 of 37'), 'page 2');
$both = $body . call('GET', '/users', [], ['page' => '2'])->body();
check(!str_contains($both, '<script>alert(1)') && str_contains($both, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'a hostile name is escaped');
$b = call('GET', '/users', [], ['q' => 'larry'])->body();
check(str_contains($b, 'Locked Larry') && !str_contains($b, 'Bulk User') && str_contains($b, '(filtered)'), 'search by name');
check(str_contains(call('GET', '/users', [], ['q' => 'bulk05@'])->body(), 'Bulk User 05'), 'search by email');
$b = call('GET', '/users', [], ['status' => 'locked'])->body();
check(str_contains($b, 'Locked Larry') && !str_contains($b, 'Bulk User 01') && str_contains($b, 'jt-badge-later'), 'status filter: locked (with a Locked badge)');
$b = call('GET', '/users', [], ['status' => 'inactive'])->body();
check(str_contains($b, 'Inactive Ivy') && !str_contains($b, 'Locked Larry'), 'status filter: inactive');
$b = call('GET', '/users', [], ['role' => (string) role_id('Manager')])->body();
check(str_contains($b, 'Inactive Ivy') && str_contains($b, 'manager@example.com') && !str_contains($b, 'Bulk User'), 'role filter');
check(str_contains(call('GET', '/users', [], ['sort' => 'username', 'dir' => 'desc'])->body(), 'sort=username&amp;dir=asc'), 'sorted header toggles direction');
check(str_contains(call('GET', '/users', [], ['q' => 'bulk', 'page' => '2'])->body(), 'q=bulk'), 'page links keep the search');
check(str_contains(call('GET', '/users', [], ['q' => 'nobody-xyz'])->body(), 'No users match your search'), 'friendly empty state');
check(call('GET', '/users', [], ['sort' => 'x;DROP', 'status' => 'zzz', 'role' => 'abc', 'page' => '-1'])->status() === 200, 'garbage parameters are harmless');
check(str_contains($body, 'you</span>'), 'your own row is marked "you"');
fresh_session();
sign_in($w['uadmin']);
$b = call('GET', '/users', [], ['q' => 'admin@example.com'])->body();
check(str_contains($b, 'Restricted') && !str_contains($b, '/users/' . $w['admin']['id'] . '/edit'), 'rows above your level show "Restricted" instead of an edit link');
check(call('GET', '/users/' . $w['admin']['id'] . '/edit')->status() === 403 && call('GET', '/users/' . $w['manager']['id'] . '/edit')->status() === 403, '...and their edit pages are refused');
check(call('GET', '/users/' . $w['staff']['id'] . '/edit')->status() === 200, 'but a Staff user can be edited');
$opts = plain((string) preg_replace('#.*<select[^>]*id="role_id"(.*?)</select>.*#s', '$1', call('GET', '/users/create')->body()));
check(str_contains($opts, 'Staff') && preg_match('/\bAdmin\b|\bManager\b/', $opts) === 0, 'the role dropdown offers only roles they may assign');

section('Pages: create, edit and account actions');
fresh_session();
sign_in($w['admin']);
$form = call('GET', '/users/create')->body();
check(str_contains($form, 'name="name"') && str_contains($form, 'name="email"') && str_contains($form, 'name="role_id"') && str_contains($form, 'temporary password is generated'), 'create form');
$r = call('POST', '/users', ['name' => '', 'email' => 'nope', 'role_id' => '']);
check($r->status() === 302 && str_ends_with((string) $r->getHeader('Location'), '/users/create') && isset($_SESSION['_flash_next']['errors']['name'], $_SESSION['_flash_next']['errors']['email'], $_SESSION['_flash_next']['errors']['role_id']), 'invalid input goes back with field errors');
$r = call('POST', '/users', ['name' => 'Dup', 'username' => username_of('STAFF@example.com'), 'email' => 'STAFF@example.com', 'role_id' => (string) role_id('Staff')]);
check(isset($_SESSION['_flash_next']['errors']['email']), 'duplicate email is a field error');
$r = call('POST', '/users', ['name' => 'Bad Role', 'username' => username_of('br@example.com'), 'email' => 'br@example.com', 'role_id' => '99999']);
check(isset($_SESSION['_flash_next']['errors']['role_id']), 'unknown role is a field error');
$r = call('POST', '/users', ['name' => '<b>Tricky</b> Tina', 'username' => username_of('tina@example.com'), 'email' => 'tina@example.com', 'role_id' => (string) role_id('Staff')]);
check($r->status() === 302 && preg_match('#/users/(\d+)/edit$#', (string) $r->getHeader('Location'), $m) === 1, 'a valid user is created and you land on their page');
$tinaId = (int) $m[1];
$secret = $_SESSION['_flash_next']['secret']['value'] ?? '';
check(strlen($secret) === 16, 'a temporary password was generated');
$page = call('GET', "/users/{$tinaId}/edit")->body();
check(str_contains($page, 'id="secretValue"') && str_contains($page, '>' . $secret . '<') && str_contains($page, 'Shown once'), 'the next page shows it once, with a Copy button');
check(str_contains($page, 'data-copy="#secretValue"') && str_contains($page, 'tina@example.com'), '...labelled with the account it belongs to');
$again = call('GET', "/users/{$tinaId}/edit")->body();
check(!str_contains($again, $secret) && !str_contains($again, 'secretValue'), 'reloading the page does not show it again');
check(!secrets_leaked($secret), 'it is nowhere in the audit log or the log files');
check(str_contains($page, '&lt;b&gt;Tricky&lt;/b&gt; Tina') && !str_contains($page, '<b>Tricky</b>'), 'the name is escaped');
check(str_contains($page, 'resetModal') && str_contains($page, 'deactivateModal') && str_contains($page, 'deleteUserModal') && str_contains($page, 'Failed attempts'), 'edit page: status panel and confirmation dialogs for the risky actions');
check(str_contains($page, 'Must be changed at next sign-in'), '...and shows the password must be changed');

// the new user's first sign-in: forced password change
$tina = user_row($tinaId);
fresh_session();
$res = (new AuthService())->attempt('tina', $secret, Request::make('POST', '/login', [], [], ['REMOTE_ADDR' => '203.0.113.90']));
check($res['ok'], 'the new user can sign in with the temporary password');
Auth::login($res['user']);
$r = call('GET', '/jobs');
check($r->status() === 302 && str_ends_with((string) $r->getHeader('Location'), '/account'), 'but every page redirects to My Account until the password is changed');
check(call('GET', '/account')->status() === 200 && str_contains(call('GET', '/account')->body(), 'You must choose a new password'), 'My Account explains why');
check(call('GET', '/users')->status() === 302, 'administration is out of reach as well');
call('POST', '/account/password', ['current_password' => $secret, 'password' => 'tina chose this passphrase', 'password_confirmation' => 'tina chose this passphrase']);
check((int) user_row($tinaId)['must_change_password'] === 0, 'choosing a new password clears the flag');
check(call('GET', '/jobs')->status() === 200, '...and the app opens up');
check(!(new AuthService())->attempt('tina', $secret, Request::make('POST', '/login', [], [], ['REMOTE_ADDR' => '203.0.113.91']))['ok'], 'the temporary password no longer works');

// editing through the route
fresh_session();
sign_in($w['admin']);
$edit = call('GET', "/users/{$tinaId}/edit")->body();
check(str_contains($edit, 'value="tina@example.com"') && preg_match('/<option value="\d+" selected>Staff/', $edit) === 1, 'edit form is prefilled');
$r = call('POST', "/users/{$tinaId}", ['name' => 'Tina T', 'username' => username_of('tina@example.com'), 'email' => 'tina@example.com', 'role_id' => (string) role_id('Manager')]);
check($r->status() === 302 && user_row($tinaId)['role_name'] === 'Manager' && user_row($tinaId)['name'] === 'Tina T', 'saving changes the user');
check(($_SESSION['_flash_next']['alert']['type'] ?? '') === 'success', '...with a success message');
$r = call('POST', "/users/{$tinaId}", ['name' => '', 'email' => 'not-an-email', 'role_id' => (string) role_id('Manager')]);
check(isset($_SESSION['_flash_next']['errors']['name'], $_SESSION['_flash_next']['errors']['email']), 'validation errors on edit');
$noToken = real_router()->dispatch(Request::make('POST', "/users/{$tinaId}/delete"));
check($noToken->status() === 403 && user_row($tinaId)['deleted_at'] === null, 'actions without a CSRF token are refused');
check(call('POST', '/users/999999/delete')->status() === 404 && call('GET', '/users/999999/edit')->status() === 404 && call('POST', '/users/999999', ['name' => 'x', 'email' => 'x@example.com', 'role_id' => '1'])->status() === 404, 'unknown users -> 404');

// actions on yourself, through the real routes
$aid = (int) $w['admin']['id'];
$self = call('GET', "/users/{$aid}/edit")->body();
check(str_contains($self, 'You cannot change your own role') && str_contains($self, 'disabled') && !str_contains($self, 'deleteUserModal'), 'your own edit page: role locked, no delete/deactivate buttons');
foreach (['deactivate', 'delete', 'reset-password'] as $action) {
    $r = call('POST', "/users/{$aid}/{$action}");
    check($r->status() === 302 && ($_SESSION['_flash_next']['alert']['type'] ?? '') === 'danger' && (int) user_row($aid)['is_active'] === 1 && user_row($aid)['deleted_at'] === null, "forged POST: you cannot {$action} yourself");
}
$r = call('POST', "/users/{$aid}", ['name' => 'Admin', 'username' => username_of('admin@example.com'), 'email' => 'admin@example.com', 'role_id' => (string) role_id('Staff')]);
check(user_row($aid)['role_name'] === 'Admin' && ($_SESSION['_flash_next']['alert']['type'] ?? '') === 'danger', 'forged POST: you cannot change your own role');

// reset, deactivate, unlock, delete through the routes
$u2 = make_user('Staff', 'actions@example.com', 'initial passphrase 123');
$id2 = (int) $u2['id'];
$r = call('POST', "/users/{$id2}/reset-password");
$s2 = $_SESSION['_flash_next']['secret']['value'] ?? '';
check($r->status() === 302 && strlen($s2) === 16 && (int) user_row($id2)['must_change_password'] === 1 && password_verify($s2, user_row($id2)['password_hash']), 'reset password through the route shows a one-time password');
$r = call('POST', "/users/{$id2}/deactivate");
check((int) user_row($id2)['is_active'] === 0 && str_contains(call('GET', '/users', [], ['status' => 'inactive'])->body(), 'actions@example.com'), 'deactivate through the route; the user appears under Inactive');
check(str_contains(plain(call('GET', "/users/{$id2}/edit")->body()), 'Activate'), 'the edit page then offers Activate');
call('POST', "/users/{$id2}/activate");
check((int) user_row($id2)['is_active'] === 1, 'activate through the route');
Database::execute('UPDATE users SET locked_until = ?, failed_attempts = 5 WHERE id = ?', ['2099-01-01 00:00:00', $id2]);
check(str_contains(plain(call('GET', "/users/{$id2}/edit")->body()), 'Unlock account'), 'a locked user\'s page offers Unlock');
call('POST', "/users/{$id2}/unlock");
check(user_row($id2)['locked_until'] === null && !str_contains(plain(call('GET', "/users/{$id2}/edit")->body()), 'Unlock account'), 'unlock through the route');
$r = call('POST', "/users/{$id2}/delete");
check($r->status() === 302 && str_ends_with((string) $r->getHeader('Location'), '/users') && user_row($id2)['deleted_at'] !== null && call('GET', "/users/{$id2}/edit")->status() === 404, 'delete through the route');

section('Pages: a deactivated or demoted user is cut off on the very next request');
$w = admin_world();
fresh_session();
sign_in($w['manager']);
check(call('GET', '/jobs')->status() === 200 && call('GET', '/users')->status() === 403, 'baseline: Manager sees jobs, not user administration');
Database::execute('UPDATE users SET is_active = 0 WHERE id = ?', [$w['manager']['id']]);
check(call('GET', '/jobs')->status() === 302, 'deactivating the user ends their session at once');
fresh_session();
sign_in($w['staff']);
check(call('GET', '/jobs/create')->status() === 200, 'Staff can open the new-job form');
Database::execute('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?', [role_id('Staff'), perm_id('jobs.create')]);
check(call('GET', '/jobs/create')->status() === 403, 'revoking jobs.create blocks it on the next request (no re-login)');
Database::execute('UPDATE users SET role_id = ? WHERE id = ?', [role_id('Manager'), $w['staff']['id']]);
check(call('GET', '/jobs/create')->status() === 200, 'changing the user\'s role takes effect on the next request too');

section('Pages: roles and the matrix');
$w = admin_world();
fresh_session();
sign_in($w['admin']);
$body = call('GET', '/roles')->body();
check(str_contains($body, 'built in') && str_contains($body, 'Manager') && str_contains($body, 'UserAdmin') && preg_match('/<td(?![^>]*data-label)/', $body) === 0, 'role list with counts and the built-in badge');
check(!str_contains($body, 'deleteRole' . role_id('Admin')) && str_contains($body, 'deleteRole' . role_id('Staff')), 'the built-in role has no delete button; others do');
check(str_contains($body, '/roles/matrix') && str_contains(call('GET', '/roles/create')->body(), 'Start from'), 'links to the matrix; the create form can copy a role');
$r = call('POST', '/roles', ['name' => '<b>Evil</b>', 'description' => 'x']);
check(isset($_SESSION['_flash_next']['errors']['name']), 'role names are restricted to readable characters');
$r = call('POST', '/roles', ['name' => 'manager', 'description' => 'dup']);
check(isset($_SESSION['_flash_next']['errors']['name']), 'duplicate role names are a field error (any case)');
$r = call('POST', '/roles', ['name' => 'Auditors', 'description' => 'Read-only', 'copy_from' => (string) role_id('Staff')]);
check($r->status() === 302 && str_ends_with((string) $r->getHeader('Location'), '/roles/matrix') && role_perms('Auditors') === role_perms('Staff'), 'creating a role (copied from Staff) sends you to the matrix');
$rid = role_id('Auditors');
check(str_contains(call('GET', "/roles/{$rid}/edit")->body(), 'value="Auditors"') && str_contains(call('GET', '/roles/' . role_id('Admin') . '/edit')->body(), 'readonly'), 'edit form; the Admin name field is read-only');
call('POST', "/roles/{$rid}", ['name' => 'Auditors Team', 'description' => 'Updated']);
check((new RoleRepository())->find($rid)['name'] === 'Auditors Team', 'renaming through the route');
$r = call('POST', '/roles/' . role_id('Admin') . '/delete');
check(($_SESSION['_flash_next']['alert']['type'] ?? '') === 'danger' && (new RoleRepository())->find(role_id('Admin')) !== null, 'the Admin role cannot be deleted (flash message)');
$r = call('POST', '/roles/' . role_id('Staff') . '/delete');
check(str_contains((string) ($_SESSION['_flash_next']['alert']['message'] ?? ''), 'still has') && (new RoleRepository())->find(role_id('Staff')) !== null, 'a role with users cannot be deleted');
$r = call('POST', "/roles/{$rid}/delete");
check((new RoleRepository())->find($rid) === null && ($_SESSION['_flash_next']['alert']['type'] ?? '') === 'success', 'an unused role is deleted');
check(call('GET', '/roles/999999/edit')->status() === 404 && call('POST', '/roles/999999/delete')->status() === 404, 'unknown roles -> 404');
$noToken = real_router()->dispatch(Request::make('POST', '/roles/' . role_id('UserAdmin') . '/delete'));
check($noToken->status() === 403 && (new RoleRepository())->find(role_id('UserAdmin')) !== null, 'deleting a role needs a CSRF token');

$matrix = call('GET', '/roles/matrix')->body();
$perms = count(Config::get('permissions.permissions'));
check(str_contains($matrix, 'jt-matrix') && str_contains($matrix, 'table-responsive') && str_contains($matrix, 'name="version"'), 'matrix page: responsive wrapper and a version field');
check(str_contains($matrix, 'colgroup') && str_contains(plain($matrix), 'Jobs') && str_contains(plain($matrix), 'Administration') && str_contains(plain($matrix), 'Dashboard'), 'permissions grouped by area');
preg_match_all('/<input class="form-check-input" type="checkbox"[^>]*>/', $matrix, $boxes);
$boxes = $boxes[0];
$expectedBoxes = $perms * 4;
$checked = count(array_filter($boxes, static fn (string $b): bool => str_contains($b, ' checked')));
$expectedChecked = $perms; // the Admin column
foreach (['Manager', 'Staff', 'UserAdmin'] as $rn) {
    $expectedChecked += count(role_perms($rn));
}
check(count($boxes) === $expectedBoxes && $checked === $expectedChecked, "the grid has {$expectedBoxes} checkboxes and the ticks match the database ({$expectedChecked})");
$adminBoxes = array_filter($boxes, static fn (string $b): bool => str_contains($b, 'name="perm[' . role_id('Admin') . '][]"'));
check(count($adminBoxes) === $perms && count(array_filter($adminBoxes, static fn (string $b): bool => str_contains($b, ' checked') && str_contains($b, 'disabled'))) === $perms, 'the Admin column is all ticked and locked');

preg_match('/name="version" value="([^"]+)"/', $matrix, $vm);
$post = ['version' => $vm[1], 'perm' => []];
foreach ((new RoleService())->matrix()['roles'] as $role) {
    if ((int) $role['is_system'] === 1) {
        continue;
    }
    $post['perm'][(int) $role['id']] = array_map('perm_id', (new RoleRepository())->permissionKeys((int) $role['id']));
}
$post['perm'][role_id('Staff')][] = perm_id('jobs.import');
$r = call('POST', '/roles/matrix', $post);
check($r->status() === 302 && has_perm('Staff', 'jobs.import') && str_contains((string) ($_SESSION['_flash_next']['alert']['message'] ?? ''), '1 role'), 'saving the matrix through the route applies the change');
$r = call('POST', '/roles/matrix', $post); // same stale version again
check(str_contains((string) ($_SESSION['_flash_next']['alert']['message'] ?? ''), 'Someone else changed') , 'a stale matrix is refused with a warning');
check(call('POST', '/roles/matrix', ['version' => 'x'])->status() === 302 && has_perm('Staff', 'jobs.import'), 'a malformed post changes nothing');
$noToken = real_router()->dispatch(Request::make('POST', '/roles/matrix', $post));
check($noToken->status() === 403, 'the matrix needs a CSRF token');
fresh_session();
sign_in($w['staff']);
check(call('GET', '/roles/matrix')->status() === 403 && call('POST', '/roles/matrix', $post)->status() === 403 && !has_perm('Staff', 'jobs.edit_financials'), 'Staff cannot see or save the matrix, and cannot promote themselves');

section('Pages: My Account (name)');
$w = admin_world();
fresh_session();
sign_in($w['staff']);
check(str_contains(call('GET', '/account')->body(), 'name="name"') && str_contains(call('GET', '/account')->body(), 'Save name'), 'My Account has a name field');
$r = call('POST', '/account/profile', ['name' => 'New Staff Name']);
check($r->status() === 302 && user_row((int) $w['staff']['id'])['name'] === 'New Staff Name', 'a user can change their own name');
check(audit_count('profile_updated') === 1, '...and it is audited');
call('POST', '/account/profile', ['name' => '']);
check(isset($_SESSION['_flash_next']['errors']['name']) && user_row((int) $w['staff']['id'])['name'] === 'New Staff Name', 'an empty name is refused');
call('POST', '/account/profile', ['name' => '<script>alert(1)</script>']);
check(!str_contains(call('GET', '/dashboard')->body(), '<script>alert(1)') && str_contains(call('GET', '/dashboard')->body(), '&lt;script&gt;alert(1)'), 'a hostile name is escaped in the page header');
call('POST', '/account/profile', ['name' => 'Hacker', 'email' => 'steal@example.com', 'role_id' => (string) role_id('Admin')]);
check(user_row((int) $w['staff']['id'])['email'] === 'staff@example.com' && user_row((int) $w['staff']['id'])['role_name'] === 'Staff', 'forged email/role fields on the profile form are ignored');

Clock::fake(null);
