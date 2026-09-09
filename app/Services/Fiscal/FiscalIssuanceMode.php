<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

final class FiscalIssuanceMode
{
    /** Valor legado apenas para compatibilidade com código/migrations antigos. normalize() nunca o retorna. */
    public const PLATFORM = 'platform';
    public const MANUAL = 'manual';
    public const EXTERNAL = 'external';

    public static function normalize(?string $mode): string
    {
        $mode = mb_strtolower(trim((string) $mode));
        return in_array($mode, [self::MANUAL, self::EXTERNAL], true)
            ? $mode
            : self::MANUAL;
    }

    public static function isExternal(string $mode): bool
    {
        return self::normalize($mode) === self::EXTERNAL;
    }
}
