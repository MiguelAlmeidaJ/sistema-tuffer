<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

final class FiscalConfiguration
{
    public function provider(): string
    {
        $provider = mb_strtolower(trim((string) ($_ENV['FISCAL_PROVIDER'] ?? 'disabled')));
        return $provider === '' ? 'disabled' : $provider;
    }

    public function environment(): string
    {
        $environment = mb_strtolower(trim((string) ($_ENV['FISCAL_ENVIRONMENT'] ?? 'homologation')));
        return $environment === 'production' ? 'production' : 'homologation';
    }

    public function autoIssue(): bool
    {
        return filter_var($_ENV['FISCAL_AUTO_ISSUE'] ?? false, FILTER_VALIDATE_BOOL);
    }

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
