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
            'PAGARME_ORDERS_PIX_ENABLED',
            'PAGARME_SPLIT_ENABLED',
            'PAGARME_SPLIT_ALLOWED_SELLERS',
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
    }

    public function testFlagsAloneDoNotChangeModeWithoutRecipientAndAllowlist(): void
    {
        $_ENV['PAGARME_ORDERS_PIX_ENABLED'] = 'true';
        $_ENV['PAGARME_SPLIT_ENABLED'] = 'true';

        self::assertSame('payment_link', (new PagarmeCheckoutConfiguration())->mode());
    }

    public function testOrdersPixRequiresBothFlagsRecipientAndAllowlistedSellers(): void
    {
        $_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] = 're_platform123';
        $_ENV['PAGARME_ORDERS_PIX_ENABLED'] = 'true';
        $_ENV['PAGARME_SPLIT_ENABLED'] = 'true';
        $_ENV['PAGARME_SPLIT_ALLOWED_SELLERS'] = '10, 20;20';
        $configuration = new PagarmeCheckoutConfiguration();

        self::assertSame('orders_pix_limited', $configuration->mode());
        self::assertSame([10, 20], $configuration->allowedSellerIds());
        self::assertTrue($configuration->usesOrders('pix', [10, 20]));
        self::assertFalse($configuration->usesOrders('pix', [10, 30]));
        self::assertFalse($configuration->usesOrders('card', [10]));
    }
}
