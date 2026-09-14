<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\CardInstallmentPricingService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CardInstallmentPricingServiceTest extends TestCase
{
    public function testUpToSixInstallmentsDoNotAddCustomerSurcharge(): void
    {
        $service = new CardInstallmentPricingService(null, [
            6 => 4.50,
            7 => 5.00,
            8 => 5.50,
            9 => 6.00,
            10 => 6.50,
            11 => 7.00,
            12 => 7.50,
        ]);

        foreach (range(1, 6) as $installments) {
            self::assertSame(0, $service->quote(100000, $installments)['surcharge_cents']);
        }
    }

    public function testAboveSixInstallmentsChargesOnlyIncrementalProviderCost(): void
    {
        $service = new CardInstallmentPricingService(null, [6 => 4.50, 12 => 7.50]);
        $quote = $service->quote(100000, 12);

        self::assertSame(3244, $quote['surcharge_cents']);
        self::assertSame(103244, $quote['total_amount_cents']);
        self::assertEqualsWithDelta(4500, ($quote['total_amount_cents'] * 0.075) - $quote['surcharge_cents'], 1.0);
    }

    public function testHigherInstallmentIsUnavailableWithoutContractedRates(): void
    {
        $service = new CardInstallmentPricingService(null, [6 => 4.50]);
        self::assertFalse($service->configuration()['plans'][7]['available']);

        $this->expectException(RuntimeException::class);
        $service->quote(100000, 7);
    }
}
