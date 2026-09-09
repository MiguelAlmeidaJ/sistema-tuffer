<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

final class FiscalProviderFactory
{
    /** @param array<string,mixed>|null $storeProfile */
    public static function make(?FiscalConfiguration $configuration = null, ?array $storeProfile = null): FiscalProvider
    {
        $configuration ??= new FiscalConfiguration();
        $provider = mb_strtolower(trim((string) ($storeProfile['provider'] ?? $configuration->provider())));
        $provider = $provider === '' ? 'disabled' : $provider;

        return match ($provider) {
            '', 'none', 'disabled' => new DisabledFiscalProvider('disabled'),
            default => new DisabledFiscalProvider($provider),
        };
    }
}
