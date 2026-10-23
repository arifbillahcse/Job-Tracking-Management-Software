<?php

declare(strict_types=1);

/**
 * Dependency-free test runner:  php tests/run.php
 *
 * Runs against an in-memory SQLite database built from the real migrations and
 * seeder (MySQL is the production target; SQLite is the test stand-in).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('APP_ENV=local');

ob_start(); // sessions are started in CLI; swallow header output

ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');
ini_set('session.save_path', sys_get_temp_dir());

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/support.php';

use App\Controllers\AuthController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Validator;
use App\Database\Migrator;
use App\Database\Seeder;
use App\Database\SqliteCompat;
use App\Helpers\PasswordPolicy;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Repositories\AuditLogRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\PermissionService;

// ---------------------------------------------------------------- fixtures

final class ProbeController extends Controller
{
    public function ok(Request $request): Response
    {
        return new Response('ok');
    }

    public function item(Request $request, int $id): Response
    {
        return new Response('item:' . $id . ':' . gettype($id));
    }
}

function fresh_db(): void
{
    Config::set('app.db.driver', 'sqlite');
    Config::set('app.db.database', ':memory:');
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    Database::setConnection($pdo);
    (new Migrator())->migrate();
    (new Seeder())->run();
    PermissionService::flush();
    Auth::reset();
}

function fresh_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    session_id(bin2hex(random_bytes(16)));
    session_start();
    $_SESSION = [];
    $_SESSION['_created'] = time();
    $_SESSION['_last'] = time();
    Auth::reset();
}

function make_user(string $role, string $email = null, string $password = 'correct horse battery'): array
{
    $email ??= strtolower($role) . '-' . bin2hex(random_bytes(3)) . '@example.com';
    $roleRow = (new RoleRepository())->findByName($role);
    $id = (new UserRepository())->create([
        'name' => $role . ' User',
        'email' => $email,
        'password_hash' => PasswordPolicy::hash($password),
        'role_id' => (int) $roleRow['id'],
    ]);
    return (new UserRepository())->findById($id);
}

function sign_in(array $user): void
{
    Auth::login($user);
}

function app_router(): Router
{
    $router = new Router();
    $router->middleware(SecurityHeadersMiddleware::class, CsrfMiddleware::class);
    $router->get('/public', [ProbeController::class, 'ok'])->public();
    $router->get('/auth-only', [ProbeController::class, 'ok'])->authOnly();
    $router->get('/admin-area', [ProbeController::class, 'ok'])->can('users.manage');
    $router->get('/jobs/{id:int}', [ProbeController::class, 'item'])->can('jobs.view');
    $router->post('/change', [ProbeController::class, 'ok'])->can('jobs.edit');
    $router->get('/undeclared', [ProbeController::class, 'ok']);
    $router->group(['prefix' => '/grp', 'middleware' => [GuestMiddleware::class]], static function (Router $r): void {
        $r->get('/page', [ProbeController::class, 'ok'])->public();
    });
    return $router;
}

function dispatch(string $method, string $path, array $post = [], array $server = []): Response
{
    return app_router()->dispatch(Request::make($method, $path, $post, [], $server));
}

// ---------------------------------------------------------------- tests

fresh_db();
fresh_session();

section('Env parser');
$parsed = Env::parse("# c\nA=1\nB=\"quoted value\"\nC='single' \nD=plain # trailing\nexport E=exp\nbad line\n");
check($parsed['A'] === '1' && $parsed['B'] === 'quoted value' && $parsed['C'] === 'single', 'parses plain and quoted values');
check($parsed['D'] === 'plain' && $parsed['E'] === 'exp', 'strips inline comments and export prefix');

section('Validator');
$v = Validator::make(['name' => '  ', 'email' => 'nope'], ['name' => 'required', 'email' => 'required|email']);
check($v->fails() && isset($v->errors()['name'], $v->errors()['email']), 'required and email failures reported per field');
$v = Validator::make(['d' => '2026-02-30'], ['d' => 'date']);
check($v->fails(), 'impossible date 2026-02-30 rejected');
$v = Validator::make(['d' => '2026-02-28', 'n' => '12.50', 'i' => '7', 'x' => 'b'], ['d' => 'date', 'n' => 'numeric|min_value:0', 'i' => 'integer', 'x' => 'in:a,b']);
check($v->passes(), 'valid date, numeric, integer and in pass');
$v = Validator::make(['n' => '-1'], ['n' => 'numeric|min_value:0']);
check($v->fails(), 'min_value rejects negatives');
$v = Validator::make(['t' => str_repeat('a', 101)], ['t' => 'max:100']);
check($v->fails(), 'max length enforced');
$v = Validator::make(['p' => 'abc'], ['p' => 'phone']);
check($v->fails(), 'phone rejects too-short values');
$v = Validator::make(['p' => '+1 (555) 123-4567'], ['p' => 'phone']);
check($v->passes(), 'phone accepts common formats');
$v = Validator::make(['x' => ' padded '], ['x' => 'required|max:20']);
check($v->validated()['x'] === 'padded', 'validated() trims strings');
$v = Validator::make(['p' => 'abc', 'p_confirmation' => 'abd'], ['p' => 'confirmed']);
check($v->fails(), 'confirmed detects mismatch');
$u = make_user('Staff', 'dupe@example.com');
$v = Validator::make(['email' => 'dupe@example.com'], ['email' => 'unique:users,email']);
check($v->fails(), 'unique detects an existing value');
$v = Validator::make(['email' => 'dupe@example.com'], ['email' => 'unique:users,email,' . $u['id']]);
check($v->passes(), 'unique ignores the record being edited');
throws(static fn () => Validator::make(['a' => 'x'], ['a' => 'unique:users;DROP TABLE users,email']), 'unique rejects unsafe identifiers');
throws(static fn () => Validator::make(['a' => 'x'], ['a' => 'bogus']), 'unknown rule is a programming error, not a silent pass');

section('Password policy');
check(PasswordPolicy::check('short') !== null, 'rejects passwords under 10 characters');
check(PasswordPolicy::check('password123') !== null, 'rejects common passwords');
check(PasswordPolicy::check('aaaaaaaaaaaa') !== null, 'rejects repetitive passwords');
check(PasswordPolicy::check('correct horse battery') === null, 'accepts a long passphrase');
$hash = PasswordPolicy::hash('correct horse battery');
check(password_verify('correct horse battery', $hash) && str_starts_with($hash, '$argon2id$'), 'hashes with Argon2id');
check(strlen(PasswordPolicy::generate()) === 20, 'generates 20-character passwords');

section('Internal path helper / redirects');
check(is_internal_path('/dashboard?x=1') && is_internal_path('/'), 'accepts internal paths');
check(!is_internal_path('//evil.com') && !is_internal_path('http://evil.com') && !is_internal_path('/\\evil.com') && !is_internal_path("/a\r\nb") && !is_internal_path(''), 'rejects external, protocol-relative and control-character paths');
throws(static fn () => Response::redirect('https://evil.com'), 'Response::redirect refuses external URLs');

section('Router');
check(dispatch('GET', '/public')->status() === 200, 'public route served');
check(dispatch('GET', '/nope')->status() === 404, 'unknown path -> 404');
$r = dispatch('GET', '/public-but-wrong-method-test');
check($r->status() === 404, 'unknown path with any method -> 404');
$r = dispatch('POST', '/public', ['_csrf' => 'x']);
check($r->status() === 403, 'POST without a valid token is stopped before reaching a missing route');
check(dispatch('GET', '/grp/page')->status() === 200, 'group prefix + group middleware applied (guest may see it)');
throws(static fn () => dispatch('GET', '/undeclared'), 'route without an access rule is refused (deny by default)');
$_SESSION['_csrf'] = 'tok';
$r = dispatch('POST', '/public', ['_csrf' => 'tok']);
check($r->status() === 405 && $r->getHeader('Allow') === 'GET', 'known path with the wrong method -> 405 with Allow header');
$u = make_user('Admin');
sign_in($u);
$r = dispatch('GET', '/jobs/42');
check($r->body() === 'item:42:integer', '{id:int} parameter is cast to int');
check(dispatch('GET', '/jobs/abc')->status() === 404, '{id:int} rejects non-numeric segments');

section('Security headers');
$r = dispatch('GET', '/public');
foreach (['Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy', 'Cache-Control'] as $h) {
    check($r->hasHeader($h), "response carries {$h}");
}
check(str_contains((string) $r->getHeader('Content-Security-Policy'), "frame-ancestors 'none'"), 'CSP forbids framing');
check(dispatch('GET', '/nope')->hasHeader('Content-Security-Policy'), 'error pages carry security headers too');

section('CSRF');
fresh_session();
$token = Csrf::token();
check(strlen($token) === 64, 'token is 256-bit hex');
$u = make_user('Manager');
sign_in($u);
$token = Csrf::token();
check(dispatch('POST', '/change')->status() === 403, 'POST with no token -> 403');
check(dispatch('POST', '/change', ['_csrf' => 'wrong'])->status() === 403, 'POST with a wrong token -> 403');
check(dispatch('POST', '/change', ['_csrf' => $token])->status() === 200, 'POST with the right token passes');
check(dispatch('POST', '/change', [], ['HTTP_X_CSRF_TOKEN' => $token])->status() === 200, 'token accepted from the X-CSRF-Token header (AJAX)');
check(dispatch('POST', '/change', ['_csrf' => $token], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => 'app.example'])->status() === 403, 'cross-origin POST rejected even with a token');
check(dispatch('POST', '/change', ['_csrf' => $token], ['HTTP_ORIGIN' => 'https://app.example', 'HTTP_HOST' => 'app.example'])->status() === 200, 'same-origin POST accepted');
check(dispatch('GET', '/public')->status() === 200, 'GET needs no token');

section('Seeded roles and permissions');
fresh_db();
$total = (int) Database::fetchValue('SELECT COUNT(*) FROM permissions');
$adminId = (int) (new RoleRepository())->findByName('Admin')['id'];
$staffId = (int) (new RoleRepository())->findByName('Staff')['id'];
$managerId = (int) (new RoleRepository())->findByName('Manager')['id'];
check(count(PermissionService::forRole($adminId)) === $total, 'Admin holds every permission');
check(!PermissionService::roleHas($staffId, 'jobs.view_financials') && PermissionService::roleHas($staffId, 'jobs.create'), 'Staff: no financials, can create jobs');
check(PermissionService::roleHas($managerId, 'jobs.edit_financials') && !PermissionService::roleHas($managerId, 'users.manage'), 'Manager: financials yes, user management no');
Database::execute('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = (SELECT id FROM permissions WHERE name = ?)', [$staffId, 'jobs.create']);
(new Seeder())->run();
PermissionService::flush();
check(!PermissionService::roleHas($staffId, 'jobs.create'), 'seeding again does not overwrite a customised non-Admin role');
Database::execute('DELETE FROM role_permissions WHERE role_id = ?', [$adminId]);
(new Seeder())->run();
PermissionService::flush();
check(count(PermissionService::forRole($adminId)) === $total, 'seeding re-grants Admin everything');
$again = (new Seeder())->run();
check(array_sum($again) === 0, 'seeder is idempotent');
check(setting('ready_threshold_days') === '30', 'ready threshold seeded at 30 days');

section('Authorization middleware');
fresh_db();
fresh_session();
$r = dispatch('GET', '/auth-only');
check($r->status() === 302 && str_ends_with((string) $r->getHeader('Location'), '/login'), 'guest is redirected to /login');
check(($_SESSION['intended'] ?? null) === '/auth-only', 'intended URL remembered for after login');
$json = app_router()->dispatch(Request::make('GET', '/auth-only', [], [], ['HTTP_ACCEPT' => 'application/json']));
check($json->status() === 401, 'JSON clients get 401 instead of a redirect');

$staff = make_user('Staff');
sign_in($staff);
check(dispatch('GET', '/auth-only')->status() === 200, 'signed-in user reaches an authOnly route');
check(dispatch('GET', '/admin-area')->status() === 403, 'Staff blocked (403) from a route needing users.manage');
check(dispatch('GET', '/jobs/1')->status() === 200, 'Staff allowed on a route needing jobs.view');
$token = Csrf::token();
check(dispatch('POST', '/change', ['_csrf' => $token])->status() === 200, 'Staff allowed to POST where jobs.edit is held');
$denied = (int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'access_denied'");
check($denied === 1, 'denied access is written to the audit log');

fresh_session();
$admin = make_user('Admin');
sign_in($admin);
check(dispatch('GET', '/admin-area')->status() === 200, 'Admin passes the same permission check');
check(dispatch('GET', '/grp/page')->status() === 302, 'signed-in user is bounced off guest-only pages');

section('Immediate effect of permission / account changes');
fresh_session();
$staff = make_user('Staff');
sign_in($staff);
check(dispatch('GET', '/jobs/1')->status() === 200, 'permission held');
Database::execute('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = (SELECT id FROM permissions WHERE name = ?)', [(int) $staff['role_id'], 'jobs.view']);
PermissionService::flush();
Auth::reset();
check(dispatch('GET', '/jobs/1')->status() === 403, 'revoked permission blocks the very next request (no re-login needed)');
Database::execute('UPDATE users SET is_active = 0 WHERE id = ?', [$staff['id']]);
Auth::reset();
check(dispatch('GET', '/auth-only')->status() === 302, 'deactivated user loses the session on the next request');

section('Authentication service');
fresh_db();
fresh_session();
$svc = new AuthService();
$req = Request::make('POST', '/login', [], [], ['REMOTE_ADDR' => '203.0.113.9']);
$victim = make_user('Staff', 'victim@example.com', 'a long enough passphrase');

$unknown = $svc->attempt('ghost@example.com', 'whatever-123', $req);
$wrong = $svc->attempt('victim@example.com', 'wrong-password-1', $req);
check(!$unknown['ok'] && !$wrong['ok'] && $unknown['error'] === $wrong['error'], 'unknown email and wrong password give the identical message');

for ($i = 0; $i < 3; $i++) {
    $svc->attempt('victim@example.com', 'wrong-password-' . $i, $req);
}
$state = Database::fetchOne('SELECT failed_attempts, locked_until FROM users WHERE email = ?', ['victim@example.com']);
check((int) $state['failed_attempts'] === 4 && $state['locked_until'] === null, 'failures are counted before the threshold');
$svc->attempt('victim@example.com', 'wrong-password-x', $req);
$state = Database::fetchOne('SELECT failed_attempts, locked_until FROM users WHERE email = ?', ['victim@example.com']);
check($state['locked_until'] !== null, 'account is locked after 5 failures');
$locked = $svc->attempt('victim@example.com', 'a long enough passphrase', $req);
check(!$locked['ok'] && $locked['error'] === AuthService::MSG_LOCKED, 'correct password is refused while locked');
check((int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'account_locked'") === 1, 'lockout is audited');

// Unknown emails lock out identically, so "locked" never reveals that an account exists.
$other = Request::make('POST', '/login', [], [], ['REMOTE_ADDR' => '203.0.113.50']);
$msg = '';
for ($i = 0; $i < 6; $i++) {
    $msg = $svc->attempt('nobody@example.com', 'guess-' . $i . '-xxxx', $other)['error'];
}
check($msg === AuthService::MSG_LOCKED, 'unknown emails hit the same lockout (no account enumeration)');

(new UserRepository())->clearLock((int) $victim['id']);
Database::execute('DELETE FROM login_attempts');
$ok = $svc->attempt('victim@example.com', 'a long enough passphrase', $req);
check($ok['ok'] && $ok['user']['email'] === 'victim@example.com', 'valid credentials succeed once unlocked');
check((int) Database::fetchValue('SELECT failed_attempts FROM users WHERE id = ?', [$victim['id']]) === 0, 'success resets the failure counter');
check(Database::fetchValue('SELECT last_login_at FROM users WHERE id = ?', [$victim['id']]) !== null, 'last login recorded');

$inactive = make_user('Staff', 'inactive@example.com', 'another long passphrase');
Database::execute('UPDATE users SET is_active = 0 WHERE id = ?', [$inactive['id']]);
$res = $svc->attempt('inactive@example.com', 'another long passphrase', $req);
check(!$res['ok'] && $res['error'] === AuthService::MSG_INVALID, 'inactive account gets the same generic error even with the right password');

$legacy = make_user('Staff', 'legacy@example.com', 'legacy long passphrase');
Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('legacy long passphrase', PASSWORD_BCRYPT, ['cost' => 4]), $legacy['id']]);
$res = $svc->attempt('legacy@example.com', 'legacy long passphrase', $req);
check($res['ok'] && str_starts_with((string) Database::fetchValue('SELECT password_hash FROM users WHERE id = ?', [$legacy['id']]), '$argon2id$'), 'old hashes are upgraded to Argon2id on login');

// Per-IP throttle across many accounts.
Database::execute('DELETE FROM login_attempts');
$ipReq = Request::make('POST', '/login', [], [], ['REMOTE_ADDR' => '198.51.100.7']);
for ($i = 0; $i < 20; $i++) {
    $svc->attempt("spray{$i}@example.com", 'password-guess-1', $ipReq);
}
$res = $svc->attempt('victim@example.com', 'a long enough passphrase', $ipReq);
check(!$res['ok'] && $res['error'] === AuthService::MSG_LOCKED, 'an IP spraying many accounts is throttled');

section('Sessions / Auth state');
fresh_db();
fresh_session();
$u = make_user('Admin', 'sess@example.com', 'a session passphrase');
$before = session_id();
sign_in($u);
check(session_id() !== $before, 'session id regenerated on login (fixation defence)');
check(Auth::check() && Auth::id() === (int) $u['id'] && Auth::can('users.manage'), 'login establishes identity and permissions');

$oldMarker = $_SESSION['pw_marker'];
$newHash = PasswordPolicy::hash('brand new passphrase');
(new UserRepository())->updatePassword((int) $u['id'], $newHash);
Auth::reset();
check(!Auth::check(), 'password change signs out other sessions holding the old marker');

fresh_session();
sign_in((new UserRepository())->findById((int) $u['id']));
$h2 = PasswordPolicy::hash('yet another passphrase');
(new UserRepository())->updatePassword((int) $u['id'], $h2);
Auth::refreshPasswordMarker($h2);
check(Auth::check(), 'the session that changed the password stays signed in');

Auth::logout();
check(!Auth::check() && !isset($_SESSION['user_id']), 'logout clears the session');

section('Forced password change');
fresh_db();
fresh_session();
$f = make_user('Admin', 'forced@example.com', 'forced long passphrase');
Database::execute('UPDATE users SET must_change_password = 1 WHERE id = ?', [$f['id']]);
sign_in((new UserRepository())->findById((int) $f['id']));
$r = dispatch('GET', '/auth-only');
check($r->status() === 302 && str_ends_with((string) $r->getHeader('Location'), '/account'), 'flagged user is sent to the password page');

section('Audit service');
fresh_db();
AuditService::record('probe', 'user', 5, ['password' => 'secret', 'name' => 'a'], ['token' => 'abc', 'nested' => ['password_hash' => 'x', 'ok' => 1]], null);
$row = (new AuditLogRepository())->latest(1)[0];
check(!str_contains((string) $row['old_values'], 'secret') && str_contains((string) $row['old_values'], '[redacted]'), 'passwords never reach the audit log');
check(!str_contains((string) $row['new_values'], 'abc') && str_contains((string) $row['new_values'], '"ok":1'), 'tokens and nested secrets are redacted, normal values kept');

section('Migrations');
check(Migrator::statements("-- c\nCREATE TABLE a (x INT);\n\nCREATE INDEX i ON a (x);\n") === ['CREATE TABLE a (x INT)', 'CREATE INDEX i ON a (x)'], 'statement splitter drops comments and splits on ;');
$translated = SqliteCompat::translate("CREATE TABLE t (\n id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
check(str_contains($translated, 'INTEGER PRIMARY KEY AUTOINCREMENT') && !str_contains($translated, 'ENGINE'), 'SQLite shim translates AUTO_INCREMENT and table options');
check((new Migrator())->migrate() === [], 'running migrations twice applies nothing new');
Database::connect()->exec('DROP TABLE settings');
Database::connect()->exec("DELETE FROM migrations WHERE filename LIKE '004%'");
check((new Migrator())->migrate() === ['004_create_settings.sql'], 'a missing migration is re-applied on its own');

section('Safe output helper');
check(e('<script>alert("x")</script>\'') === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&#039;', 'e() escapes HTML and quotes');

finish();
