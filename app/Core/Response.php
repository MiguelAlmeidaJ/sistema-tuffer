<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

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

    /** @param array<string,mixed> $payload */
    public static function json(array $payload, int $status = 200): string
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function privateFile(string $path, string $mimeType, string $downloadName): string
    {
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Arquivo privado não encontrado.');
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($downloadName)) ?: 'documento';
        $contents = file_get_contents($path);
        if (!is_string($contents)) throw new RuntimeException('Não foi possível ler o arquivo privado.');
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . strlen($contents));
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        return $contents;
    }
}
