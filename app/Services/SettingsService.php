<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Repositories\SettingsRepository;
use DateTimeZone;
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

    public const PAGE_SIZES = ['10', '25', '50', '100'];

    /**
     * Current value of every editable setting (stored value, else the shipped default).
     *
     * @return array<string,string>
     */
    public static function editable(): array
    {
        $defaults = (array) Config::get('settings', []);
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = (string) (self::get($key) ?? ($defaults[$key] ?? ''));
        }
        return $out;
    }

    /** Settings the page may change. Anything else in the table (or in a request) is ignored. */
    public const KEYS = ['company_name', 'ready_threshold_days', 'currency_symbol', 'timezone', 'page_size'];

    /**
     * @param array<string,mixed> $input
     * @return array{values:array<string,string>,errors:array<string,string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $values = [];

        $name = self::clean($input['company_name'] ?? '');
        if ($name === '' || mb_strlen($name) > 60) {
            $errors['company_name'] = 'Enter a company name of up to 60 characters.';
        }
        $values['company_name'] = $name;

        $days = is_string($input['ready_threshold_days'] ?? null) ? trim($input['ready_threshold_days']) : '';
        if (filter_var($days, FILTER_VALIDATE_INT) === false || (int) $days < 1 || (int) $days > 365) {
            $errors['ready_threshold_days'] = 'Enter a whole number of days from 1 to 365.';
        }
        $values['ready_threshold_days'] = (string) (int) $days;

        $symbol = self::clean($input['currency_symbol'] ?? '');
        if ($symbol === '' || mb_strlen($symbol) > 3) {
            $errors['currency_symbol'] = 'Enter a currency symbol of 1 to 3 characters.';
        }
        $values['currency_symbol'] = $symbol;

        $zone = is_string($input['timezone'] ?? null) ? trim($input['timezone']) : '';
        if (!in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Choose a time zone from the list.';
        }
        $values['timezone'] = $zone;

        $size = is_string($input['page_size'] ?? null) ? trim($input['page_size']) : '';
        if (!in_array($size, self::PAGE_SIZES, true)) {
            $errors['page_size'] = 'Choose one of ' . implode(', ', self::PAGE_SIZES) . '.';
        }
        $values['page_size'] = $size;

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Save validated values. Returns only what actually changed, as old/new pairs; the change and its
     * audit entry are written together.
     *
     * @param array<string,string> $values
     * @return array{old:array<string,string>,new:array<string,string>}
     */
    public static function update(array $values): array
    {
        $current = self::editable();
        $old = [];
        $new = [];
        foreach (self::KEYS as $key) {
            if (isset($values[$key]) && $values[$key] !== $current[$key]) {
                $old[$key] = $current[$key];
                $new[$key] = $values[$key];
            }
        }
        if ($new === []) {
            return ['old' => [], 'new' => []];
        }

        Database::transaction(static function () use ($new, $old): void {
            $repo = new SettingsRepository();
            foreach ($new as $key => $value) {
                $repo->set($key, $value);
            }
            AuditService::record('settings_updated', 'settings', null, $old, $new);
        });
        self::flush();

        return ['old' => $old, 'new' => $new];
    }

    /** Collapse whitespace and drop control characters. */
    private static function clean(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $value));
    }
}
