<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

final class FiscalProviderFactory
{
    public static function make(?FiscalConfiguration $configuration = null): FiscalProvider
    {
        $configuration ??= new FiscalConfiguration();
        $provider = $configuration->provider();
        return match ($provider) {
            '', 'none', 'disabled' => new DisabledFiscalProvider('disabled'),
            default => new DisabledFiscalProvider($provider),
        };
    }
}
