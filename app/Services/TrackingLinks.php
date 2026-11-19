<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * The two orange buttons at the very top of every page (client request, v1.10.0): Driver Tracking and Job Tracking.
 * Each button opens an outside web address in a new tab. The addresses live in Settings, so they can be corrected without a
 * code change; an address left empty hides its button, and with both empty the bar is not shown at all.
 * Only http and https addresses are accepted (never javascript: or other schemes).
 */
final class TrackingLinks
{
    public const MAX_LENGTH = 300;

    /** @return string the saved address, or the shipped one when it was never saved ('' = no button) */
    private static function value(string $key): string
    {
        $default = (string) (((array) Config::get('settings', []))[$key] ?? '');
        return trim((string) (SettingsService::get($key) ?? $default));
    }

    public static function driver(): string
    {
        return self::value('tracking_driver_url');
    }

    public static function job(): string
    {
        return self::value('tracking_job_url');
    }

    public static function any(): bool
    {
        return self::driver() !== '' || self::job() !== '';
    }

    /**
     * Check what was typed on the Settings page.
     *
     * @return array{value:string,error:?string}
     */
    public static function parse(mixed $input): array
    {
        if (!is_string($input)) {
            return ['value' => '', 'error' => 'Enter a web address such as https://example.com/page, or leave it empty.'];
        }
        $url = trim($input);
        if ($url === '') {
            return ['value' => '', 'error' => null];
        }
        $parts = parse_url($url);
        if (mb_strlen($url) > self::MAX_LENGTH
            || preg_match('/[\s\x00-\x1F\x7F<>"\'\\\\]/u', $url) === 1
            || $parts === false
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ['value' => '', 'error' => 'Enter a web address that starts with http:// or https://, up to ' . self::MAX_LENGTH . ' characters, with no spaces, or leave it empty.'];
        }
        return ['value' => $url, 'error' => null];
    }
}
