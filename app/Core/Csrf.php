<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put('_token', $token);
        }
        return $token;
    }

    public static function valid(?string $token): bool
    {
        return is_string($token) && hash_equals(self::token(), $token);
    }
}
