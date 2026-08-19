<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

final class CpfValidator
{
    public function normalize(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    public function isValid(string $value): bool
    {
        $cpf = $this->normalize($value);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
        for ($position = 9; $position <= 10; $position++) {
            $sum = 0;
            for ($index = 0; $index < $position; $index++) $sum += (int) $cpf[$index] * (($position + 1) - $index);
            $digit = (10 * $sum) % 11;
            if ($digit === 10) $digit = 0;
            if ($digit !== (int) $cpf[$position]) return false;
        }
        return true;
    }
}
