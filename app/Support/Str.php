<?php

declare(strict_types=1);

namespace App\Support;

final class Str
{
    public static function slug(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($value))) ?: '';
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
    }
}
