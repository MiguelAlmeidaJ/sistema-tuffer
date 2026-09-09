<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

final class FiscalIssuanceMode
{
    public const PLATFORM = 'platform';
    public const MANUAL = 'manual';
    public const EXTERNAL = 'external';

    public static function normalize(?string $mode): string
    {
        $mode = mb_strtolower(trim((string) $mode));
        return in_array($mode, [self::PLATFORM, self::MANUAL, self::EXTERNAL], true)
            ? $mode
            : self::MANUAL;
    }

    public static function usesPlatform(string $mode): bool
    {
        return self::normalize($mode) === self::PLATFORM;
    }
}
