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
use App\Helpers\Paginator;
use App\Helpers\PasswordPolicy;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Repositories\AuditLogRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Repositories\JobRepository;
use App\Services\AuthService;
use App\Services\JobService;
use App\Validation\JobRules;
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


function real_router(): Router
{
    $router = new Router();
    $router->middleware(SecurityHeadersMiddleware::class, CsrfMiddleware::class);
    (require BASE_PATH . '/app/Config/routes.php')($router);
    return $router;
}

/**
 * Call the real application routes as the currently signed-in user, as one fresh HTTP request:
 * flash data from the previous request becomes readable and older flash data is dropped, like Session::start() does.
 * POSTs get a valid CSRF token.
 */
function call(string $method, string $path, array $post = [], array $query = []): Response
{
    if (strtoupper($method) === 'POST') {
        $post += ['_csrf' => Csrf::token()];
    }
    next_request();
    $_GET = $query;
    Auth::reset();
    return real_router()->dispatch(Request::make($method, $path, $post, $query));
}

/** Simulate the next page load: flash data set before a redirect becomes readable. */
function next_request(): void
{
    $_SESSION['_flash'] = $_SESSION['_flash_next'] ?? [];
    unset($_SESSION['_flash_next']);
}

function job_input(array $override = []): array
{
    return $override + [
        'job_number' => 'T-' . bin2hex(random_bytes(3)),
        'customer_first_name' => 'Ada',
        'customer_last_name' => 'Smith',
        'pickup_date' => '2026-11-01',
        'first_available_date' => '2026-12-01',
        'from_location' => 'Austin, TX',
        'to_location' => 'Denver, CO',
        'job_location' => 'Main warehouse',
        'cf' => '900',
        'balance' => '1200.5',
        'storage_fee' => '40',
        'notes' => 'Handle with care',
    ];
}

function last_audit(string $action): ?array
{
    return Database::fetchOne('SELECT * FROM audit_logs WHERE action = ? ORDER BY id DESC LIMIT 1', [$action]);
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

// ================================================================ PHASE 2: core job management

section('Jobs: validation rules');
fresh_db();
fresh_session();
$ok = job_input();
$v = Validator::make($ok, JobRules::rules(null, true), JobRules::labels());
check($v->passes(), 'a complete valid job passes');
foreach (['job_number', 'customer_first_name', 'customer_last_name', 'first_available_date', 'from_location', 'to_location'] as $field) {
    $v = Validator::make(job_input([$field => '']), JobRules::rules(null, true), JobRules::labels());
    check(isset($v->errors()[$field]), "{$field} is required");
}
$v = Validator::make(job_input(['pickup_date' => '', 'job_location' => '', 'cf' => '', 'notes' => '']), JobRules::rules(null, true), JobRules::labels());
check($v->passes(), 'pickup date, job location, CF and notes are optional');
check(Validator::make(job_input(['job_number' => 'AB C!']), JobRules::rules(), JobRules::labels())->fails(), 'job # rejects spaces and symbols');
check(Validator::make(job_input(['job_number' => 'JT-1001/A.2']), JobRules::rules(), JobRules::labels())->passes(), 'job # accepts letters, digits and - _ / .');
check(Validator::make(job_input(['job_number' => str_repeat('A', 41)]), JobRules::rules(), JobRules::labels())->fails(), 'job # max 40 characters');
check(Validator::make(job_input(['first_available_date' => '2026-13-45']), JobRules::rules(), JobRules::labels())->fails(), 'impossible available date rejected');
check(Validator::make(job_input(['pickup_date' => 'tomorrow']), JobRules::rules(), JobRules::labels())->fails(), 'non-date pickup rejected');
check(Validator::make(job_input(['balance' => '-5']), JobRules::rules(null, true), JobRules::labels())->fails(), 'negative balance rejected');
check(Validator::make(job_input(['storage_fee' => 'abc']), JobRules::rules(null, true), JobRules::labels())->fails(), 'non-numeric storage fee rejected');
check(Validator::make(job_input(['balance' => '99999999999']), JobRules::rules(null, true), JobRules::labels())->fails(), 'balance above the column limit rejected');
check(Validator::make(job_input(['cf' => '123456789']), JobRules::rules(null, true), JobRules::labels())->fails(), 'CF above the column limit rejected');
check(Validator::make(job_input(['customer_first_name' => str_repeat('x', 101)]), JobRules::rules(), JobRules::labels())->fails(), 'first name max 100 characters');
check(Validator::make(job_input(['notes' => str_repeat('n', 5001)]), JobRules::rules(), JobRules::labels())->fails(), 'notes max 5000 characters');
check(!array_key_exists('balance', JobRules::rules(null, false)), 'financial fields are not even validated without the financial permission');

section('Jobs: service rules (Manager)');
$manager = make_user('Manager', 'mgr@example.com');
$staff = make_user('Staff', 'stf@example.com');
sign_in($manager);
$svc = new JobService();
$input = job_input(['job_number' => 'JT-5001', 'status' => 'delivered', 'carrier_name' => 'Forged Carrier', 'driver_phone' => '555', 'id' => 999, 'deleted_at' => '2020-01-01']);
$id = $svc->create($input);
$job = (new JobRepository())->find($id);
check($job['status'] === 'booked', 'new jobs are always "booked" (forged status ignored)');
check($job['carrier_name'] === null && $job['driver_phone'] === null && (int) $job['id'] === $id && $job['deleted_at'] === null, 'forged carrier, id and deleted_at ignored');
check((int) $job['created_by'] === (int) $manager['id'] && (int) $job['updated_by'] === (int) $manager['id'], 'created_by / updated_by recorded');
check($job['balance'] == 1200.5 && $job['storage_fee'] == 40 && $job['cf'] == 900, 'figures stored as decimals');
$audit = last_audit('job_created');
check($audit !== null && (int) $audit['entity_id'] === $id && str_contains((string) $audit['new_values'], 'JT-5001'), 'creation is audited');

$id2 = $svc->create(job_input(['job_number' => 'JT-5002', 'pickup_date' => '', 'cf' => '', 'job_location' => '  ', 'notes' => '', 'balance' => '', 'storage_fee' => '']));
$j2 = (new JobRepository())->find($id2);
check($j2['pickup_date'] === null && $j2['cf'] === null && $j2['job_location'] === null && $j2['notes'] === null, 'empty optional fields are stored as NULL');
check($j2['balance'] == 0 && $j2['storage_fee'] == 0, 'empty money fields default to 0.00');

section('Jobs: financial fields are enforced server-side');
sign_in($staff);
check(!$svc->canSeeFinancials() && !$svc->canEditFinancials(), 'Staff has no financial access');
$sid = $svc->create(job_input(['job_number' => 'JT-5003', 'balance' => '777', 'storage_fee' => '88']));
$sj = (new JobRepository())->find($sid);
check($sj['balance'] == 0 && $sj['storage_fee'] == 0, 'a Staff-supplied balance and storage fee are ignored on create');
$view = $svc->find($id);
check(!array_key_exists('balance', $view) && !array_key_exists('storage_fee', $view), 'service strips balance and storage fee for Staff (find)');
$page = $svc->page(50, 0);
check(array_reduce($page['rows'], static fn (bool $c, array $r): bool => $c && !array_key_exists('balance', $r), true), 'service strips them from list rows too');
$loaded = (string) (new JobRepository())->find($id)['updated_at'];
$res = $svc->update($id, job_input(['job_number' => 'JT-5001', 'customer_last_name' => 'Renamed', 'balance' => '1', 'storage_fee' => '1']), $loaded);
$after = (new JobRepository())->find($id);
check($res['ok'] && $after['customer_last_name'] === 'Renamed', 'Staff can edit non-financial fields');
check($after['balance'] == 1200.5 && $after['storage_fee'] == 40, 'Staff cannot change balance or storage fee, even by forging the POST');

sign_in($manager);
check($svc->canSeeFinancials() && $svc->canEditFinancials() && array_key_exists('balance', $svc->find($id)), 'Manager sees and edits financials');

section('Jobs: update, change detection and locking');
$id = $svc->create(job_input(['job_number' => 'JT-5010', 'balance' => '100']));
$loaded = (string) (new JobRepository())->find($id)['updated_at'];
$res = $svc->update($id, job_input(['job_number' => 'JT-5010', 'customer_first_name' => '  Grace ', 'balance' => '250', 'status' => 'delivered', 'carrier_name' => 'X']), $loaded);
$row = (new JobRepository())->find($id);
check($res['ok'] && !$res['conflict'] && $row['customer_first_name'] === 'Grace', 'update saves and trims values');
check($row['status'] === 'booked' && $row['carrier_name'] === null, 'update ignores forged status and carrier fields');
check(in_array('customer_first_name', $res['changed'], true) && in_array('balance', $res['changed'], true) && !in_array('job_number', $res['changed'], true), 'only genuinely changed fields are reported');
$audit = last_audit('job_updated');
$old = json_decode((string) $audit['old_values'], true);
$new = json_decode((string) $audit['new_values'], true);
check($old['balance'] === '100.00' && $new['balance'] === '250.00' && !isset($new['job_number']), 'audit holds old/new values for changed fields only');
check((int) $row['updated_by'] === (int) $manager['id'], 'updated_by recorded');

$auditCount = (int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'job_updated'");
$loaded = (string) $row['updated_at'];
$same = $svc->update($id, job_input(['job_number' => 'JT-5010', 'customer_first_name' => 'Grace', 'balance' => '250', 'storage_fee' => '40', 'customer_last_name' => 'Smith']), $loaded);
check($same['ok'] && $same['changed'] === [], 'saving without changes is a no-op');
check((int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'job_updated'") === $auditCount, 'a no-op writes no audit entry');

Database::execute('UPDATE jobs SET updated_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $id]);
$stale = $svc->update($id, job_input(['job_number' => 'JT-5010', 'customer_first_name' => 'Overwrite']), $loaded);
check(!$stale['ok'] && $stale['conflict'], 'a stale form is rejected (optimistic locking)');
check((new JobRepository())->find($id)['customer_first_name'] === 'Grace', 'the stale save changed nothing');
check($svc->update($id, job_input(['job_number' => 'JT-5010']), '')['conflict'], 'a missing loaded_at counts as stale');
check($svc->update(999999, job_input(), '2020-01-01 00:00:00')['ok'] === false, 'updating an unknown job fails cleanly');

section('Jobs: soft delete and restore');
$before = (new JobRepository())->count();
$del = $svc->create(job_input(['job_number' => 'JT-5020']));
check((new JobRepository())->count() === $before + 1, 'count includes the new job');
check($svc->delete($del), 'delete succeeds');
check($svc->find($del) === null && (new JobRepository())->count() === $before, 'deleted job vanishes from find and count');
check(!in_array('JT-5020', array_column($svc->page(500, 0)['rows'], 'job_number'), true), 'deleted job vanishes from the list');
check(in_array('JT-5020', array_column($svc->trashed(), 'job_number'), true), 'deleted job appears in the trash');
check(Database::fetchValue('SELECT deleted_at FROM jobs WHERE id = ?', [$del]) !== null, 'the row still exists in the database (soft delete)');
check(!$svc->delete($del), 'deleting twice fails');
check(!Validator::make(['job_number' => 'JT-5020'], ['job_number' => 'unique:jobs,job_number'])->passes(), 'a deleted job keeps its job # reserved (validator)');
$dup = null;
try {
    (new JobRepository())->create(['job_number' => 'JT-5020', 'customer_first_name' => 'A', 'customer_last_name' => 'B', 'first_available_date' => '2026-12-01', 'from_location' => 'X', 'to_location' => 'Y', 'status' => 'booked'], null);
} catch (PDOException $e) {
    $dup = $e;
}
check($dup !== null && (string) $dup->getCode() === '23000' && str_contains($dup->getMessage(), 'job_number'), 'the database itself also refuses a duplicate job # (race-proof)');
check(last_audit('job_deleted') !== null, 'deletion is audited');
check($svc->restore($del) && $svc->find($del) !== null, 'restore brings the job back');
check(!$svc->restore($del), 'restoring a job that is not deleted fails');
check(last_audit('job_restored') !== null, 'restore is audited');

section('Jobs: routes and permissions (real routes)');
foreach (real_router()->routes() as $route) {
    if (!$route->hasAccessRule()) {
        check(false, "route {$route->method} {$route->pattern} has no access rule");
    }
}
$known = array_keys((array) Config::get('permissions.permissions'));
$unknown = [];
foreach (real_router()->routes() as $route) {
    if ($route->permission !== null && !in_array($route->permission, $known, true)) {
        $unknown[] = $route->permission;
    }
}
check($unknown === [], 'every permission key used by a route exists in the permission list' . ($unknown ? ' (unknown: ' . implode(',', $unknown) . ')' : ''));

fresh_session();
sign_in($staff);
check(call('GET', '/jobs')->status() === 200, 'Staff: job list');
check(call('GET', '/jobs/create')->status() === 200, 'Staff: new-job form');
check(call('GET', '/jobs/trash')->status() === 403, 'Staff: trash is forbidden');
$r = call('POST', '/jobs', job_input(['job_number' => 'JT-6001', 'balance' => '999', 'status' => 'delivered']));
check($r->status() === 302 && preg_match('#/jobs/(\d+)$#', (string) $r->getHeader('Location'), $m) === 1, 'Staff: creating a job redirects to its page');
$staffJobId = (int) ($m[1] ?? 0);
$created = (new JobRepository())->find($staffJobId);
check($created !== null && $created['status'] === 'booked' && $created['balance'] == 0, 'Staff: forged status/balance ignored through the real route');
check(str_contains(call('GET', '/jobs/' . $staffJobId)->body(), 'JT-6001'), 'Staff: details page shows the job');
check(call('GET', '/jobs/' . $staffJobId . '/edit')->status() === 200, 'Staff: edit form');
check(call('POST', '/jobs/' . $staffJobId . '/delete')->status() === 403, 'Staff: delete is forbidden');
check(call('POST', '/jobs/' . $staffJobId . '/restore')->status() === 403, 'Staff: restore is forbidden');
check((new JobRepository())->find($staffJobId) !== null, 'Staff: the forbidden delete changed nothing');
$staffList = call('GET', '/jobs')->body();
check(!str_contains($staffList, 'Balance') && !str_contains($staffList, '$'), 'Staff: no balance column or amounts on the list');
$staffShow = call('GET', '/jobs/' . (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'JT-5001'"))->body();
check(!str_contains($staffShow, 'Balance') && !str_contains($staffShow, 'Storage fee') && !str_contains($staffShow, '1,200.50'), 'Staff: no financial figures on the details page');
$staffForm = call('GET', '/jobs/create')->body();
check(!str_contains($staffForm, 'name="balance"') && !str_contains($staffForm, 'name="storage_fee"'), 'Staff: financial inputs are not in the form');
check(!str_contains($staffList, 'Delete') && !str_contains($staffList, '/jobs/trash'), 'Staff: no delete/trash links shown');

fresh_session();
sign_in($manager);
$list = call('GET', '/jobs')->body();
check(str_contains($list, 'Balance') && str_contains($list, '$'), 'Manager: balance column and amounts shown');
check(str_contains(call('GET', '/jobs/create')->body(), 'name="balance"'), 'Manager: financial inputs in the form');
check(call('GET', '/jobs/trash')->status() === 200, 'Manager: trash page');
check(call('GET', '/jobs/999999')->status() === 404 && call('GET', '/jobs/999999/edit')->status() === 404 && call('POST', '/jobs/999999/delete')->status() === 404, 'unknown job ids -> 404');
check(call('GET', '/jobs/abc')->status() === 404, 'non-numeric job id -> 404');

$r = call('POST', '/jobs/' . $staffJobId . '/delete');
check($r->status() === 302 && str_ends_with((string) $r->getHeader('Location'), '/jobs'), 'Manager: delete redirects to the list');
check(call('GET', '/jobs/' . $staffJobId)->status() === 404 && call('GET', '/jobs/' . $staffJobId . '/edit')->status() === 404, 'a deleted job is 404 on its page and edit form');
check(call('POST', '/jobs/' . $staffJobId, job_input())->status() === 404, 'a deleted job cannot be updated');
check(str_contains(call('GET', '/jobs/trash')->body(), 'JT-6001'), 'deleted job is listed in the trash');
$r = call('POST', '/jobs/' . $staffJobId . '/restore');
check($r->status() === 302 && call('GET', '/jobs/' . $staffJobId)->status() === 200, 'Manager: restore makes the job reachable again');

section('Jobs: form handling through the real routes');
fresh_session();
sign_in($manager);
$r = call('POST', '/jobs', ['job_number' => '', 'customer_first_name' => '<b>x</b>', 'balance' => '-1']);
check($r->status() === 302 && str_ends_with((string) $r->getHeader('Location'), '/jobs/create'), 'invalid create redirects back to the form');
check(isset($_SESSION['_flash_next']['errors']['job_number'], $_SESSION['_flash_next']['errors']['balance']), 'field errors are flashed');
check(!isset($_SESSION['_flash_next']['old']['_csrf']), 'the CSRF token is not echoed back');
$form = call('GET', '/jobs/create')->body();
check(str_contains($form, 'is-invalid') && str_contains($form, 'Job # is required.'), 'the form shows the error messages');
check(str_contains($form, 'value="&lt;b&gt;x&lt;/b&gt;"'), 'the submitted value is repopulated and escaped');

$r = call('POST', '/jobs', job_input(['job_number' => 'JT-5001']));
check($r->status() === 302 && str_ends_with((string) $r->getHeader('Location'), '/jobs/create') && isset($_SESSION['_flash_next']['errors']['job_number']), 'duplicate job # is a field error, not a crash');
$r = call('POST', '/jobs', job_input(['job_number' => 'JT-5020-X']));
check($r->status() === 302, 'a fresh job # is accepted');

$xss = '<script>alert(1)</script>';
$r = call('POST', '/jobs', job_input(['job_number' => 'JT-XSS1', 'customer_first_name' => $xss, 'from_location' => $xss, 'notes' => $xss]));
preg_match('#/jobs/(\d+)$#', (string) $r->getHeader('Location'), $m);
$xssId = (int) $m[1];
foreach (['/jobs' => 'list', '/jobs/' . $xssId => 'details', '/jobs/' . $xssId . '/edit' => 'edit form'] as $path => $label) {
    $body = call('GET', $path)->body();
    check(!str_contains($body, $xss) && str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;'), "script tags are escaped on the {$label}");
}
check(!str_contains(call('GET', '/jobs')->body(), '<td ') || preg_match('/<td(?![^>]*data-label)/', call('GET', '/jobs')->body()) === 0, 'every table cell has a data-label (table-to-card layout on phones)');

// optimistic locking through the form
$editBody = call('GET', '/jobs/' . $xssId . '/edit')->body();
preg_match('/name="loaded_at" value="([^"]+)"/', $editBody, $lm);
check(isset($lm[1]), 'the edit form carries loaded_at');
Database::execute('UPDATE jobs SET updated_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $xssId]); // someone else saved
$r = call('POST', '/jobs/' . $xssId, job_input(['job_number' => 'JT-XSS1', 'customer_first_name' => 'Mine', 'loaded_at' => $lm[1]]));
check($r->status() === 302 && str_ends_with((string) $r->getHeader('Location'), '/edit'), 'stale edit is sent back to the form');
check(str_contains((string) ($_SESSION['_flash_next']['alert']['message'] ?? ''), 'Someone else changed this job'), 'with a conflict warning');
check((string) (new JobRepository())->find($xssId)['customer_first_name'] === $xss, "and the other user's data is untouched");
$again = call('GET', '/jobs/' . $xssId . '/edit')->body();
check(str_contains($again, 'value="Mine"') && str_contains($again, '2020-01-01 00:00:00'), "the user's entries are kept and the form now carries the fresh loaded_at");
$r = call('POST', '/jobs/' . $xssId, job_input(['job_number' => 'JT-XSS1', 'customer_first_name' => 'Mine', 'loaded_at' => '2020-01-01 00:00:00']));
check($r->status() === 302 && (string) (new JobRepository())->find($xssId)['customer_first_name'] === 'Mine', 'resubmitting with the fresh loaded_at saves');

$r = call('POST', '/jobs/' . $xssId, job_input(['job_number' => 'JT-5001', 'loaded_at' => (string) (new JobRepository())->find($xssId)['updated_at']]));
check(isset($_SESSION['_flash_next']['errors']['job_number']), 'renaming to an existing job # is rejected on update');
$cur = (string) (new JobRepository())->find($xssId)['updated_at'];
$r = call('POST', '/jobs/' . $xssId, job_input(['job_number' => 'JT-XSS1', 'loaded_at' => $cur]));
check(!isset($_SESSION['_flash_next']['errors']), 'keeping its own job # is fine on update');
check(call('POST', '/jobs', job_input())->status() === 302, 'CSRF-valid create passes');
$noToken = real_router()->dispatch(Request::make('POST', '/jobs', job_input()));
check($noToken->status() === 403, 'create without a CSRF token is refused');
$noToken = real_router()->dispatch(Request::make('POST', '/jobs/' . $xssId . '/delete'));
check($noToken->status() === 403 && (new JobRepository())->find($xssId) !== null, 'delete without a CSRF token is refused and changes nothing');

section('Jobs: list pagination');
fresh_db();
fresh_session();
$manager = make_user('Manager', 'mgr2@example.com');
sign_in($manager);
for ($i = 1; $i <= 60; $i++) {
    (new JobRepository())->create(['job_number' => sprintf('P-%03d', $i), 'customer_first_name' => 'A', 'customer_last_name' => 'B', 'first_available_date' => '2026-12-01', 'from_location' => 'X', 'to_location' => 'Y', 'status' => 'booked'], null);
}
$p1 = call('GET', '/jobs')->body();
check(str_contains($p1, 'P-060') && !str_contains($p1, 'P-035') && str_contains($p1, 'P-036'), 'page 1 holds the newest 25 jobs');
check(str_contains($p1, 'aria-label="Pagination"') && str_contains($p1, 'Showing 1&ndash;25 of 60'), 'pagination controls and counts shown');
$p3 = call('GET', '/jobs', [], ['page' => '3'])->body();
check(str_contains($p3, 'P-001') && str_contains($p3, 'P-010') && !str_contains($p3, 'P-011'), 'page 3 holds the oldest 10 jobs');
check(str_contains(call('GET', '/jobs', [], ['page' => '999'])->body(), 'P-001'), 'a page past the end is clamped to the last page');
check(str_contains(call('GET', '/jobs', [], ['page' => '-4'])->body(), 'P-060'), 'a negative page is treated as page 1');
$pg = new Paginator(60, 25, 2);
check($pg->lastPage === 3 && $pg->offset() === 25 && $pg->from() === 26 && $pg->to() === 50, 'paginator arithmetic');
$pg = new Paginator(0, 25, 5);
check($pg->page === 1 && $pg->from() === 0 && $pg->to() === 0, 'paginator handles an empty list');
$pg = new Paginator(1000, 10, 50);
$win = $pg->window();
check(in_array(null, $win, true) && $win[0] === 1 && $win[array_key_last($win)] === 100, 'paginator window keeps first/last page and marks gaps');

section('Jobs: empty states and formatting helpers');
fresh_db();
fresh_session();
sign_in(make_user('Manager', 'mgr3@example.com'));
check(str_contains(call('GET', '/jobs')->body(), 'No jobs yet'), 'empty list shows a friendly empty state');
check(str_contains(call('GET', '/jobs/trash')->body(), 'The trash is empty'), 'empty trash shows an empty state');
check(fmt_date('2026-11-06') === 'Nov 6, 2026' && fmt_date(null) === '' && fmt_date('garbage') === '', 'fmt_date');
check(fmt_money('1200.5') === '$1,200.50' && fmt_money(null) === '' && fmt_money('abc') === '', 'fmt_money uses the currency setting');
check(fmt_number('900.00') === '900' && fmt_number('12.50') === '12.5' && fmt_number('1234.5') === '1,234.5' && fmt_number(null) === '', 'fmt_number trims trailing zeros');
check(fmt_datetime('2026-11-06 15:30:00') === 'Nov 6, 2026 3:30 PM', 'fmt_datetime converts from UTC');
check(str_contains(status_badge('in_transit'), 'jt-badge-transit') && str_contains(status_badge('in_transit'), 'In Transit'), 'status_badge maps known statuses');
check(str_contains(status_badge('weird<x>'), 'Weird&lt;x&gt;'), 'status_badge escapes unknown statuses');
$_SESSION['_flash'] = ['old' => ['a' => 'typed']];
check(old_or('a', 'db') === 'typed' && old_or('b', 'db') === 'db', 'old_or prefers the submitted value, else the stored one');
$_SESSION['_flash'] = [];


finish();
