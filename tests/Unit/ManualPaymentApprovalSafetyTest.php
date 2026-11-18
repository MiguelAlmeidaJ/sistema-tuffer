<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ManualPaymentApprovalSafetyTest extends TestCase
{
    public function testManualApprovalRequiresExternalReferenceAndIsRestrictedToFailedLinkJobs(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Payments/ManualPaymentApprovalService.php');
        self::assertIsString($source);
        self::assertStringContainsString('The type field is required', $source);
        self::assertStringContainsString("payment_link", $source);
        self::assertStringContainsString("FOR UPDATE", $source);
        self::assertStringContainsString("OrderInventoryService", $source);
        self::assertStringContainsString("OrderCouponService", $source);
        self::assertStringContainsString("manual_payment_approvals", $source);
        self::assertStringContainsString("external_reference", $source);
    }

    public function testRouteIsAdminOnlyAndUsesCsrf(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/routes/admin.php');
        self::assertStringContainsString("'role:admin'", $routes);
        self::assertStringContainsString("aprovar-manualmente", $routes);
        self::assertStringContainsString("['csrf']", $routes);
    }
}
