<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\CardInstallmentPricingService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CardInstallmentPricingServiceTest extends TestCase
{
    public function testUpToThreeInstallmentsDoNotAddCustomerSurcharge(): void
    {
        $service = new CardInstallmentPricingService(null, [3 => 3.00, 4 => 3.50, 5 => 4.00, 6 => 4.50]);

        self::assertSame(0, $service->quote(100000, 1)['surcharge_cents']);
        self::assertSame(0, $service->quote(100000, 2)['surcharge_cents']);
        self::assertSame(0, $service->quote(100000, 3)['surcharge_cents']);
    }

    public function testAboveThreeInstallmentsChargesOnlyIncrementalProviderCost(): void
    {
        $service = new CardInstallmentPricingService(null, [3 => 3.00, 4 => 3.50, 5 => 4.00, 6 => 4.50]);
        $quote = $service->quote(100000, 6);

        self::assertSame(1571, $quote['surcharge_cents']);
        self::assertSame(101571, $quote['total_amount_cents']);
        self::assertEqualsWithDelta(3000, ($quote['total_amount_cents'] * 0.045) - $quote['surcharge_cents'], 1.0);
    }

    public function testHigherInstallmentIsUnavailableWithoutContractedRates(): void
    {
        $service = new CardInstallmentPricingService(null, [3 => 3.00]);
        self::assertFalse($service->configuration()['plans'][4]['available']);

        $this->expectException(RuntimeException::class);
        $service->quote(100000, 4);
    }
}
