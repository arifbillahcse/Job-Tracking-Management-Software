<?php

declare(strict_types=1);

/**
 * v1.10.0: the Driver Tracking / Job Tracking buttons at the very top of every signed-in page, with their addresses in Settings.
 * Included by tests/run.php (after phase25.php).
 */

use App\Core\Clock;
use App\Core\Database;
use App\Services\SettingsService;
use App\Services\TrackingLinks;

section('Tracking buttons: address checks');
foreach (['https://app.bhrelocationinc.com/', 'https://app.example.com/a/b?x=1&y=2', 'HTTP://Example.com/'] as $ok) {
    check(TrackingLinks::parse($ok)['error'] === null && TrackingLinks::parse($ok)['value'] === $ok, "accepted: {$ok}");
}
check(TrackingLinks::parse('')['error'] === null && TrackingLinks::parse('   ')['value'] === '', 'an empty address is allowed (the button is hidden)');
foreach (['javascript:alert(1)', 'ftp://example.com/x', 'example.com/page', '//example.com', 'http://', 'http://exa mple.com', "http://example.com/\"onclick=\"x", 'http://example.com/<b>', 'data:text/html,hi', 'http://user:pass@example.com/', 'https://' . str_repeat('a', 300) . '.com'] as $bad) {
    check(TrackingLinks::parse($bad)['error'] !== null, 'refused: ' . substr($bad, 0, 40));
}
check(TrackingLinks::parse(['x'])['error'] !== null, 'something that is not text is refused');

section('Tracking buttons: on the page');
fresh_db();
fresh_session();
Clock::fake('2026-11-20');
$admin = make_user('Admin', 'p26-admin@example.com');
sign_in($admin);
$html = call('GET', '/dashboard')->body();
check(TrackingLinks::driver() === 'https://app.bhrelocationinc.com/' && TrackingLinks::job() === 'http://app.americanprovanline.com/', 'the shipped addresses are the two the client gave');
check(preg_match('~<nav class="jt-tracklinks"[^>]*>.*?href="https://app\.bhrelocationinc\.com/"[^>]*>.*?Driver Tracking</a>.*?href="http://app\.americanprovanline\.com/"[^>]*>.*?Job Tracking</a>~s', $html) === 1, 'the page has the two buttons: Driver Tracking and Job Tracking, each opening its address in a new tab');
check(strpos($html, 'jt-tracklinks') < strpos($html, 'class="jt-topbar"'), 'the bar is above the top bar, at the very top');
foreach (['/jobs', '/jobs/calendar', '/trips', '/settings', '/jobs/create'] as $path) {
    check(str_contains(call('GET', $path)->body(), 'class="jt-tracklinks"'), "{$path} has the bar too");
}
fresh_session();
check(!str_contains(call('GET', '/login')->body(), 'jt-tracklinks'), 'the sign-in page does not have it');
sign_in($admin);

section('Tracking buttons: Settings');
check(str_contains(call('GET', '/settings')->body(), 'name="tracking_driver_url"') && str_contains(call('GET', '/settings')->body(), 'name="tracking_job_url"'), 'the Settings page has both addresses');
$save = static fn (array $over) => call('POST', '/settings', $over + SettingsService::editable());
$save(['tracking_job_url' => 'https://app.americanprovanline.com/job-tracking.php']);
SettingsService::flush();
check(TrackingLinks::job() === 'https://app.americanprovanline.com/job-tracking.php' && str_contains(call('GET', '/dashboard')->body(), 'href="https://app.americanprovanline.com/job-tracking.php"'), 'changing the Job Tracking address changes the button');
$save(['tracking_driver_url' => 'javascript:alert(1)']);
SettingsService::flush();
check(TrackingLinks::driver() === 'https://app.bhrelocationinc.com/', 'a bad address is refused and the saved one stays');
$save(['tracking_driver_url' => '']);
SettingsService::flush();
$d = call('GET', '/dashboard')->body();
check(!str_contains($d, 'Driver Tracking') && str_contains($d, 'Job Tracking'), 'an empty address hides only that button');
$save(['tracking_driver_url' => '', 'tracking_job_url' => '']);
SettingsService::flush();
check(!str_contains(call('GET', '/dashboard')->body(), 'jt-tracklinks'), 'with both addresses empty the bar is not shown at all');
call('POST', '/settings/storage-fee', ['storage_fee_rate' => '0.7', 'storage_fee_after_days' => '30', 'storage_fee_basis' => 'pickup', 'storage_fee_mode' => 'monthly']);
SettingsService::flush();
check(TrackingLinks::any() === false, 'the storage-fee-only page leaves the addresses alone');
$save(['tracking_driver_url' => 'http://app.bhrelocationinc.com/driver-tracking.php?a=1&b="2"']);
check(TrackingLinks::driver() === '', 'an address with a quote is refused');
$save(['tracking_driver_url' => 'http://app.bhrelocationinc.com/driver-tracking.php?a=1&b=2']);
SettingsService::flush();
check(str_contains(call('GET', '/dashboard')->body(), 'driver-tracking.php?a=1&amp;b=2'), 'an & in an address is escaped in the page');
$aud = (string) Database::fetchValue("SELECT new_values FROM audit_logs WHERE action = 'settings_updated' ORDER BY id DESC LIMIT 1");
check(str_contains($aud, 'tracking_driver_url'), 'the change is in the audit log');
