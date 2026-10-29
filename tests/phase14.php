<?php

declare(strict_types=1);

/**
 * "Keep me signed in" (remember me), version 1.4.0: the sign-in checkbox, the cookie, the stored hash, and every way a
 * remembered sign-in must stop working. Included by tests/run.php (after phase13.php).
 */

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Repositories\UserRepository;
use App\Services\RememberService;

/** Sign in over HTTP; returns the cookie the server asked the browser to keep (or null). */
function rm_login(array $user, bool $remember = true, string $password = 'correct horse battery'): ?string
{
    fresh_session();
    RememberService::$lastCookie = null;
    unset($_COOKIE[RememberService::COOKIE]);
    call('POST', '/login', ['username' => $user['username'], 'password' => $password] + ($remember ? ['remember' => '1'] : []));
    return RememberService::$lastCookie;
}

/** A new browser session (the old one expired or the browser was closed) that still holds the cookie. */
function rm_new_session(?string $cookie): void
{
    fresh_session();
    if ($cookie === null) {
        unset($_COOKIE[RememberService::COOKIE]);
    } else {
        $_COOKIE[RememberService::COOKIE] = $cookie;
    }
}

section('Remember me: the sign-in page and the cookie');
fresh_db();
fresh_session();
Config::set('app.remember.days', 14);
$admin = make_user('Admin', 'rm-admin@example.com');
$staff = make_user('Staff', 'rm-staff@example.com');
$login = call('GET', '/login')->body();
check(str_contains($login, 'name="remember"') && str_contains($login, 'type="checkbox"') && str_contains($login, 'Keep me signed in on this device') && str_contains($login, '14 days'), 'the sign-in page has the checkbox, and says for how long');
Config::set('app.remember.days', 0);
check(!str_contains(call('GET', '/login')->body(), 'name="remember"'), 'with REMEMBER_ME_DAYS=0 there is no checkbox');
Config::set('app.remember.days', 14);

$cookie = rm_login($admin, false);
check($cookie === null && (int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens') === 0, 'without ticking the box nothing is remembered');
rm_new_session($cookie);
check(call('GET', '/dashboard')->status() === 302, '...and a new session must sign in again');

$cookie = rm_login($admin);
check($cookie !== null && preg_match('/^[0-9a-f]{24}:[0-9a-f]{64}$/', $cookie) === 1, 'ticking the box sets a cookie with a 24-character selector and a 64-character secret');
[$selector, $validator] = explode(':', (string) $cookie);
$row = Database::fetchOne('SELECT * FROM remember_tokens WHERE selector = ?', [$selector]);
check($row !== null && (int) $row['user_id'] === (int) $admin['id'] && $row['token_hash'] === hash('sha256', $validator) && $row['token_hash'] !== $validator, 'the database keeps only a hash of the secret');
$dump = json_encode(Database::fetchAll('SELECT * FROM remember_tokens'));
check(!str_contains((string) $dump, $validator), '...the secret itself is nowhere in the table');
$days = (strtotime((string) $row['expires_at'] . ' UTC') - time()) / 86400;
check($days > 13.9 && $days <= 14.0, 'it expires 14 days from now');
check(str_contains((string) Database::fetchValue("SELECT action FROM audit_logs WHERE action = 'remember_enabled' LIMIT 1"), 'remember_enabled'), 'turning it on is audited');

section('Remember me: coming back');
rm_new_session($cookie);
$r = call('GET', '/dashboard');
check($r->status() === 200 && (int) ($_SESSION['user_id'] ?? 0) === (int) $admin['id'], 'a new session with the cookie is signed in again, as the same user');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'login_success' ORDER BY id DESC LIMIT 1");
check(str_contains($aud, 'remember me'), '...and the audit log says it was through "remember me"');
check((int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens') === 1 && Database::fetchValue('SELECT expires_at FROM remember_tokens') === $row['expires_at'], 'using the cookie does not extend its life');
rm_new_session($cookie);
check(call('GET', '/login')->status() === 302 && str_ends_with((string) call('GET', '/login')->getHeader('Location'), '/dashboard'), 'the sign-in page sends a remembered browser straight to the dashboard');
rm_new_session($cookie);
$_SESSION['intended'] = '/jobs';
check(call('GET', '/jobs')->status() === 200, 'any page works, not just the dashboard');

section('Remember me: every way it must stop');
// expired
Database::execute('UPDATE remember_tokens SET expires_at = ?', ['2020-01-01 00:00:00']);
rm_new_session($cookie);
check(call('GET', '/dashboard')->status() === 302 && (int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens') === 0 && !isset($_COOKIE[RememberService::COOKIE]), 'an expired token is refused, deleted, and the cookie is cleared');
// wrong secret
$cookie = rm_login($admin);
[$selector] = explode(':', (string) $cookie);
rm_new_session($selector . ':' . str_repeat('0', 64));
check(call('GET', '/dashboard')->status() === 302 && (int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE selector = ?', [$selector]) === 0, 'a right selector with a wrong secret is refused and the token is removed (somebody is guessing)');
check((int) Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'remember_rejected'") === 1, '...and the audit log records it');
rm_new_session($cookie);
check(call('GET', '/dashboard')->status() === 302, 'even the real cookie no longer works after that');
// garbage
foreach (['garbage', '', str_repeat('a', 24) . ':' . str_repeat('b', 64), "'; DROP TABLE users; --", str_repeat('a', 5000), 'zz' . str_repeat('0', 22) . ':' . str_repeat('0', 64)] as $bad) {
    rm_new_session($bad);
    check(call('GET', '/dashboard')->status() === 302, 'a bad cookie (' . json_encode(substr($bad, 0, 20)) . ') is just ignored');
}
check((int) Database::fetchValue('SELECT COUNT(*) FROM users') === 2, '...and nothing was damaged');
// password changed
$cookie = rm_login($admin);
(new UserRepository())->updatePassword((int) $admin['id'], \App\Helpers\PasswordPolicy::hash('a brand new long password'), false);
rm_new_session($cookie);
check(call('GET', '/dashboard')->status() === 302 && (int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$admin['id']]) === 0, 'after the password changes, an older remembered sign-in no longer works');
Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [$admin['password_hash'], $admin['id']]);
// deactivated / locked
$cookie = rm_login($staff);
[$selOff] = explode(':', (string) $cookie);
Database::execute('UPDATE users SET is_active = 0 WHERE id = ?', [$staff['id']]);
rm_new_session($cookie);
check(call('GET', '/dashboard')->status() === 302 && (int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE selector = ?', [$selOff]) === 0, 'a deactivated user is not let in by the cookie, and the token is removed');
Database::execute('UPDATE users SET is_active = 1 WHERE id = ?', [$staff['id']]);
$cookie = rm_login($staff);
Database::execute('UPDATE users SET locked_until = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + 600), $staff['id']]);
rm_new_session($cookie);
check(call('GET', '/dashboard')->status() === 302, 'a locked user is not let in by the cookie');
Database::execute('UPDATE users SET locked_until = NULL WHERE id = ?', [$staff['id']]);
// user removed
$cookie = rm_login($staff);
Database::execute('DELETE FROM users WHERE id = ?', [$staff['id']]);
rm_new_session($cookie);
check(call('GET', '/dashboard')->status() === 302, 'a user who no longer exists is not let in');
$staff = make_user('Staff', 'rm-staff2@example.com');
// feature turned off
$cookie = rm_login($admin);
Config::set('app.remember.days', 0);
rm_new_session($cookie);
check(call('GET', '/dashboard')->status() === 302, 'with REMEMBER_ME_DAYS=0 an existing cookie stops working');
Config::set('app.remember.days', 14);
rm_new_session($cookie);
check(call('GET', '/dashboard')->status() === 200, '...and works again when it is turned back on (the token was not deleted)');

section('Remember me: sign out, other devices, the account page');
Database::execute('DELETE FROM remember_tokens');
$cookie = rm_login($admin);
[$selOut] = explode(':', (string) $cookie);
rm_new_session($cookie);
call('GET', '/dashboard');
$res = call('POST', '/logout');
check($res->status() === 302 && (int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE selector = ?', [$selOut]) === 0 && !isset($_COOKIE[RememberService::COOKIE]), 'signing out forgets this browser: token deleted, cookie cleared');
rm_new_session($cookie);
check(call('GET', '/dashboard')->status() === 302, '...so the old cookie value does not work any more');
Database::execute('DELETE FROM remember_tokens');
$cA = rm_login($admin);
$cB = rm_login($staff);
$cA2 = rm_login($admin);
rm_new_session($cA);
call('GET', '/dashboard');
call('POST', '/logout');
check((int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$admin['id']]) === 1 && (int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$staff['id']]) === 1, 'signing out on one device leaves the user\'s other device, and other users, alone');
rm_new_session($cA2);
check(call('GET', '/dashboard')->status() === 200, 'the other device is still signed in');
$acc = plain(call('GET', '/account')->body());
check(str_contains($acc, 'on 1 device') && str_contains($acc, 'Forget all devices'), 'My Account shows how many devices are remembered, with a button to forget them');
$res = call('POST', '/account/devices');
check($res->status() === 302 && (int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$admin['id']]) === 0 && (int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$staff['id']]) === 1, '"Forget all devices" removes only my own');
rm_new_session($cA2);
check(call('GET', '/dashboard')->status() === 302, '...and those cookies stop working');
check(str_contains((string) Database::fetchValue("SELECT action FROM audit_logs WHERE action = 'remember_revoked' LIMIT 1"), 'remember_revoked'), 'and it is audited');
rm_new_session($cB);
check(call('GET', '/dashboard')->status() === 200, 'Staff\'s remembered browser still works');
fresh_session();
\App\Core\Auth::login((new UserRepository())->findById((int) $admin['id']));
\App\Core\Session::set('x', 1);
$forged = real_router()->dispatch(\App\Core\Request::make('POST', '/account/devices', ['_csrf' => 'forged'], [], [], []));
check($forged->status() === 403, '"Forget all devices" refuses a forged token');
fresh_session();
unset($_COOKIE[RememberService::COOKIE]);
check(call('POST', '/account/devices')->status() === 302 && str_ends_with((string) call('POST', '/account/devices')->getHeader('Location'), '/login'), 'and needs a signed-in user');

section('Remember me: limits and password rules');
Database::execute('DELETE FROM remember_tokens');
rm_login($staff);
$cookie = rm_login($admin);
for ($i = 0; $i < 12; $i++) {
    rm_login($admin);
}
check((int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$admin['id']]) <= 10, 'a user keeps at most 10 remembered browsers (the oldest are dropped)');
check((int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$staff['id']]) === 1, '...without touching other users');
Database::execute('UPDATE remember_tokens SET expires_at = ? WHERE user_id = ?', ['2020-01-01 00:00:00', $admin['id']]);
rm_login($admin);
check((int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$admin['id']]) === 1, 'expired tokens are cleaned up on the next sign-in');
// wrong password never remembers
$bad = rm_login($admin, true, 'wrong password');
check($bad === null, 'a failed sign-in never sets a cookie even if the box was ticked');
// a user who must change the password is still forced to
$cookie = rm_login($admin);
Database::execute('UPDATE users SET must_change_password = 1 WHERE id = ?', [$admin['id']]);
rm_new_session($cookie);
$r = call('GET', '/jobs');
check($r->status() === 302 && str_ends_with((string) $r->getHeader('Location'), '/account'), 'a remembered user who must change the password is still sent to do it');
Database::execute('UPDATE users SET must_change_password = 0 WHERE id = ?', [$admin['id']]);
// changing the password through the account page ends every remembered sign-in
$cookie = rm_login($admin);
rm_new_session($cookie);
call('GET', '/dashboard');
call('POST', '/account/password', ['current_password' => 'correct horse battery', 'password' => 'another long passphrase 42', 'password_confirmation' => 'another long passphrase 42']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$admin['id']]) === 0, 'changing the password on My Account removes every remembered sign-in of that user');
Config::set('app.remember.days', 14);

section('Remember me: a server that has not migrated yet still signs people in');
fresh_db();
fresh_session();
Config::set('app.remember.days', 14);
$u = make_user('Admin', 'rm-nomig@example.com');
Database::connect()->exec('DROP TABLE remember_tokens');
$page = call('GET', '/login')->body();
check(!str_contains($page, 'name="remember"'), 'without the table there is no checkbox');
$c = rm_login($u, true);
check($c === null && (int) ($_SESSION['user_id'] ?? 0) === (int) $u['id'], 'signing in (even with a ticked box in the request) still works and sets no cookie');
rm_new_session('0123456789abcdef01234567:' . str_repeat('a', 64));
check(call('GET', '/dashboard')->status() === 302, 'and a stray cookie is simply ignored');
$rows = \App\Services\DeployCheck::run();
check(count(array_filter($rows, static fn (array $r): bool => $r['level'] === 'fail' && str_contains($r['label'], 'remember_tokens'))) === 1, 'php cli/verify.php reports the missing table');
Config::set('app.remember.days', 14);
