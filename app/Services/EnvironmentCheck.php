<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Database\Migrator;
use Throwable;

/**
 * Pre-flight / post-deploy self check (php cli/doctor.php). Each result is ok, warn or fail.
 * "fail" means the installation is unsafe or broken; "warn" means check it before going live.
 */
final class EnvironmentCheck
{
    /**
     * @return array<int,array{level:string,label:string,detail:string}>
     */
    public static function run(): array
    {
        $r = [];
        $add = static function (string $level, string $label, string $detail = '') use (&$r): void {
            $r[] = ['level' => $level, 'label' => $label, 'detail' => $detail];
        };
        $production = Config::get('app.env') === 'production';

        PHP_VERSION_ID >= 80100 ? $add('ok', 'PHP ' . PHP_VERSION) : $add('fail', 'PHP 8.1 or newer is required', 'running ' . PHP_VERSION);
        foreach (['pdo', 'mbstring', 'fileinfo', 'zip', 'ctype', 'json'] as $ext) {
            extension_loaded($ext) ? $add('ok', "Extension {$ext}") : $add('fail', "Extension {$ext} is missing", $ext === 'zip' ? 'needed for Excel import/export' : '');
        }
        $driver = (string) Config::get('app.db.driver');
        extension_loaded('pdo_' . $driver) ? $add('ok', "PDO driver {$driver}") : $add('fail', "PDO driver pdo_{$driver} is missing");
        if ($production && $driver !== 'mysql') {
            $add('fail', 'Production must use MySQL/MariaDB', "DB_DRIVER is {$driver}");
        }

        if ($production) {
            Config::get('app.debug') ? $add('fail', 'APP_DEBUG is on in production') : $add('ok', 'Debug output is off');
            $url = (string) Config::get('app.url');
            str_starts_with($url, 'https://') ? $add('ok', 'APP_URL uses HTTPS') : $add('warn', 'APP_URL is not HTTPS', $url);
            ini_get('display_errors') && !in_array(strtolower((string) ini_get('display_errors')), ['0', 'off', 'stderr'], true)
                ? $add('warn', 'PHP display_errors is on', 'set display_errors=Off in php.ini')
                : $add('ok', 'PHP does not display errors');
        } else {
            $add('warn', 'APP_ENV is "' . Config::get('app.env') . '" (fine for development, not for production)');
        }

        foreach (['storage', 'storage/logs', 'storage/imports'] as $dir) {
            $path = BASE_PATH . '/' . $dir;
            is_dir($path) && is_writable($path) ? $add('ok', "{$dir}/ is writable") : $add('fail', "{$dir}/ is not writable", $path);
        }
        is_dir(BASE_PATH . '/public/storage') || is_link(BASE_PATH . '/public/storage')
            ? $add('fail', 'public/storage exists', 'uploaded files must not be inside the web root')
            : $add('ok', 'Uploads are outside the web root');

        $env = BASE_PATH . '/.env';
        if (is_file($env)) {
            $perms = (int) (fileperms($env) & 0777);
            ($perms & 0007) === 0 ? $add('ok', '.env is not world-readable') : $add('warn', '.env is world-readable', sprintf('mode %04o, use chmod 640', $perms));
        } else {
            $add('warn', 'No .env file', 'the built-in defaults are in use (database user root, no password); create .env from deploy/env.production.example');
        }
        is_file(BASE_PATH . '/public/.env') ? $add('fail', 'public/.env exists') : $add('ok', 'No .env inside public/');

        $need = ImportService::MAX_BYTES;
        $limit = min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size')));
        $limit >= $need
            ? $add('ok', 'Uploads up to ' . intdiv($limit, 1048576) . ' MB are allowed')
            : $add('warn', 'PHP upload limit is ' . round($limit / 1048576, 1) . ' MB', 'raise upload_max_filesize and post_max_size to at least ' . intdiv($need, 1048576) . 'M for imports');

        try {
            Database::fetchValue('SELECT 1');
            $add('ok', 'Database connection');
            $pending = array_filter((new Migrator())->status(), static fn (array $m): bool => !$m['applied']);
            $pending === [] ? $add('ok', 'All migrations applied') : $add('fail', count($pending) . ' migration(s) pending', 'run php cli/migrate.php');

            $admins = (int) Database::fetchValue('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND u.deleted_at IS NULL AND r.name = ?', ['Admin']);
            $admins > 0 ? $add('ok', 'An active administrator exists') : $add('fail', 'No active administrator', 'run php cli/create-admin.php');

            if ($production) {
                $demo = (int) Database::fetchValue("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND (email LIKE '%@example.com' OR email LIKE '%@example.org')");
                $demo === 0 ? $add('ok', 'No demo accounts') : $add('fail', "{$demo} demo account(s) (@example.com) exist", 'delete them or change their passwords');
            }
        } catch (Throwable $e) {
            $add('fail', 'Database is not reachable', $e->getMessage());
        }

        return $r;
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
