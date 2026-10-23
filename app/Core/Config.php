<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Reads app/Config/<file>.php with dot notation: Config::get('app.session.idle_minutes').
 */
final class Config
{
    /** @var array<string,array<string,mixed>> */
    private static array $files = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);
        $value = self::file($file);
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Override a value for the current process (used by tests). */
    public static function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);
        $data = self::file($file);
        $ref = &$data;
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
        self::$files[$file] = $data;
    }

    /**
     * @return array<string,mixed>
     */
    private static function file(string $name): array
    {
        if (!isset(self::$files[$name])) {
            $path = BASE_PATH . '/app/Config/' . $name . '.php';
            self::$files[$name] = is_file($path) ? (array) require $path : [];
        }
        return self::$files[$name];
    }
}
