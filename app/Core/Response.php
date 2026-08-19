<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function redirect(string $path, int $status = 302): string
    {
        header('Location: ' . url($path), true, $status);
        return '';
    }

    public static function redirectAway(string $url, int $status = 303): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || ($host !== 'pagar.me' && !str_ends_with($host, '.pagar.me'))) {
            throw new \InvalidArgumentException('URL externa de pagamento inválida.');
        }
        header('Location: ' . $url, true, $status);
        return '';
    }

    /** @param array<int,string> $allowedHosts */
    public static function redirectExternal(string $url, array $allowedHosts, int $status = 302): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = array_map(static fn (string $allowed): string => strtolower($allowed), $allowedHosts);
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, $allowedHosts, true)) {
            throw new \InvalidArgumentException('URL externa não autorizada.');
        }
        header('Location: ' . $url, true, $status);
        return '';
    }
}
