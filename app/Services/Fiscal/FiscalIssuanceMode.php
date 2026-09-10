<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

final class FiscalIssuanceMode
{
    public const MANUAL = 'manual';
    public const EXTERNAL = 'external';
    public const CONNECTOR = 'connector';

    public static function normalize(?string $mode): string
    {
        $mode = mb_strtolower(trim((string) $mode));
        return in_array($mode, [self::MANUAL, self::EXTERNAL, self::CONNECTOR], true)
            ? $mode
            : self::MANUAL;
    }

    public static function isExternal(string $mode): bool
    {
        return self::normalize($mode) === self::EXTERNAL;
    }

    public static function isConnector(string $mode): bool
    {
        return self::normalize($mode) === self::CONNECTOR;
    }
}
