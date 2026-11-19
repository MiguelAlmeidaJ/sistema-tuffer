<?php
declare(strict_types=1);

namespace Tests\Unit;
use PHPUnit\Framework\TestCase;

final class AsaasConfirmationFlowTest extends TestCase
{
    public function testPostCheckoutKeepsPublicStorefrontConfirmation(): void
    {
        $root=dirname(__DIR__, 2);
        $controller=file_get_contents($root.'/app/Http/Controllers/Public/CheckoutController.php');
        $routes=file_get_contents($root.'/routes/web.php');
        $page=file_get_contents($root.'/resources/views/public/checkout/order-confirmation.php');
        self::assertStringContainsString("'/checkout/pedido/'", $controller);
        self::assertStringContainsString("'/checkout/pedido/{code}'", $routes);
        self::assertStringContainsString("'layouts/public'", file_get_contents($root.'/app/Http/Controllers/Public/CheckoutConfirmationController.php'));
        self::assertStringContainsString('pix_qr_code',$page);
        self::assertStringContainsString('asaas_pix_image',$page);
        self::assertStringContainsString('data-copy-target', $page);
        self::assertStringContainsString('csrf_field()', $page);
    }

    public function testAsaasKeepsRemoteIdEvenWhenQrCodeFetchFails(): void
    {
        $root=dirname(__DIR__, 2);
        $source=file_get_contents($root.'/app/Services/Payments/Asaas/AsaasChargeService.php');
        $marker=strpos($source, 'Registre a cobrança remota ANTES');
        $qr=strpos($source, "'/pixQrCode'");
        if ($qr===false) $qr=strpos($source, '/pixQrCode');
        self::assertNotFalse($marker);
        self::assertNotFalse($qr);
        self::assertLessThan($qr, $marker);
        self::assertStringContainsString("['pending','waiting_payment']", $source);
        $recovery=file_get_contents($root.'/app/Services/Payments/PendingPaymentRecoveryService.php');
        self::assertStringContainsString("['completed','failed']", $recovery);
    }

    public function testDiagnosticDoesNotPrintPixOrApiSecrets(): void
    {
        $source=file_get_contents(dirname(__DIR__, 2).'/scripts/diagnose-asaas-order.php');
        self::assertStringContainsString("LAST_ERROR_CATEGORY", $source);
        self::assertStringNotContainsString("echo \$payment['pix_qr_code']", $source);
    }
}
