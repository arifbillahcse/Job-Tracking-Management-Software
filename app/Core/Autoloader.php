<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal PSR-4 autoloader so the app runs without Composer.
 */
final class Autoloader
{
    /**
     * @param array<string,string> $prefixes namespace prefix => base directory
     */
    public static function register(array $prefixes): void
    {
        spl_autoload_register(static function (string $class) use ($prefixes): void {
            foreach ($prefixes as $prefix => $dir) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }
                $relative = substr($class, strlen($prefix));
                $file = rtrim($dir, '/') . '/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        });
    }
}
