<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $secure = self::isSecureRequest()
                || strtolower((string) ($_ENV['APP_ENV'] ?? 'local')) === 'production';
            session_name('tuffer_session');
            session_start([
                'cookie_httponly' => true,
                'cookie_secure' => $secure,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
                'use_only_cookies' => true,
            ]);
        }
    }

    public static function get(string $key, mixed $default = null): mixed { return $_SESSION[$key] ?? $default; }
    public static function put(string $key, mixed $value): void { $_SESSION[$key] = $value; }
    public static function forget(string $key): void { unset($_SESSION[$key]); }
    public static function regenerate(): void { session_regenerate_id(true); }

    public static function rememberFor(int $seconds): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || $seconds < 1) {
            return;
        }

        $parameters = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + $seconds,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => self::isSecureRequest()
                || strtolower((string) ($_ENV['APP_ENV'] ?? 'local')) === 'production'
                || (bool) $parameters['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function flash(string $key, mixed $value): void { $_SESSION['_flash'][$key] = $value; }

    public static function pullFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    private static function isSecureRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
        if (!filter_var($_ENV['TRUST_PROXY_HEADERS'] ?? false, FILTER_VALIDATE_BOOL)) return false;
        return strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
    }
}
