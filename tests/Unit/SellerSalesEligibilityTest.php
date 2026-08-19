<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Sellers\SellerSalesEligibility;
use PHPUnit\Framework\TestCase;

final class SellerSalesEligibilityTest extends TestCase
{
    public function testRequiresAllPaymentAndApprovalConditions(): void
    {
        $eligible = [
            'status' => 'active',
            'payment_enabled' => 1,
            'pagarme_recipient_id' => 'rp_ABC123',
            'payment_onboarding_status' => 'active',
        ];
        $service = new SellerSalesEligibility();

        self::assertTrue($service->canSell($eligible));

        foreach (['status', 'payment_enabled', 'pagarme_recipient_id', 'payment_onboarding_status'] as $field) {
            $seller = $eligible;
            $seller[$field] = match ($field) {
                'status' => 'pending',
                'payment_enabled' => 0,
                'pagarme_recipient_id' => null,
                default => 'analyzing',
            };
            self::assertFalse($service->canSell($seller), "The {$field} condition must be required.");
        }
    }
}
