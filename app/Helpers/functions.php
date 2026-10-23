<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;
use App\Repositories\SettingsRepository;

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
    static $all = null;
    if ($all === null) {
        try {
            $all = (new SettingsRepository())->all();
        } catch (Throwable) {
            $all = [];
        }
    }
    return $all[$key] ?? $default;
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
