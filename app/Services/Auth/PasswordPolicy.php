<?php

declare(strict_types=1);

namespace App\Services\Auth;

final class PasswordPolicy
{
    public static function error(string $password): ?string
    {
        if (strlen($password) < 12
            || !preg_match('/[a-z]/', $password)
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/\d/', $password)
            || !preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Use pelo menos 12 caracteres, com letra maiúscula, minúscula, número e símbolo.';
        }
        return null;
    }
}
