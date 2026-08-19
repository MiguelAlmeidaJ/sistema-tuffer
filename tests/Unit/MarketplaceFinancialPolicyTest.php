<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\MarketplaceFinancialPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MarketplaceFinancialPolicyTest extends TestCase
{
    private ?string $previousShippingRecipient;
    private ?string $previousCoverage;

    protected function setUp(): void
    {
        $this->previousShippingRecipient = $_ENV['MARKETPLACE_SHIPPING_RECIPIENT'] ?? null;
        $this->previousCoverage = $_ENV['MARKETPLACE_PLATFORM_COUPON_REQUIRES_COMMISSION_COVERAGE'] ?? null;
        $_ENV['MARKETPLACE_SHIPPING_RECIPIENT'] = 'seller';
        $_ENV['MARKETPLACE_PLATFORM_COUPON_REQUIRES_COMMISSION_COVERAGE'] = 'true';
    }

    protected function tearDown(): void
    {
        $this->restore('MARKETPLACE_SHIPPING_RECIPIENT', $this->previousShippingRecipient);
        $this->restore('MARKETPLACE_PLATFORM_COUPON_REQUIRES_COMMISSION_COVERAGE', $this->previousCoverage);
    }

    public function testSellerFundedCouponReducesSellerNetAndShippingHasNoCommission(): void
    {
        $amounts = (new MarketplaceFinancialPolicy())->sellerAmounts(10_000, 1_500, 1_000, '10.00', 'seller');

        self::assertSame(900, $amounts['commission_cents']);
        self::assertSame(1_000, $amounts['seller_discount_cents']);
        self::assertSame(0, $amounts['platform_discount_cents']);
        self::assertSame(9_600, $amounts['seller_net_cents']);
        self::assertSame(900, $amounts['platform_contribution_cents']);
    }

    public function testPlatformFundedCouponReducesPlatformRevenue(): void
    {
        $amounts = (new MarketplaceFinancialPolicy())->sellerAmounts(10_000, 500, 500, '10.00', 'platform');

        self::assertSame(950, $amounts['commission_cents']);
        self::assertSame(0, $amounts['seller_discount_cents']);
        self::assertSame(500, $amounts['platform_discount_cents']);
        self::assertSame(9_550, $amounts['seller_net_cents']);
        self::assertSame(450, $amounts['platform_contribution_cents']);
    }

    public function testCommissionRoundingUsesIntegerCents(): void
    {
        $amounts = (new MarketplaceFinancialPolicy())->sellerAmounts(101, 0, 0, '10.00', 'seller');
        self::assertSame(10, $amounts['commission_cents']);
    }

    public function testRejectsPlatformSplitBelowZero(): void
    {
        $this->expectException(RuntimeException::class);
        (new MarketplaceFinancialPolicy())->assertPlatformAmount(-1);
    }

    public function testRejectsPlatformCouponGreaterThanItsCommission(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('excede a comissão');

        (new MarketplaceFinancialPolicy())->sellerAmounts(10_000, 500, 2_000, '10.00', 'platform');
    }

    public function testShippingCanBeAssignedToPlatformWithoutEnteringCommissionBase(): void
    {
        $_ENV['MARKETPLACE_SHIPPING_RECIPIENT'] = 'platform';

        $amounts = (new MarketplaceFinancialPolicy())->sellerAmounts(10_000, 1_500, 1_000, '10.00', 'seller');

        self::assertSame(900, $amounts['commission_cents']);
        self::assertSame(8_100, $amounts['seller_net_cents']);
        self::assertSame(2_400, $amounts['platform_contribution_cents']);
        self::assertSame(0, $amounts['shipping_seller_cents']);
        self::assertSame(1_500, $amounts['shipping_platform_cents']);
    }

    private function restore(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $value;
        }
    }
}
