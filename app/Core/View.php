<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Plain PHP templates in /views. Output is escaped in the template with e().
 */
final class View
{
    /**
     * Render a view, optionally wrapped in a layout that receives it as $content.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::capture($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture($layout, $data + ['content' => $content]);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function partial(string $view, array $data = []): string
    {
        return self::capture($view, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function capture(string $view, array $data): string
    {
        $file = BASE_PATH . '/views/' . $view . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$view}");
        }

        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($file, $data);
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
