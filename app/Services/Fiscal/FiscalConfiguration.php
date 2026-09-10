<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

final class FiscalConfiguration
{
    public function rtcRequired(): bool
    {
        return filter_var($_ENV['FISCAL_RTC_REQUIRED'] ?? true, FILTER_VALIDATE_BOOL);
    }

    public function privateStorage(): string
    {
        $path = trim((string) ($_ENV['FISCAL_PRIVATE_STORAGE'] ?? 'storage/private/fiscal'));
        return $path === '' ? 'storage/private/fiscal' : $path;
    }
}
