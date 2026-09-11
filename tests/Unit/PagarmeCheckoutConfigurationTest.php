<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\Pagarme\PagarmeCheckoutConfiguration;
use PHPUnit\Framework\TestCase;

final class PagarmeCheckoutConfigurationTest extends TestCase
{
    /** @var array<string,string|null> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach ([
            'PAGARME_PLATFORM_RECIPIENT_ID',
            'PAGARME_PUBLIC_KEY',
            'PAGARME_ORDERS_PIX_ENABLED',
            'PAGARME_SPLIT_ENABLED',
        ] as $name) {
            $this->previous[$name] = $_ENV[$name] ?? null;
            unset($_ENV[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }

    public function testPaymentLinkIsTheSafeDefault(): void
    {
        $configuration = new PagarmeCheckoutConfiguration();

        self::assertSame('payment_link', $configuration->mode());
        self::assertFalse($configuration->usesOrders('pix', [10]));
        self::assertFalse($configuration->cardCheckoutConfigured());
    }

    public function testFlagsAloneDoNotChangeModeWithoutRecipient(): void
    {
        $_ENV['PAGARME_ORDERS_PIX_ENABLED'] = 'true';
        $_ENV['PAGARME_SPLIT_ENABLED'] = 'true';

        self::assertSame('payment_link', (new PagarmeCheckoutConfiguration())->mode());
    }

    public function testOrdersPixUsesDynamicSellerEligibilityInsteadOfEnvAllowlist(): void
    {
        $_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] = 're_platform123';
        $_ENV['PAGARME_ORDERS_PIX_ENABLED'] = 'true';
        $_ENV['PAGARME_SPLIT_ENABLED'] = 'true';
        $configuration = new PagarmeCheckoutConfiguration();

        self::assertSame('orders_pix_limited', $configuration->mode());
        self::assertTrue($configuration->usesOrders('pix', [10, 20]));
        self::assertTrue($configuration->usesOrders('pix', [10, 30]));
        self::assertFalse($configuration->usesOrders('pix', [0]));
        self::assertFalse($configuration->usesOrders('card', [10]));
    }

    public function testCardConfigurationDoesNotRequireSellerIdsInEnvironment(): void
    {
        $_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] = 're_platform123';
        $_ENV['PAGARME_PUBLIC_KEY'] = 'pk_test_public123';
        $_ENV['PAGARME_SPLIT_ENABLED'] = 'true';

        self::assertTrue((new PagarmeCheckoutConfiguration())->cardCheckoutConfigured());
    }
}
