<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    /** @var array<string, mixed>|null|false */
    private static array|null|false $user = false;

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        if (self::$user !== false) {
            return self::$user;
        }

        $id = Session::get('user_id');
        if (!$id) {
            return self::$user = null;
        }

        $statement = Database::connection()->prepare('SELECT id, name, email, type, status, auth_version FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $user = $statement->fetch();

        if (!$user || ($user['status'] ?? null) !== 'active') {
            self::logout();
            return null;
        }

        if ((int) ($user['auth_version'] ?? 1) !== (int) Session::get('auth_version', 1)) {
            self::logout();
            return null;
        }

        return self::$user = ($user ?: null);
    }

    public static function check(): bool { return self::user() !== null; }
    public static function id(): ?int { return isset(self::user()['id']) ? (int) self::user()['id'] : null; }

    /** @param array<string, mixed> $user */
    public static function login(array $user): void
    {
        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        Session::put('auth_version', (int) ($user['auth_version'] ?? 1));
        self::$user = $user;
    }

    public static function logout(): void
    {
        Session::forget('user_id');
        Session::forget('auth_version');
        Session::forget('admin_last_activity');
        Session::regenerate();
        self::$user = null;
    }
}
