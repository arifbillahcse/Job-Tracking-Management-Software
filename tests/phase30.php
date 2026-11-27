<?php

declare(strict_types=1);

/**
 * v1.13.0 (print phase 1): the Carrier Statement has a printable header (company details from Settings) and a Remarks / Signature footer.
 * Included by tests/run.php (after phase29.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Services\CompanyProfile;
use App\Services\SettingsService;

section('Statement header: company details in Settings');
fresh_db();
fresh_session();
Clock::fake('2026-11-16');
$admin = make_user('Admin', 'p30-admin@example.com');
sign_in($admin);

$base = SettingsService::editable();
$ok = static fn (array $over): array => SettingsService::validate($over + $base);
check($ok(['company_address' => '12 Main St, Alpharetta, GA 30009', 'company_phone' => '+1 (347) 668-4584', 'company_dot' => '2521000', 'company_mc' => 'MC-77123'])['errors'] === [], 'a normal address, phone, DOT and MC are accepted');
foreach ([['company_address', '<b>x</b>'], ['company_address', str_repeat('x', 191)], ['company_phone', 'call me'], ['company_phone', str_repeat('1', 31)], ['company_dot', 'DOT;DROP'], ['company_mc', str_repeat('9', 31)]] as [$key, $bad]) {
    check(isset($ok([$key => $bad])['errors'][$key]), $key . ' "' . mb_substr($bad, 0, 12) . '" is refused');
}
check($ok(['company_address' => "  12   Main\tSt \n"])['values']['company_address'] === '12 Main St', 'spaces and control characters are tidied');
check($ok(['company_phone' => ''])['errors'] === [] && $ok(['company_dot' => ''])['values']['company_dot'] === '', 'every line may be empty');

$mk = static fn (string $n, array $o = []): int => mk_job($o + ['job_number' => $n, 'status' => 'in_transit', 'carrier_name' => 'KEN', 'driver_name' => 'Dan', 'driver_phone' => '973-718-0688', 'cf' => '900', 'carrier_rate' => '2.50', 'balance' => '2275.74', 'loaded_date' => '2026-11-05']);
$mk('H-1');
$page = static fn (array $q) => plain(call('GET', '/jobs/statement', [], $q + ['go' => '1'])->body());

$bare = $page(['driver' => 'Dan']);
check(str_contains($bare, 'Driver Statement') && str_contains($bare, 'Dan') && str_contains($bare, 'Date: Nov 16, 2026') && str_contains($bare, 'Period: all dates'), 'the header has the title, the driver, the date and the period');
check(str_contains($bare, 'Phone: 973-718-0688'), 'the driver\'s phone (from the job) is in the header');
check(!str_contains($bare, 'DOT:') && !str_contains($bare, 'MC:'), 'with nothing in Settings, no DOT or MC line is printed');
check(str_contains($bare, 'Remarks') && str_contains($bare, 'Signature'), 'the footer has Remarks and Signature');
check(str_contains($page(['carrier' => 'KEN']), 'Carrier Statement'), 'a carrier-only statement is titled Carrier Statement');
check(str_contains($page(['carrier' => 'KEN', 'driver' => 'Dan']), 'Carrier: KEN'), 'with both, the driver is the subject and the carrier is named');

$r = call('POST', '/settings', ['company_address' => '12 Main St, Alpharetta, GA 30009', 'company_phone' => '+1 (347) 668-4584', 'company_dot' => '2521000', 'company_mc' => 'MC-77123'] + $base);
check(CompanyProfile::dot() === '2521000' && CompanyProfile::address() === '12 Main St, Alpharetta, GA 30009', 'saving Settings stores the company details');
$full = $page(['driver' => 'Dan']);
check(str_contains($full, '12 Main St, Alpharetta, GA 30009') && str_contains($full, 'DOT: 2521000 · MC: MC-77123') && str_contains($full, 'Phone: +1 (347) 668-4584'), 'the saved details are in the header');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'settings_updated' ORDER BY id DESC LIMIT 1");
check(str_contains($aud, 'company_dot'), 'the change is audited');

call('POST', '/settings', ['company_address' => '<script>alert(1)</script>'] + $base);
check(CompanyProfile::address() === '12 Main St, Alpharetta, GA 30009', 'an address with markup is refused and the saved one stays');
check(str_contains(call('GET', '/settings')->body(), 'name="company_dot"') && str_contains(call('GET', '/settings')->body(), 'company-logo.png'), 'the Settings page has the fields and says where the logo goes');

section('Statement header: logo');
$body = call('GET', '/jobs/statement', [], ['go' => '1', 'driver' => 'Dan'])->body();
check(!str_contains($body, 'jt-stmt-logo') && CompanyProfile::logoUrl() === '', 'with no logo file there is no image');
$file = dirname(__DIR__) . '/public/' . CompanyProfile::LOGO_PATH;
@mkdir(dirname($file), 0775, true);
file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='));
check(str_contains(call('GET', '/jobs/statement', [], ['go' => '1', 'driver' => 'Dan'])->body(), 'class="jt-stmt-logo" src="/assets/img/company-logo.png"'), 'with a logo file the header shows it');
@unlink($file);
check(CompanyProfile::logoUrl() === '', 'the test logo is removed again');
