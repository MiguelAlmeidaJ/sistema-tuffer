<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

final class CnpjValidator
{
    public function normalize(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    public function isValid(string $value): bool
    {
        $cnpj = $this->normalize($value);
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        return $this->checkDigit($cnpj, 12) && $this->checkDigit($cnpj, 13);
    }

    private function checkDigit(string $cnpj, int $position): bool
    {
        $weights = $position === 12
            ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
            : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += (int) $cnpj[$index] * $weight;
        }
        $remainder = $sum % 11;
        return (int) $cnpj[$position] === ($remainder < 2 ? 0 : 11 - $remainder);
    }
}
