<?php

declare(strict_types=1);

/**
 * Phase 7 tests: settings page, audit log viewer, route audit, maintenance cleanup and the environment check.
 * Included by tests/run.php (after phase5.php), which provides the fixtures and helpers.
 */

use App\Controllers\AuditController;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Repositories\AuditLogRepository;
use App\Repositories\ImportRepository;
use App\Services\AuditCriteria;
use App\Services\AuditService;
use App\Services\EnvironmentCheck;
use App\Services\JobBucket;
use App\Services\MaintenanceService;
use App\Services\RouteAudit;
use App\Services\SettingsService;

function p7_setting(string $key): ?string
{
    $v = Database::fetchValue('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
    return $v === false || $v === null ? null : (string) $v;
}

function p7_post_settings(array $override = []): \App\Core\Response
{
    return call('POST', '/settings', $override + [
        'company_name' => 'Acme Moving', 'ready_threshold_days' => '30', 'currency_symbol' => '$',
        'timezone' => 'UTC', 'page_size' => '25',
    ]);
}

function user_row_p7(int $id): ?array
{
    return Database::fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
}

function p7_audit_row(string $action, ?int $userId, ?string $entity, ?int $entityId, string $when, ?string $old = null, ?string $new = null, string $ua = 'test-agent'): int
{
    Database::execute(
        'INSERT INTO audit_logs (user_id, action, entity, entity_id, old_values, new_values, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$userId, $action, $entity, $entityId, $old, $new, '203.0.113.9', $ua, $when]
    );
    return (int) Database::fetchValue('SELECT MAX(id) FROM audit_logs');
}

// ================================================================ settings: validation

section('Settings: validation');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'p7-admin@example.com');
$mgr = make_user('Manager', 'p7-mgr@example.com');
$staff = make_user('Staff', 'p7-stf@example.com');

$good = ['company_name' => 'Acme Moving', 'ready_threshold_days' => '45', 'currency_symbol' => '€', 'timezone' => 'America/Chicago', 'page_size' => '50'];
$r = SettingsService::validate($good);
check($r['errors'] === [] && $r['values'] === $good, 'valid input passes and comes back normalised');

foreach (['0', '-5', '366', '1.5', 'abc', '', '30; DROP TABLE settings', ' ', '１２'] as $bad) {
    check(isset(SettingsService::validate(['ready_threshold_days' => $bad] + $good)['errors']['ready_threshold_days']), 'day limit "' . $bad . '" is refused');
}
foreach (['1', '30', '365', ' 60 '] as $okay) {
    check(!isset(SettingsService::validate(['ready_threshold_days' => $okay] + $good)['errors']['ready_threshold_days']), 'day limit "' . $okay . '" is accepted');
}
check(isset(SettingsService::validate(['company_name' => ''] + $good)['errors']['company_name']), 'an empty company name is refused');
check(isset(SettingsService::validate(['company_name' => str_repeat('x', 61)] + $good)['errors']['company_name']), 'a 61-character company name is refused');
check(SettingsService::validate(['company_name' => "Acme\x00\t  Moving\n"] + $good)['values']['company_name'] === 'Acme Moving', 'control characters and runs of spaces are collapsed');
check(isset(SettingsService::validate(['company_name' => ['x']] + $good)['errors']['company_name']), 'an array where text is expected is refused, not coerced');
check(isset(SettingsService::validate(['currency_symbol' => 'USD$'] + $good)['errors']['currency_symbol']), 'a 4-character currency symbol is refused');
check(isset(SettingsService::validate(['timezone' => 'Mars/Olympus'] + $good)['errors']['timezone']), 'an unknown time zone is refused');
check(isset(SettingsService::validate(['timezone' => '../../etc/passwd'] + $good)['errors']['timezone']), 'a path-looking time zone is refused');
check(isset(SettingsService::validate(['page_size' => '1000'] + $good)['errors']['page_size']), 'a page size outside the list is refused');
check(count(SettingsService::validate([])['errors']) === 5, 'a request with nothing in it fails on all five fields');

// ================================================================ settings: saving, audit, effect

section('Settings: saving, audit and effect');
sign_in($admin);
$view = call('GET', '/settings');
$html = $view->content();
check($view->status() === 200 && str_contains($html, 'name="ready_threshold_days"') && str_contains($html, 'value="30"'), 'the page shows the current values');
check(str_contains($html, 'name="_csrf"'), 'the form carries a CSRF token');

$job = job_input(['job_number' => 'P7-1', 'first_available_date' => '2026-12-02']); // 40 days from 2026-10-23
$jobId = (new \App\Services\JobService())->create($job)['id'] ?? null;
if ($jobId === null) {
    $jobId = (int) Database::fetchValue("SELECT id FROM jobs WHERE job_number = 'P7-1'");
}
$jobRow = Database::fetchOne('SELECT * FROM jobs WHERE id = ?', [$jobId]);
check(JobBucket::of($jobRow) === 'later', 'a job 40 days out starts in More Than 30 Days');

$res = p7_post_settings(['ready_threshold_days' => '60', 'company_name' => 'Acme Moving']);
check($res->status() === 302 && p7_setting('ready_threshold_days') === '60' && p7_setting('company_name') === 'Acme Moving', 'saving stores the new values');
SettingsService::flush();
check(JobBucket::threshold() === 60 && JobBucket::of($jobRow) === 'ready', 'the same job is now Ready for Delivery, with no data change');
$ready = call('GET', '/jobs/ready')->content();
check(str_contains($ready, 'P7-1'), 'and it is listed under Ready for Delivery');
check(!str_contains(call('GET', '/jobs/more-than-30')->content(), 'P7-1'), '...and no longer under the later list');

$a = last_audit('settings_updated');
$old = json_decode((string) $a['old_values'], true);
$new = json_decode((string) $a['new_values'], true);
check($a !== null && (int) $a['user_id'] === (int) $admin['id'] && $a['entity'] === 'settings', 'the change is audited with who made it');
check($old === ['company_name' => 'Job Tracker', 'ready_threshold_days' => '30'] && $new === ['company_name' => 'Acme Moving', 'ready_threshold_days' => '60'], 'the audit entry holds only what changed, old and new');

$before = (int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings_updated'");
$res = p7_post_settings(['ready_threshold_days' => '60', 'company_name' => 'Acme Moving']);
check($res->status() === 302 && (int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings_updated'") === $before, 'saving without changing anything writes no audit entry');

$res = p7_post_settings(['ready_threshold_days' => '0', 'company_name' => '<b>x</b>']);
check($res->status() === 302 && p7_setting('ready_threshold_days') === '60' && p7_setting('company_name') === 'Acme Moving', 'an invalid save changes nothing, even for the valid fields in it');
$page = call('GET', '/settings')->content();
check(str_contains($page, 'whole number of days') && str_contains($page, '&lt;b&gt;x&lt;/b&gt;') && !str_contains($page, '<b>x</b>'), 'the form comes back with the error and the typed value, escaped');

p7_post_settings(['company_name' => '<script>alert(1)</script>']);
$page = call('GET', '/dashboard')->content();
check(!str_contains($page, '<script>alert(1)</script>') && str_contains($page, '&lt;script&gt;'), 'a company name with markup is escaped everywhere it is shown');
p7_post_settings(['company_name' => 'Acme Moving']);

$extra = p7_post_settings(['setting_key' => 'ready_threshold_days', 'is_admin' => '1', 'page_size' => '25']);
check(Database::fetchValue("SELECT COUNT(*) FROM settings WHERE setting_key IN ('is_admin', 'setting_key')") == 0, 'unknown fields cannot create settings');

$rowsBefore = (int) Database::fetchValue('SELECT COUNT(*) FROM settings');
$none = SettingsService::update(['evil_key' => 'x', 'company_name' => 'Acme Moving']);
check($none['new'] === [] && (int) Database::fetchValue('SELECT COUNT(*) FROM settings') === $rowsBefore && p7_setting('evil_key') === null, 'update() ignores keys that are not editable settings');

p7_post_settings(['timezone' => 'Pacific/Auckland']);
SettingsService::flush();
check(fmt_datetime('2026-10-23 12:00:00') === 'Oct 24, 2026 1:00 AM', 'the time zone setting changes how stored UTC times are displayed');
p7_post_settings(['timezone' => 'UTC']);
SettingsService::flush();

p7_post_settings(['page_size' => '10']);
SettingsService::flush();
check(\App\Helpers\Paginator::pageSizeFromSettings() === 10, 'rows per page takes effect');
p7_post_settings(['page_size' => '25']);
SettingsService::flush();

// ================================================================ settings: access

section('Settings: access control');
foreach ([['Manager', $mgr], ['Staff', $staff]] as [$name, $user]) {
    sign_in($user);
    $before = p7_setting('company_name');
    check(call('GET', '/settings')->status() === 403, "{$name}: the page is forbidden");
    check(p7_post_settings(['company_name' => 'Hacked'])->status() === 403 && p7_setting('company_name') === $before, "{$name}: saving is forbidden and changes nothing");
    check(!str_contains(call('GET', '/account')->content(), 'href="/settings"'), "{$name}: the menu does not offer Settings");
}
Auth::logout();
fresh_session();
check(call('GET', '/settings')->status() === 302, 'signed out: redirected to sign in');
check(p7_post_settings()->status() === 302, 'signed out: saving is redirected, not processed');
sign_in($admin);
$noToken = real_router()->dispatch(Request::make('POST', '/settings', ['company_name' => 'NoToken', 'ready_threshold_days' => '30', 'currency_symbol' => '$', 'timezone' => 'UTC', 'page_size' => '25']));
check($noToken->status() === 403 && p7_setting('company_name') === 'Acme Moving', 'saving without a CSRF token is refused');
check(str_contains(call('GET', '/account')->content(), 'href="/settings"'), 'an administrator sees Settings in the menu');

// ================================================================ audit criteria

section('Audit log: filters');
$q = static fn (array $query): AuditCriteria => AuditCriteria::fromRequest(Request::make('GET', '/audit', [], $query));
check(!$q([])->hasFilters(), 'no filters by default');
check($q(['action' => "x'; DROP TABLE audit_logs;--"])->action === '', 'an action that is not an identifier is dropped');
check($q(['action' => 'Job_Created'])->action === '', 'uppercase is not an identifier here');
check($q(['action' => ['a']])->action === '', 'an array is dropped');
check($q(['action' => 'job_created'])->action === 'job_created', 'a real action is kept');
check($q(['user' => '-3'])->userId === 0 && $q(['user' => 'abc'])->userId === 0 && $q(['user' => '7'])->userId === 7, 'user ids are positive integers or nothing');
check($q(['from' => '2026-02-30'])->from === '' && $q(['from' => 'yesterday'])->from === '' && $q(['from' => '2026-03-01'])->from === '2026-03-01', 'only real dates are kept');
$swapped = $q(['from' => '2026-05-10', 'to' => '2026-05-01']);
check($swapped->from === '2026-05-01' && $swapped->to === '2026-05-10', 'a reversed range is put in order');
check($q(['page' => '0'])->page === 1 && $q(['page' => '-4'])->page === 1 && $q(['page' => '3'])->page === 3, 'page is at least 1');
check($q(['from' => '2026-10-23'])->fromUtc() === '2026-10-23 00:00:00' && $q(['to' => '2026-10-23'])->toUtc() === '2026-10-23 23:59:59', 'dates become inclusive UTC bounds');
SettingsService::update(['timezone' => 'America/New_York']);
check($q(['from' => '2026-10-23'])->fromUtc() === '2026-10-23 04:00:00' && $q(['to' => '2026-10-23'])->toUtc() === '2026-10-24 03:59:59', 'bounds follow the business time zone (EDT)');
SettingsService::update(['timezone' => 'UTC']);

// ================================================================ audit repository + pages

section('Audit log: listing, filtering, pages');
fresh_db();
fresh_session();
Clock::fake('2026-10-23');
$admin = make_user('Admin', 'p7-admin2@example.com');
$mgr = make_user('Manager', 'p7-mgr2@example.com');
$staff = make_user('Staff', 'p7-stf2@example.com');
Database::execute('DELETE FROM audit_logs');
$aid = (int) $admin['id'];
$mid = (int) $mgr['id'];

$e1 = p7_audit_row('login_success', $aid, null, null, '2026-10-20 09:00:00');
$e2 = p7_audit_row('job_created', $mid, 'job', 41, '2026-10-21 10:00:00', null, '{"job_number":"J-41","balance":"100.00"}');
$e3 = p7_audit_row('job_updated', $mid, 'job', 41, '2026-10-22 11:00:00', '{"balance":"100.00","notes":"a"}', '{"balance":"250.00","notes":"b"}');
$e4 = p7_audit_row('login_failed', null, null, null, '2026-10-22 23:30:00', null, '{"email":"nobody@example.com"}');
$e5 = p7_audit_row('user_created', $aid, 'user', 9, '2026-10-23 08:00:00');
$e6 = p7_audit_row('job_updated', $aid, 'job', 55, '2026-10-23 08:30:00', null, null, '<script>alert("ua")</script>');
$repo = new AuditLogRepository();
$all = AuditCriteria::fromRequest(Request::make('GET', '/audit'));

check($repo->count($all) === 6, 'all entries are counted');
$rows = $repo->search($all, 50, 0);
check(array_column($rows, 'id') === [$e6, $e5, $e4, $e3, $e2, $e1], 'newest first');
check($rows[0]['user_name'] === $admin['name'] && $rows[2]['user_name'] === null, 'the user name is joined; guests have none');
check(array_column($repo->search($all, 2, 2), 'id') === [$e4, $e3], 'limit and offset page through without overlap');

$f = static fn (array $query): array => array_map('intval', array_column($repo->search(AuditCriteria::fromRequest(Request::make('GET', '/audit', [], $query)), 50, 0), 'id'));
check($f(['action' => 'job_updated']) === [$e6, $e3], 'filter by action');
check($f(['entity' => 'job']) === [$e6, $e3, $e2], 'filter by record type');
check($f(['entity' => 'job', 'entity_id' => '41']) === [$e3, $e2], 'filter by one record');
check($f(['user' => (string) $mid]) === [$e3, $e2], 'filter by user');
check($f(['from' => '2026-10-22', 'to' => '2026-10-22']) === [$e4, $e3], 'a single day is inclusive of its last second');
check($f(['from' => '2026-10-23']) === [$e6, $e5], 'from only');
check($f(['to' => '2026-10-20']) === [$e1], 'to only');
check($f(['action' => 'job_updated', 'user' => (string) $mid, 'from' => '2026-10-22']) === [$e3], 'filters combine with AND');
check($f(['action' => 'nope_nothing']) === [], 'nothing matches an unknown action');
check($f(['action' => "' OR '1'='1"]) === [$e6, $e5, $e4, $e3, $e2, $e1], 'an injection attempt is dropped as a filter, not executed');
check((int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs') === 6, 'and no row was lost');

sign_in($admin);
$page = call('GET', '/audit');
$html = $page->content();
check($page->status() === 200 && str_contains($html, 'Job created') && str_contains($html, 'Failed sign-in') && str_contains($html, '203.0.113.9'), 'the page lists entries with readable action names');
check(str_contains($html, 'href="/jobs/41"') && str_contains($html, 'href="/users/9/edit"'), 'record references link to the record');
check(!str_contains($html, '<script>alert("ua")</script>'), 'hostile text in stored data is never output raw');
$filtered = call('GET', '/audit', [], ['action' => 'login_failed'])->content();
$rowsOnly = substr($filtered, (int) strpos($filtered, '<tbody>'));
check(str_contains($rowsOnly, 'Failed sign-in') && !str_contains($rowsOnly, 'Job created') && str_contains($filtered, '(filtered)'), 'the page applies filters and says so');
$empty = call('GET', '/audit', [], ['action' => 'nothing_here'])->content();
check(str_contains($empty, 'No entries match') && str_contains($empty, 'Clear filters'), 'an empty result is explained');
check(call('GET', '/audit', [], ['page' => '999'])->status() === 200, 'a page number past the end does not error');

$show = call('GET', '/audit/' . $e3);
$sh = $show->content();
check($show->status() === 200 && str_contains($sh, '250.00') && str_contains($sh, '100.00') && str_contains($sh, 'balance') && str_contains($sh, 'notes'), 'the detail page shows each field before and after');
check(str_contains(call('GET', '/audit/' . $e6)->content(), '&lt;script&gt;alert(&quot;ua&quot;)&lt;/script&gt;'), 'the detail page escapes the stored browser string');
check(call('GET', '/audit/99999')->status() === 404, 'an unknown entry is a 404');
check(call('GET', '/audit/abc')->status() === 404, 'a non-numeric id is a 404');

$changes = AuditController::changes('{"a":"1","gone":"x"}', '{"a":"2","new":"y","flag":true,"list":[1,2]}');
$byField = array_column($changes, null, 'field');
check($byField['a']['old'] === '1' && $byField['a']['new'] === '2', 'changes(): changed field');
check($byField['gone']['new'] === null && $byField['new']['old'] === null, 'changes(): removed and added fields are distinguished from empty');
check($byField['flag']['new'] === 'yes' && $byField['list']['new'] === '[1,2]', 'changes(): booleans and lists are readable');
check(AuditController::changes('not json', null) === [] && AuditController::changes(null, null) === [], 'changes(): malformed or missing JSON gives no rows');

// the audit log offers no way to change it
$mutating = array_filter(real_router()->routes(), static fn ($r) => str_starts_with($r->pattern, '/audit') && $r->method !== 'GET');
check($mutating === [], 'there is no route that edits or deletes audit entries');
check(call('POST', '/audit/' . $e1 . '/delete')->status() === 404 && call('POST', '/audit')->status() === 405, 'posting to the audit log finds nothing to run');
check((int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs') >= 6, '...and nothing was removed');

// access
foreach ([['Manager', $mgr], ['Staff', $staff]] as [$name, $user]) {
    sign_in($user);
    check(call('GET', '/audit')->status() === 403 && call('GET', '/audit/' . $e1)->status() === 403, "{$name}: audit log and entries are forbidden");
    check(!str_contains(call('GET', '/account')->content(), 'href="/audit"'), "{$name}: the menu does not offer the Audit Log");
}
// audit.view alone is enough, without any other administration right
$viewer = (new \App\Services\RoleService())->create('Auditor', 'Reads the audit log', null)['id'] ?? null;
$auditorRole = (int) Database::fetchValue("SELECT id FROM roles WHERE name = 'Auditor'");
if ($auditorRole > 0) {
    Database::execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$auditorRole, (int) Database::fetchValue("SELECT id FROM permissions WHERE name = 'audit.view'")]);
    $auditor = (new \App\Repositories\UserRepository())->findById((new \App\Repositories\UserRepository())->create([
        'name' => 'Auditor', 'email' => 'p7-aud@example.com', 'password_hash' => \App\Helpers\PasswordPolicy::hash('correct horse battery'), 'role_id' => $auditorRole,
    ]));
    sign_in($auditor);
    check(call('GET', '/audit')->status() === 200, 'a role with only audit.view can read the log');
    $keep = p7_setting('company_name');
    check(p7_post_settings(['company_name' => 'By auditor'])->status() === 403 && p7_setting('company_name') === $keep, '...it cannot save settings');
    check(call('GET', '/users')->status() === 403 && call('GET', '/settings')->status() === 403 && call('GET', '/jobs')->status() === 403, '...and nothing else');
}

// real entries are produced by real actions
sign_in($admin);
$beforeCount = (int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs');
p7_post_settings(['company_name' => 'Zeta Movers']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs') === $beforeCount + 1 && str_contains(call('GET', '/audit')->content(), 'Settings changed'), 'a settings change shows up in the viewer');
AuditService::record('password_changed', 'user', 1, ['password' => 'hunter2-old'], ['password' => 'hunter2-new', 'nested' => ['token' => 'abc123']]);
$last = Database::fetchOne('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1');
check(!str_contains((string) $last['old_values'] . (string) $last['new_values'], 'hunter2') && !str_contains((string) $last['new_values'], 'abc123'), 'passwords and tokens are redacted before storage, including nested ones');

// ================================================================ password change throttling

section('My Account: current-password guessing is limited');
fresh_db();
fresh_session();
$victim = make_user('Staff', 'p7-victim@example.com', 'correct horse battery');
sign_in($victim);
$change = static fn (string $current, string $new = 'a brand new passphrase 77'): \App\Core\Response => call('POST', '/account/password', [
    'current_password' => $current, 'password' => $new, 'password_confirmation' => $new,
]);
$max = (int) Config::get('app.auth.max_failed_attempts', 5);
for ($i = 1; $i < $max; $i++) {
    $res = $change('guess ' . $i);
    check($res->status() === 302 && $res->getHeader('Location') === '/account', "wrong guess {$i}: refused, still signed in");
}
check((int) user_row_p7((int) $victim['id'])['failed_attempts'] === $max - 1 && user_row_p7((int) $victim['id'])['locked_until'] === null, 'the failures are counted, below the limit');
$res = $change('guess ' . $max);
$row = user_row_p7((int) $victim['id']);
check($res->getHeader('Location') === '/login' && $row['locked_until'] !== null, "guess {$max}: the account locks and the session is ended");
check(last_audit('account_locked') !== null && str_contains((string) last_audit('account_locked')['new_values'], 'password change'), '...and the lock is audited');
check(call('GET', '/dashboard')->status() === 302, 'the hijacked session no longer works');
fresh_session();
$svc = new \App\Services\AuthService();
$login = $svc->attempt('p7-victim@example.com', 'correct horse battery', Request::make('GET', '/login', [], [], ['REMOTE_ADDR' => '198.51.100.7']));
check($login['ok'] === false, 'even the right password cannot sign in while the account is locked');
Database::execute('UPDATE users SET locked_until = NULL, failed_attempts = 0 WHERE id = ?', [$victim['id']]);

fresh_session();
sign_in(user_row_p7((int) $victim['id']));
$change('wrong 1');
$change('wrong 2');
check((int) user_row_p7((int) $victim['id'])['failed_attempts'] === 2, 'two wrong guesses are counted');
$res = $change('correct horse battery');
$row = user_row_p7((int) $victim['id']);
check($res->getHeader('Location') === '/account' && password_verify('a brand new passphrase 77', $row['password_hash']), 'the right password still changes it');
check((int) $row['failed_attempts'] === 0, '...and clears the failure count');

// ================================================================ route audit

section('Route audit');
$known = array_keys((array) Config::get('permissions.permissions', []));
$result = RouteAudit::inspect(real_router(), $known);
check($result['problems'] === [] && $result['routes'] > 40, 'the real route table has no problems (' . $result['routes'] . ' routes)');
check($result['public'] === ['GET /', 'GET /login', 'POST /login'], 'only the home redirect and sign-in are public');

$bad = new Router();
$bad->get('/open', ['X', 'y']);
$bad->get('/both', ['X', 'y'])->public()->authOnly();
$bad->get('/typo', ['X', 'y'])->can('jobs.vieww');
$bad->post('/leaky', ['X', 'y'])->public();
$bad->get('/things/{id:int}/delete', ['X', 'y'])->can('jobs.delete');
$bad->get('/dup', ['X', 'y'])->public();
$bad->get('/dup', ['X', 'y'])->public();
$problems = implode("\n", RouteAudit::inspect($bad, $known)['problems']);
foreach (['/open: no access rule', '/both: more than one', 'unknown permission "jobs.vieww"', '/leaky: a public route that changes data', '/delete: looks like an action but answers GET', '/dup: declared twice'] as $needle) {
    check(str_contains($problems, $needle), 'detects: ' . $needle);
}
$everyPostIsGuarded = true;
foreach (real_router()->routes() as $route) {
    if ($route->method === 'POST') {
        $res = real_router()->dispatch(Request::make('POST', preg_replace('/\{[^}]+\}/', '1', $route->pattern), []));
        if ($route->pattern !== '/login' && !in_array($res->status(), [302, 403], true)) {
            $everyPostIsGuarded = false;
        }
    }
}
check($everyPostIsGuarded, 'every POST route refuses a request with no token and no session (redirect or 403)');

// ================================================================ maintenance

section('Maintenance cleanup');
fresh_db();
fresh_session();
$dir = sys_get_temp_dir() . '/jt-maint-' . bin2hex(random_bytes(4));
mkdir($dir . '/imports', 0777, true);
mkdir($dir . '/logs', 0777, true);
$now = new DateTimeImmutable('2026-10-23 12:00:00', new DateTimeZone('UTC'));
$admin = make_user('Admin', 'p7-m@example.com');
$uid = (int) $admin['id'];

$attempt = static fn (string $when) => Database::execute("INSERT INTO login_attempts (email, ip, successful, created_at) VALUES ('a@example.com', '1.1.1.1', 0, ?)", [$when]);
$attempt('2026-09-30 00:00:00'); // 23 days old: kept
$attempt('2026-09-20 00:00:00'); // 33 days: removed
$attempt('2026-01-01 00:00:00'); // removed
$attempt('2026-10-22 00:00:00'); // kept

$imports = new ImportRepository();
$mk = static function (string $name, string $when, string $status) use ($imports, $uid, $dir): int {
    $file = $name . '.csv';
    file_put_contents($dir . '/imports/' . $file, 'x');
    $id = $imports->create($uid, $name . '.csv', $file, 'csv', ['total' => 1, 'valid' => 1, 'invalid' => 0, 'headers' => [], 'mapping' => [], 'ignored' => [], 'warnings' => [], 'sample' => []]);
    Database::execute('UPDATE import_batches SET created_at = ?, status = ? WHERE id = ?', [$when, $status, $id]);
    return $id;
};
$fresh = $mk('fresh', '2026-10-23 06:00:00', 'previewed');           // 6 h old: kept
$stale = $mk('stale', '2026-10-21 06:00:00', 'previewed');           // 2 days: expired + file removed
$stuck = $mk('stuck', '2026-10-21 06:00:00', 'importing');           // stuck: expired
$freshImporting = $mk('busy', '2026-10-23 11:30:00', 'importing');   // an import running right now: untouched
$oldDone = $mk('olddone', '2026-06-01 00:00:00', 'committed');       // > 90 days: record removed
$newDone = $mk('newdone', '2026-10-01 00:00:00', 'committed');       // kept
Database::execute("UPDATE import_batches SET stored_name = NULL WHERE id IN (?, ?)", [$oldDone, $newDone]);
Database::execute("INSERT INTO import_errors (batch_id, row_no, job_number, messages, raw_data) VALUES (?, 2, 'X', '[]', '[]')", [$oldDone]);
file_put_contents($dir . '/imports/stray.csv', 'orphan');
touch($dir . '/imports/stray.csv', $now->getTimestamp() - 7200);      // 2 h old, unreferenced: removed
file_put_contents($dir . '/imports/justnow.csv', 'in progress');
touch($dir . '/imports/justnow.csv', $now->getTimestamp() - 60);      // being uploaded: kept
file_put_contents($dir . '/imports/.gitkeep', '');
foreach (['2026-08-01', '2026-08-23', '2026-08-24', '2026-10-22'] as $day) {
    file_put_contents($dir . '/logs/app-' . $day . '.log', 'x');
}
file_put_contents($dir . '/logs/notes.txt', 'keep me');

$svc = new MaintenanceService($dir);
$dry = $svc->run(false, null, $now);
check($dry['login_attempts']['found'] === 2 && $dry['login_attempts']['removed'] === 0, 'dry run: counts old sign-in attempts and removes none');
check($dry['import_previews']['found'] === 2 && $dry['import_records']['found'] === 1 && $dry['import_files']['found'] === 1 && $dry['logs']['found'] === 2, 'dry run: finds previews, records, stray files and logs');
check((int) Database::fetchValue('SELECT COUNT(*) FROM login_attempts') === 4 && is_file($dir . '/imports/stray.csv') && is_file($dir . '/imports/stale.csv'), 'dry run: nothing was deleted');
check(!isset($dry['audit']), 'the audit log is not even looked at by default');

$audit0 = (int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs');
Database::execute("INSERT INTO audit_logs (action, ip, user_agent, created_at) VALUES ('ancient', '', '', '2020-01-01 00:00:00')");
$done = $svc->run(true, null, $now);
check((int) Database::fetchValue('SELECT COUNT(*) FROM login_attempts') === 2, 'apply: old attempts are removed, recent ones kept');
check(Database::fetchValue('SELECT status FROM import_batches WHERE id = ?', [$stale]) === 'expired' && !is_file($dir . '/imports/stale.csv'), 'apply: an abandoned preview is expired and its file deleted');
check(Database::fetchValue('SELECT status FROM import_batches WHERE id = ?', [$stuck]) === 'expired' && !is_file($dir . '/imports/stuck.csv'), 'apply: a stuck import is expired too');
check(Database::fetchValue('SELECT status FROM import_batches WHERE id = ?', [$fresh]) === 'previewed' && is_file($dir . '/imports/fresh.csv'), 'apply: a recent preview is untouched');
check(Database::fetchValue('SELECT status FROM import_batches WHERE id = ?', [$freshImporting]) === 'importing' && is_file($dir . '/imports/busy.csv'), 'apply: an import running right now is untouched');
check(Database::fetchOne('SELECT id FROM import_batches WHERE id = ?', [$oldDone]) === null && (int) Database::fetchValue('SELECT COUNT(*) FROM import_errors WHERE batch_id = ?', [$oldDone]) === 0, 'apply: an old finished import and its problem rows are removed');
check(Database::fetchOne('SELECT id FROM import_batches WHERE id = ?', [$newDone]) !== null, 'apply: a recent finished import is kept');
check(!is_file($dir . '/imports/stray.csv') && is_file($dir . '/imports/justnow.csv') && is_file($dir . '/imports/.gitkeep'), 'apply: stray files go; a file that is only seconds old and .gitkeep stay');
check(!is_file($dir . '/logs/app-2026-08-01.log') && !is_file($dir . '/logs/app-2026-08-23.log') && is_file($dir . '/logs/app-2026-08-24.log') && is_file($dir . '/logs/app-2026-10-22.log') && is_file($dir . '/logs/notes.txt'), 'apply: only dated logs past 60 days go');
check((int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs') === $audit0 + 1, 'apply: the audit log was not touched');
$again = $svc->run(true, null, $now);
check(array_sum(array_column($again, 'removed')) === 0, 'running it again finds nothing more to do');

throws(static fn () => $svc->run(true, 30, $now), 'a 30-day audit retention is refused');
throws(static fn () => $svc->run(true, 364, $now), 'so is 364');
$svc->run(false, 365, $now);
check((int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'ancient'") === 1, 'an audit retention dry run deletes nothing');
$svc->run(true, 365, $now);
check((int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'ancient'") === 0, 'an explicit 365-day retention removes entries older than that');
check((int) Database::fetchValue('SELECT COUNT(*) FROM audit_logs') === $audit0, '...and keeps everything newer');

array_map('unlink', glob($dir . '/imports/{,.}*', GLOB_BRACE) ?: []);
array_map('unlink', glob($dir . '/logs/*') ?: []);
@rmdir($dir . '/imports');
@rmdir($dir . '/logs');
@rmdir($dir);

// ================================================================ environment check

section('Environment check');
fresh_db();
Config::set('app.env', 'local');
$byLabel = static fn (array $rows): array => array_column($rows, null, 'label');
$rows = EnvironmentCheck::run();
check(count(array_filter($rows, static fn (array $r): bool => in_array($r['level'], ['ok', 'warn', 'fail'], true))) === count($rows), 'every result is ok, warn or fail');
check(isset($byLabel($rows)['All migrations applied']) && isset($byLabel($rows)['Extension zip']), 'it checks migrations and the zip extension');

Config::set('app.env', 'production');
Config::set('app.debug', true);
Config::set('app.url', 'http://shop.example.test');
make_user('Admin', 'real-demo@example.com');
$rows = EnvironmentCheck::run();
$labels = implode("\n", array_map(static fn (array $r): string => $r['level'] . ': ' . $r['label'], $rows));
check(str_contains($labels, 'fail: APP_DEBUG is on in production'), 'production with debug on fails');
check(str_contains($labels, 'warn: APP_URL is not HTTPS'), 'production over plain HTTP warns');
check(str_contains($labels, 'fail: Production must use MySQL/MariaDB'), 'production on SQLite fails');
check(str_contains($labels, 'fail: 1 demo account(s)'), 'a demo account left in production fails');
Config::set('app.debug', false);
Config::set('app.url', 'https://jobs.example.test');
$labels = implode("\n", array_map(static fn (array $r): string => $r['level'] . ': ' . $r['label'], EnvironmentCheck::run()));
check(!str_contains($labels, 'APP_DEBUG') && str_contains($labels, 'ok: APP_URL uses HTTPS'), 'fixing them clears those results');
Config::set('app.env', 'local');
Config::set('app.url', 'http://127.0.0.1:8099');
fresh_db();
fresh_session();
