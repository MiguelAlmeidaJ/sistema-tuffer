<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\Asaas\AsaasClient;
use App\Services\Payments\Asaas\AsaasChargeService;
use PHPUnit\Framework\TestCase;

final class AsaasCheckoutSafetyTest extends TestCase
{
    public function testCheckoutRemainsDisabledWithoutExplicitFlag(): void
    {
        $key = $_ENV['ASAAS_API_KEY'] ?? null;
        $token = $_ENV['ASAAS_WEBHOOK_TOKEN'] ?? null;
        $flag = $_ENV['ASAAS_CHECKOUT_ENABLED'] ?? null;
        try {
            $_ENV['ASAAS_API_KEY'] = str_repeat('a', 40);
            $_ENV['ASAAS_WEBHOOK_TOKEN'] = str_repeat('b', 24);
            $_ENV['ASAAS_CHECKOUT_ENABLED'] = 'false';
            self::assertTrue((new AsaasClient())->configured());
            self::assertFalse((new AsaasClient())->checkoutEnabled());
        } finally {
            foreach (['ASAAS_API_KEY'=>$key,'ASAAS_WEBHOOK_TOKEN'=>$token,'ASAAS_CHECKOUT_ENABLED'=>$flag] as $name=>$old) {
                if ($old === null) unset($_ENV[$name]); else $_ENV[$name]=$old;
            }
        }
    }

    public function testInvoiceHostsAreRestrictedToAsaas(): void
    {
        self::assertTrue(AsaasChargeService::trustedInvoiceUrl('https://www.asaas.com/i/test'));
        self::assertFalse(AsaasChargeService::trustedInvoiceUrl('https://asaas.com.evil.test/i/test'));
        self::assertFalse(AsaasChargeService::trustedInvoiceUrl('http://www.asaas.com/i/test'));
        self::assertFalse(AsaasChargeService::trustedInvoiceUrl('https://user@www.asaas.com/i/test'));
    }

    public function testNewCheckoutDoesNotCreatePagarmePaymentsOrCollectCardNumbers(): void
    {
        $root = dirname(__DIR__, 2);
        $placement = file_get_contents($root . '/app/Services/Orders/OrderPlacementService.php');
        $checkout = file_get_contents($root . '/resources/views/public/checkout/index.php');
        self::assertStringContainsString("'asaas'", $placement);
        self::assertStringContainsString("'asaas.create_payment'", $placement);
        self::assertStringNotContainsString('data-card-number', $checkout);
        self::assertStringNotContainsString('data-card-cvv', $checkout);
    }
}
