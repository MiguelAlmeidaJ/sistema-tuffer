<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    /** @var array<string, mixed> */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function shared(string $key, mixed $default = null): mixed
    {
        return self::$shared[$key] ?? $default;
    }

    /** @param array<string, mixed> $data */
    public static function render(string $view, array $data = []): string
    {
        $viewPath = dirname(__DIR__, 2) . '/resources/views/' . $view . '.php';

        if (!is_file($viewPath)) {
            throw new RuntimeException("View não encontrada: {$view}");
        }

        extract(array_merge(self::$shared, $data), EXTR_SKIP);
        ob_start();
        require $viewPath;

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $data */
    public static function page(string $view, string $layout, array $data = []): string
    {
        $content = self::render($view, $data);

        return self::render($layout, array_merge($data, ['content' => $content]));
    }
}
