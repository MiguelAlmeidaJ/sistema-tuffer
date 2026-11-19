<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SellerSandboxOrderCancellationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root=dirname(__DIR__,2);
    }

    public function testCancellationIsOnlyAvailableForSandboxPendingOrders(): void
    {
        $service=file_get_contents($this->root.'/app/Services/Orders/SandboxOrderCancellationService.php');
        self::assertStringContainsString("!== 'sandbox'", $service);
        self::assertStringContainsString("!== 'pending_payment'", $service);
        self::assertStringContainsString("['PENDING','OVERDUE','DELETED']", $service);
        self::assertStringContainsString('OrderInventoryService', $service);
        self::assertStringContainsString("status='cancelled'", $service);
        self::assertStringContainsString('externalReference', $service);
        self::assertStringContainsString('DELETE', file_get_contents($this->root.'/app/Services/Payments/Asaas/AsaasClient.php'));
    }

    public function testJobAndCancellationUseSameDatabaseAdvisoryLock(): void
    {
        $charge=file_get_contents($this->root.'/app/Services/Payments/Asaas/AsaasChargeService.php');
        $cancel=file_get_contents($this->root.'/app/Services/Orders/SandboxOrderCancellationService.php');
        self::assertStringContainsString("'asaas-payment-' . $paymentId",$charge);
        self::assertStringContainsString("'asaas-payment-' . $paymentId",$cancel);
        self::assertStringContainsString('GET_LOCK', $charge);
        self::assertStringContainsString('GET_LOCK', $cancel);
        self::assertStringContainsString('RELEASE_LOCK', $charge);
        self::assertStringContainsString('RELEASE_LOCK', $cancel);
    }

    public function testSellerRouteRequiresSellerRoleAndCsrf(): void
    {
        $routes=file_get_contents($this->root.'/routes/seller.php');
        self::assertStringContainsString("'/pedidos/{code}/cancelar-teste'", $routes);
        self::assertStringContainsString("['role:seller','csrf']", $routes);
        $view=file_get_contents($this->root.'/resources/views/seller/orders/show.php');
        self::assertStringContainsString('seller-order-action-menu', $view);
        self::assertStringContainsString('confirm_order_code', $view);
        self::assertStringContainsString('csrf_field()', $view);
        self::assertStringContainsString('seller-order-summary-grid', $view);
    }
}
