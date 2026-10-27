<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;
use App\Services\SettingsService;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return BASE_PATH . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }
}

/** Escape a value for HTML output. Use on every dynamic value in a template. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** True for same-site paths like "/dashboard" - rejects "//host", "http://..." and control characters. */
function is_internal_path(string $path): bool
{
    return $path !== ''
        && $path[0] === '/'
        && !str_starts_with($path, '//')
        && !str_contains($path, '\\')
        && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
}

/** Absolute-path URL inside the app, honouring a sub-folder install. */
function url(string $path = ''): string
{
    $base = (string) Config::get('app.base_path', '');
    return $base . '/' . ltrim($path, '/');
}

/** URL of a file under public/assets with a cache-busting version. */
function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $version = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('assets/' . ltrim($path, '/')) . $version;
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

/** Value submitted on the previous request (flashed on validation failure). */
function old(string $key, string $default = ''): string
{
    $old = Session::flashed('old', []);
    $value = is_array($old) ? ($old[$key] ?? $default) : $default;
    return is_scalar($value) ? (string) $value : $default;
}

/**
 * @return array<int,string>
 */
function field_errors(string $field): array
{
    $errors = Session::flashed('errors', []);
    return is_array($errors) && isset($errors[$field]) ? (array) $errors[$field] : [];
}

function has_error(string $field): bool
{
    return field_errors($field) !== [];
}

/** Renders the validation message(s) for a field, if any. */
function error_text(string $field): string
{
    $errors = field_errors($field);
    return $errors === [] ? '' : '<div class="invalid-feedback d-block">' . e($errors[0]) . '</div>';
}

/**
 * @return array{type:string,message:string}|null
 */
function alert(): ?array
{
    $alert = Session::flashed('alert');
    return is_array($alert) && isset($alert['type'], $alert['message']) ? $alert : null;
}

function can(string $permission): bool
{
    return Auth::can($permission);
}

/**
 * @return array<string,mixed>|null
 */
function auth_user(): ?array
{
    return Auth::user();
}

/** Value from the settings table, falling back to $default if the table is unavailable. */
function setting(string $key, ?string $default = null): ?string
{
    return SettingsService::get($key, $default);
}

/** Release number from the VERSION file ("dev" when it is missing). */
function app_version(): string
{
    static $version = null;
    return $version ??= (is_file(BASE_PATH . '/VERSION') ? trim((string) file_get_contents(BASE_PATH . '/VERSION')) : 'dev');
}

function app_name(): string
{
    return setting('company_name', (string) Config::get('app.name', 'Job Tracker')) ?? 'Job Tracker';
}

/** Inline SVG icon (Feather-style paths, no external font needed). Output is static markup, safe to echo. */
function icon(string $name, string $class = ''): string
{
    static $paths = [
        'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'menu' => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'alert-triangle' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'edit' => '<path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>',
        'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'rotate-ccw' => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
        'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'sliders' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'chevron-up' => '<polyline points="18 15 12 9 6 15"/>',
        'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
        'chevrons' => '<polyline points="7 15 12 20 17 15"/><polyline points="7 9 12 4 17 9"/>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'dollar' => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'alert-circle' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'unlock' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'key' => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
        'truck' => '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    ];

    return '<svg class="icon ' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/** True when a locally vendored Bootstrap build is present. */
function has_vendored_bootstrap(): bool
{
    return is_file(BASE_PATH . '/public/assets/vendor/bootstrap/css/bootstrap.min.css')
        && is_file(BASE_PATH . '/public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js');
}

/** Submitted value if the previous request flashed one for this field, otherwise $fallback (edit forms). */
function old_or(string $key, mixed $fallback): string
{
    $old = Session::flashed('old', []);
    if (is_array($old) && array_key_exists($key, $old) && is_scalar($old[$key])) {
        return (string) $old[$key];
    }
    return is_scalar($fallback) ? (string) $fallback : '';
}

/** "Nov 6, 2026" from a Y-m-d date; '' for empty or invalid input. */
function fmt_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
    return $parsed === false ? '' : $parsed->format('M j, Y');
}

/** UTC timestamp from the database shown in the configured time zone. */
function fmt_datetime(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '';
    }
    try {
        $zone = new DateTimeZone(setting('timezone', 'UTC') ?: 'UTC');
    } catch (Throwable) {
        $zone = new DateTimeZone('UTC');
    }
    try {
        return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->setTimezone($zone)->format('M j, Y g:i A');
    } catch (Throwable) {
        return '';
    }
}

/** Currency amount with the symbol from settings, e.g. "$1,200.50". Plain text - escape with e(). */
function fmt_money(mixed $amount): string
{
    if ($amount === null || $amount === '' || !is_numeric($amount)) {
        return '';
    }
    return (setting('currency_symbol', '$') ?? '$') . number_format((float) $amount, 2);
}

/** Number with up to two decimals and no trailing zeros ("900", "12.5"). */
function fmt_number(mixed $number): string
{
    if ($number === null || $number === '' || !is_numeric($number)) {
        return '';
    }
    return rtrim(rtrim(number_format((float) $number, 2, '.', ','), '0'), '.');
}

/** Coloured status pill. Unknown statuses fall back to a neutral label. Output is escaped HTML. */
function status_badge(string $status): string
{
    $definition = Config::get('statuses.statuses.' . $status);
    $label = is_array($definition) ? (string) $definition['label'] : ucfirst(str_replace('_', ' ', $status));
    $class = is_array($definition) ? (string) $definition['badge'] : 'booked';
    return '<span class="jt-badge jt-badge-' . e($class) . '">' . e($label) . '</span>';
}

/** Link to $page of a list, keeping the current query string (search, filters, sort). */
function page_url(string $path, int $page): string
{
    $query = $_GET;
    $query['page'] = $page;
    return url($path) . '?' . http_build_query($query);
}

/** URL of a list page with the given query parameters (empty values are dropped). */
function list_url(string $path, array $params = []): string
{
    $params = array_filter($params, static fn (mixed $v): bool => $v !== '' && $v !== null);
    return url($path) . ($params === [] ? '' : '?' . http_build_query($params));
}

/** Days-until chip next to a booked job's available date, e.g. "in 5 days" / "Overdue by 2 days". HTML, escaped. */
function availability_chip(array $job): string
{
    $hint = \App\Services\JobBucket::availability($job);
    return $hint === null ? '' : '<span class="jt-chip jt-chip-' . e($hint['tone']) . '">' . e($hint['text']) . '</span>';
}

/** One line explaining where a job's storage fee comes from (plain text; escape with e()). */
function storage_fee_note(array $job): string
{
    $result = \App\Services\StorageFeeService::assess($job);
    if ($result['manual']) {
        return 'Set manually (overrides the automatic fee)';
    }
    if (($job['status'] ?? '') === 'cancelled') {
        return 'No storage fee on a cancelled job';
    }
    if ((float) $result['fee'] > 0) {
        $months = (int) ($result['months'] ?? 1);
        return 'Automatic: A-CF ' . fmt_number($job['cf']) . ' × rate ' . (string) (float) $result['rate']
            . ($months > 1 ? ' × ' . $months . ' months' : '') . ' (after ' . \App\Services\StorageFeeService::days() . ' days)';
    }
    if (($job['cf'] ?? null) === null || (float) $job['cf'] <= 0) {
        return 'Automatic: needs a CF to be calculated';
    }
    $start = \App\Services\StorageFeeService::startsOn($job);
    if ($start === null) {
        return 'Automatic: counted from the ' . \App\Services\StorageFeeService::BASIS[\App\Services\StorageFeeService::basis()] . ', which is not set yet';
    }
    return ($job['status'] ?? '') === 'delivered'
        ? 'Automatic: delivered before the fee started'
        : 'Automatic: starts on ' . fmt_date($start) . ' (CF × rate ' . (string) (float) \App\Services\StorageFeeService::rate() . ')';
}

/** Tap-to-call link for a phone number; plain text when it has too few digits to dial. HTML, escaped. */
function tel_link(?string $phone): string
{
    $phone = trim((string) $phone);
    if ($phone === '') {
        return '—';
    }
    $dial = preg_replace('/[^0-9+]/', '', $phone) ?? '';
    return strlen($dial) >= 7
        ? '<a class="jt-tel" href="tel:' . e($dial) . '">' . icon('phone') . ' ' . e($phone) . '</a>'
        : e($phone);
}

/** Path (with query string) of a job section list, e.g. list_path('ready', ['q' => 'ada']) -> /jobs/ready?q=ada */
function list_path(string $bucket, array $params = []): string
{
    $path = \App\Services\JobBucket::definitions()[$bucket]['path'] ?? '/jobs';
    $params = array_filter($params, static fn (mixed $v): bool => $v !== '' && $v !== null);
    return $path . ($params === [] ? '' : '?' . http_build_query($params));
}
