<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The fixed list of carriers (client request, v1.9.0), kept in the `carriers` setting, one name per line, and edited on the Settings page.
 *
 * While the list is empty nothing is required (the app works as before). Once it has names, moving a job to In Transit needs one
 * of them. The list can hold any name, a foreman's too (a foreman is sometimes the carrier). A job keeps the carrier name it was
 * given as plain text, so renaming a carrier here does not rename it on jobs that already have it.
 */
final class CarrierList
{
    public const MAX_NAMES = 200;
    public const MAX_LENGTH = 100;

    /** @return array<int,string> the carriers, in the order they were typed */
    public static function all(): array
    {
        $text = (string) SettingsService::get('carriers', '');
        return $text === '' ? [] : array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $n): bool => $n !== ''));
    }

    /** True when the list has names: a carrier must then be chosen when a job goes In Transit. */
    public static function required(): bool
    {
        return self::all() !== [];
    }

    /** The list's own spelling of a name (matched ignoring letter case), or null when it is not on the list. */
    public static function find(string $name): ?string
    {
        $needle = mb_strtolower(trim($name));
        foreach (self::all() as $carrier) {
            if (mb_strtolower($carrier) === $needle) {
                return $carrier;
            }
        }
        return null;
    }

    /**
     * Turn the text typed on the Settings page into the stored list.
     *
     * @return array{value:string,error:?string} one name per line, tidy; or the problem
     */
    public static function parse(mixed $text): array
    {
        if (!is_string($text)) {
            return ['value' => '', 'error' => 'The carrier list must be text, one name per line.'];
        }
        $names = [];
        $seen = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $name = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > self::MAX_LENGTH) {
                return ['value' => '', 'error' => 'A carrier name can be at most ' . self::MAX_LENGTH . ' characters: "' . mb_substr($name, 0, 30) . '..."'];
            }
            if (preg_match('/[\x00-\x1F\x7F<>]/u', $name) === 1) {
                return ['value' => '', 'error' => 'A carrier name cannot contain < or > or control characters: "' . mb_substr($name, 0, 30) . '"'];
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue; // the same name twice is kept once
            }
            $seen[$key] = true;
            $names[] = $name;
        }
        if (count($names) > self::MAX_NAMES) {
            return ['value' => '', 'error' => 'The list can hold at most ' . self::MAX_NAMES . ' carriers.'];
        }
        return ['value' => implode("\n", $names), 'error' => null];
    }
}
