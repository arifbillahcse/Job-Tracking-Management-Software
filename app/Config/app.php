<?php

declare(strict_types=1);

use App\Core\Env;

$url = rtrim((string) Env::get('APP_URL', 'http://localhost:8000'), '/');
$env = (string) Env::get('APP_ENV', 'production');

return [
    'name' => 'Job Tracker',
    'env' => $env,
    'debug' => Env::bool('APP_DEBUG', false) && $env !== 'production',
    'url' => $url,
    // Sub-folder the app is served from, e.g. "/job-tracker/public"; empty when served from a domain root.
    'base_path' => rtrim((string) parse_url($url, PHP_URL_PATH), '/'),
    // Only enable behind a reverse proxy/CDN you control; otherwise X-Forwarded-* headers are ignored.
    'trust_proxy' => Env::bool('TRUST_PROXY', false),
    // Development convenience: load Bootstrap from a CDN when public/assets/vendor is empty.
    'allow_cdn_fallback' => $env !== 'production',

    'db' => [
        'driver' => (string) Env::get('DB_DRIVER', 'mysql'), // "sqlite" is for tests/local development only
        'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::int('DB_PORT', 3306),
        'database' => (string) Env::get('DB_DATABASE', 'job_tracker'),
        'username' => (string) Env::get('DB_USERNAME', 'root'),
        'password' => (string) Env::get('DB_PASSWORD', ''),
    ],

    'session' => [
        'name' => 'JT_SESSID',
        'idle_minutes' => Env::int('SESSION_IDLE_MINUTES', 30),
        'absolute_hours' => Env::int('SESSION_ABSOLUTE_HOURS', 8),
        'secure' => Env::bool('SESSION_SECURE', false), // forced on automatically over HTTPS
    ],

    // "Keep me signed in": days a remembered browser stays signed in (0 turns the checkbox off). Fixed from the day it was ticked.
    'remember' => [
        'days' => Env::int('REMEMBER_ME_DAYS', 14),
    ],

    'auth' => [
        'max_failed_attempts' => 5,   // per account before a temporary lock
        'lockout_minutes' => 15,
        'ip_max_failures' => 20,      // failed attempts per IP inside the window
        'ip_window_minutes' => 15,
    ],

    'password' => [
        'min_length' => 10,
        // Argon2id defaults; password_needs_rehash() upgrades hashes automatically if these change.
        'options' => [],
    ],
];
