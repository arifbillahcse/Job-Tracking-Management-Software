<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use Throwable;

/**
 * Read access to the `settings` table with a per-request cache.
 * Falls back to the supplied default when the table is unavailable (e.g. before migrations ran).
 */
final class SettingsService
{
    /** @var array<string,string|null>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            try {
                self::$cache = (new SettingsRepository())->all();
            } catch (Throwable) {
                self::$cache = [];
            }
        }
        return self::$cache[$key] ?? $default;
    }

    /** Drop the cache (after a setting changes, and in tests). */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
