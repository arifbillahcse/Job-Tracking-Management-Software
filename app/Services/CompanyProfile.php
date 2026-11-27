<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * The company details printed at the top of the Carrier Statement (client request, v1.13.0): name, address, phone, DOT and MC number.
 * They live in Settings (the name is the existing company name), so they can be corrected without a code change. Every line is
 * optional; an empty one is simply not printed. An optional logo is shown when the file public/assets/img/company-logo.png exists.
 */
final class CompanyProfile
{
    public const LOGO_PATH = 'assets/img/company-logo.png';

    /** key => [label, maximum length, pattern the text must match] */
    public const FIELDS = [
        'company_address' => ['Address', 190, '/^[^<>"\\\\]+$/u'],
        'company_phone' => ['Phone', 30, '/^[0-9 ()+.\\-\\/xX#]+$/'],
        'company_dot' => ['DOT number', 30, '/^[A-Za-z0-9 \\-#.]+$/'],
        'company_mc' => ['MC number', 30, '/^[A-Za-z0-9 \\-#.]+$/'],
    ];

    private static function value(string $key): string
    {
        $default = (string) (((array) Config::get('settings', []))[$key] ?? '');
        return trim((string) (SettingsService::get($key) ?? $default));
    }

    public static function name(): string
    {
        return trim((string) (SettingsService::get('company_name') ?? 'Job Tracker'));
    }

    public static function address(): string
    {
        return self::value('company_address');
    }

    public static function phone(): string
    {
        return self::value('company_phone');
    }

    public static function dot(): string
    {
        return self::value('company_dot');
    }

    public static function mc(): string
    {
        return self::value('company_mc');
    }

    /** The logo's address when the file exists, else ''. */
    public static function logoUrl(): string
    {
        return is_file(dirname(__DIR__, 2) . '/public/' . self::LOGO_PATH) ? url('/' . self::LOGO_PATH) : '';
    }
}
