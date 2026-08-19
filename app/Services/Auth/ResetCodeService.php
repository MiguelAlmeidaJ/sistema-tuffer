<?php

declare(strict_types=1);

namespace App\Services\Auth;

final class ResetCodeService
{
    public function generate(): string
    {
        return (string) random_int(10000000, 99999999);
    }

    public function hash(string $code): string
    {
        return password_hash($code, PASSWORD_DEFAULT);
    }

    public function verify(string $code, string $hash): bool
    {
        return password_verify($code, $hash);
    }
}
