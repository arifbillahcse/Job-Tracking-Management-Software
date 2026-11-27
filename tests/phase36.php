<?php

declare(strict_types=1);

/**
 * v1.18.0 (companies): the Companies master (address, city, phone, DOT, MC) matched to the job's broker by name, shown on company
 * statements and company invoices (copied onto the invoice when it is saved). Included by tests/run.php (after phase35.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Services\CompanyService;
use App\Services\InvoiceService;

section('Companies: input rules');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$admin = make_user('Admin', 'p36-admin@example.com');
sign_in($admin);

$ok = ['name' => 'Prime Moving', 'address' => '12 Main St', 'city' => 'Alpharetta, GA 30009', 'phone' => '+1 (347) 668-4584', 'dot_number' => '2521000', 'mc_number' => 'MC-77123'];
check(CompanyService::validate($ok, null)['errors'] === [], 'a normal company is accepted');
check(CompanyService::validate(['name' => 'Only A Name'], null)['errors'] === [], 'only the name is required');
foreach ([['name', ''], ['name', '<b>x</b>'], ['name', str_repeat('x', 101)], ['address', '<x>'], ['address', str_repeat('x', 191)], ['city', 'a"b\\c'], ['phone', 'call me'], ['phone', str_repeat('1', 31)], ['dot_number', 'DOT;DROP'], ['mc_number', str_repeat('9', 31)]] as [$key, $bad]) {
    check(isset(CompanyService::validate([$key => $bad] + $ok, null)['errors'][$key]), $key . ' "' . mb_substr($bad, 0, 12) . '" is refused');
}
check(CompanyService::validate(["name" => "  Prime \t  Moving \n"] + $ok, null)['values']['name'] === 'Prime Moving', 'spaces and control characters in the name are tidied');
check(CompanyService::validate(['name' => ['x']] + $ok, null)['errors'] !== [], 'a name that is not text is refused');

section('Companies: pages');
check(str_contains(plain(call('GET', '/companies')->body()), 'No companies yet'), 'the page starts empty');
call('POST', '/companies', $ok);
$c = CompanyService::findByName('prime moving');
check($c !== null && $c['name'] === 'Prime Moving' && $c['dot_number'] === '2521000' && $c['city'] === 'Alpharetta, GA 30009', 'a company is added and found by name ignoring letter case');
call('POST', '/companies', ['name' => 'PRIME MOVING'] + $ok);
check((int) Database::fetchValue('SELECT COUNT(*) FROM companies') === 1, 'a second company with the same name (any letter case) is refused');
call('POST', '/companies', ['name' => '']);
check((int) Database::fetchValue('SELECT COUNT(*) FROM companies') === 1, 'an empty name saves nothing');
check(str_contains(plain(call('GET', '/companies/create', [], ['name' => 'Next Level'])->body()), 'New company') && str_contains(call('GET', '/companies/create', [], ['name' => 'Next Level'])->body(), 'value="Next Level"'), 'the add form can be prefilled with a broker name');
check(call('GET', '/companies/999999/edit')->status() === 404 && call('POST', '/companies/999999', $ok)->status() === 404 && call('POST', '/companies/999999/delete')->status() === 404, 'a company that does not exist is a 404');

$mk = static fn (string $n, array $o = []): int => mk_job($o + ['job_number' => $n, 'status' => 'in_transit', 'carrier_name' => 'KEN', 'driver_name' => 'Dan', 'broker' => 'Prime Moving', 'cf' => '100', 'carrier_rate' => '2.00', 'balance' => '500.00', 'loaded_date' => '2026-11-05']);
$j1 = $mk('C-1');
$j2 = $mk('C-2', ['broker' => 'prime moving', 'cf' => '50', 'carrier_rate' => '3.00', 'balance' => '100.00']);
$j3 = $mk('C-3', ['broker' => 'Next Level', 'cf' => '10', 'carrier_rate' => '5.00', 'balance' => '80.00']);
$list = plain(call('GET', '/companies')->body());
check(str_contains($list, 'Prime Moving') && str_contains($list, '12 Main St, Alpharetta, GA 30009') && str_contains($list, '2521000'), 'the list shows the company and its details');
check(CompanyService::search('')[0]['job_count'] == 2, 'the job count matches the broker name ignoring letter case (2 jobs)');
check(str_contains($list, 'Brokers on jobs with no company details') && str_contains($list, 'Next Level') && !str_contains(call('GET', '/companies')->body(), 'name=Prime'), 'brokers with no details are listed, with an Add details link');
check(CompanyService::unlinked() === [['broker' => 'Next Level', 'jobs' => 1]], 'only the broker with no company record is unlinked');
check(str_contains(plain(call('GET', '/companies', [], ['q' => 'alpha'])->body()), 'Prime Moving') && str_contains(plain(call('GET', '/companies', [], ['q' => 'zzz'])->body()), 'No company matches') && str_contains(plain(call('GET', '/companies', [], ['q' => '2521'])->body()), 'Prime Moving'), 'search finds a company by city or DOT');
check(!str_contains(call('GET', '/companies', [], ['q' => '<script>x</script>'])->body(), '<script>x</script>'), 'a search typed as markup is never output raw');

// the job form's Broker pick list holds the companies too
call('POST', '/companies', ['name' => 'Zeta Freight']);
$form = call('GET', '/jobs/create')->body();
check(str_contains($form, '<option value="Zeta Freight">') && str_contains($form, '<option value="Prime Moving">') && substr_count($form, 'value="Prime Moving"') >= 1 && substr_count(strtolower($form), 'value="prime moving"') === 1, 'the job form offers each company and each used broker once');

// 2. the company statement and the company invoice carry the details
$st = plain(call('GET', '/jobs/statement', [], ['go' => '1', 'company' => 'Prime Moving'])->body());
check(str_contains($st, 'Company Statement') && str_contains($st, '12 Main St, Alpharetta, GA 30009') && str_contains($st, 'DOT: 2521000 · MC: MC-77123') && str_contains($st, 'Phone: +1 (347) 668-4584'), 'a company statement shows the company\'s details');
$stNo = plain(call('GET', '/jobs/statement', [], ['go' => '1', 'company' => 'Next Level'])->body());
check(str_contains($stNo, 'Company Statement') && !str_contains($stNo, 'DOT:'), 'a company with no record shows no details');
call('POST', '/invoices', ['company' => 'Prime Moving']);
$ci = Database::fetchOne("SELECT * FROM invoices WHERE type = 'CI' AND seq = 1");
check($ci !== null && str_contains((string) $ci['company_info'], '2521000') && CompanyService::infoOf($ci)['city'] === 'Alpharetta, GA 30009', 'saving a company invoice copies the company\'s details onto it');
$page = plain(call('GET', '/invoices/' . $ci['id'])->body());
check(str_contains($page, 'Prime Moving') && str_contains($page, '12 Main St, Alpharetta, GA 30009') && str_contains($page, 'DOT: 2521000 · MC: MC-77123'), 'the company invoice shows the details');
call('POST', '/companies/' . $c['id'], ['name' => 'Prime Moving', 'address' => '99 New Road', 'city' => 'Atlanta', 'phone' => '', 'dot_number' => '1', 'mc_number' => ''] + []);
$after = plain(call('GET', '/invoices/' . $ci['id'])->body());
check(str_contains($after, '12 Main St, Alpharetta, GA 30009') && !str_contains($after, '99 New Road'), 'changing the company later does not change the invoice that was already saved');
check(str_contains(plain(call('GET', '/jobs/statement', [], ['go' => '1', 'company' => 'Prime Moving'])->body()), '99 New Road'), 'but a new statement uses the new details');
check(CompanyService::infoOf(['company_info' => null]) === [] && CompanyService::infoOf(['company_info' => 'not json']) === [], 'an invoice without saved details has none');

// generated company invoices copy the details too
call('POST', '/invoices', ['carrier' => 'KEN']);
$di = Database::fetchOne("SELECT * FROM invoices WHERE type = 'DI' AND seq = 1");
call('POST', '/invoices/' . $di['id'] . '/company-invoices');
$gen = Database::fetchOne("SELECT * FROM invoices WHERE type = 'CI' AND broker = 'Prime Moving' AND source_invoice_id IS NOT NULL");
check($gen !== null && CompanyService::infoOf($gen)['address'] === '99 New Road', 'a company invoice generated from a driver invoice copies the company\'s details');
$nl = Database::fetchOne("SELECT * FROM invoices WHERE type = 'CI' AND broker = 'Next Level'");
check($nl !== null && $nl['company_info'] === null, 'a company with no record has no details on its invoice');

// 3. rename
check(CompanyService::jobCount('Prime Moving') === 2, 'two jobs carry the broker name');
call('POST', '/companies/' . $c['id'], ['name' => 'Prime Moving Inc', 'address' => '99 New Road', 'rename_jobs' => '1']);
check(Database::fetchValue('SELECT broker FROM jobs WHERE id = ?', [$j1]) === 'Prime Moving Inc' && Database::fetchValue('SELECT broker FROM jobs WHERE id = ?', [$j2]) === 'Prime Moving Inc' && Database::fetchValue('SELECT broker FROM jobs WHERE id = ?', [$j3]) === 'Next Level', 'renaming with the box ticked changes the broker on its jobs, and only on its jobs');
check(CompanyService::unlinked() === [['broker' => 'Next Level', 'jobs' => 1]], 'the renamed jobs stay linked to the company');
call('POST', '/companies/' . $c['id'], ['name' => 'Prime Again', 'address' => '99 New Road']);
check(Database::fetchValue('SELECT broker FROM jobs WHERE id = ?', [$j1]) === 'Prime Moving Inc' && in_array('Prime Moving Inc', array_column(CompanyService::unlinked(), 'broker'), true), 'renaming with the box not ticked leaves the jobs alone (they then have no details)');
call('POST', '/companies', ['name' => 'Taken']);
call('POST', '/companies/' . $c['id'], ['name' => 'TAKEN']);
check(CompanyService::find((int) $c['id'])['name'] === 'Prime Again', 'renaming to a name another company has is refused');
check(Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'company_created'") == 3 && Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'company_updated'") >= 3, 'adding and changing companies is audited');

// 4. delete
call('POST', '/companies/' . $c['id'] . '/delete');
check(CompanyService::find((int) $c['id']) === null && Database::fetchValue('SELECT broker FROM jobs WHERE id = ?', [$j1]) === 'Prime Moving Inc', 'removing a company keeps the broker name on its jobs');
check(Database::fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'company_deleted'") == 1 && str_contains(plain(call('GET', '/invoices/' . $ci['id'])->body()), '12 Main St, Alpharetta, GA 30009'), 'the removal is audited, and a saved invoice keeps its copy of the details');

// 5. who may
$staff = make_user('Staff', 'p36-staff@example.com');
sign_in($staff);
check(call('GET', '/companies')->status() === 403 && call('POST', '/companies', $ok)->status() === 403 && call('POST', '/companies/1/delete')->status() === 403, 'Staff cannot see or change companies');
check(!str_contains(call('GET', '/dashboard')->body(), 'href="/companies"'), 'and the menu does not list Companies for Staff');
$manager = make_user('Manager', 'p36-mgr@example.com');
sign_in($manager);
check(call('GET', '/companies')->status() === 200 && str_contains(call('GET', '/dashboard')->body(), 'href="/companies"'), 'a Manager can, and the menu lists Companies');
sign_in($admin);
