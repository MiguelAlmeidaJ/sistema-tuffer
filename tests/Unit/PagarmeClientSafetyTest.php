<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\PagarmeClient;
use App\Services\Payments\PagarmeException;
use PHPUnit\Framework\TestCase;

final class PagarmeClientSafetyTest extends TestCase
{
    public function testRefusesProductionKeyInLocalEnvironment(): void
    {
        $previousEnvironment = $_ENV['APP_ENV'] ?? null;
        $previousOverride = $_ENV['PAGARME_ALLOW_LIVE_IN_LOCAL'] ?? null;
        $_ENV['APP_ENV'] = 'local';
        $_ENV['PAGARME_ALLOW_LIVE_IN_LOCAL'] = 'false';

        try {
            $this->expectException(PagarmeException::class);
            $this->expectExceptionMessage('Use uma chave sk_test_');
            (new PagarmeClient('sk_production_example', 'https://api.pagar.me/core/v5'))
                ->createPaymentLink([], 'safe-test');
        } finally {
            if ($previousEnvironment === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $previousEnvironment;
            if ($previousOverride === null) unset($_ENV['PAGARME_ALLOW_LIVE_IN_LOCAL']); else $_ENV['PAGARME_ALLOW_LIVE_IN_LOCAL'] = $previousOverride;
        }
    }
}
