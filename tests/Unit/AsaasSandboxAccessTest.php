<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\Asaas\AsaasCheckoutAccess;
use PHPUnit\Framework\TestCase;

final class AsaasSandboxAccessTest extends TestCase
{
    public function testSandboxAllowsOnlyExplicitAuthorizedCustomer(): void
    {
        $names=['ASAAS_ENV','ASAAS_API_KEY','ASAAS_WEBHOOK_TOKEN','ASAAS_CHECKOUT_ENABLED','ASAAS_SANDBOX_TEST_EMAIL','ASAAS_PRODUCTION_APPROVED'];
        $prior=[];
        foreach ($names as $name) $prior[$name]=$_ENV[$name]??null;
        try {
            $_ENV['ASAAS_ENV']='sandbox';
            $_ENV['ASAAS_API_KEY']=str_repeat('a',40);
            $_ENV['ASAAS_WEBHOOK_TOKEN']=str_repeat('b',24);
            $_ENV['ASAAS_CHECKOUT_ENABLED']='true';
            $_ENV['ASAAS_SANDBOX_TEST_EMAIL']='homologacao@example.com';
            self::assertTrue(AsaasCheckoutAccess::allowed(['id'=>42,'type'=>'customer','email'=>'homologacao@example.com']));
            self::assertFalse(AsaasCheckoutAccess::allowed(['id'=>43,'type'=>'customer','email'=>'outro@example.com']));
            self::assertFalse(AsaasCheckoutAccess::allowed(['id'=>44,'type'=>'admin','email'=>'homologacao@example.com']));
            self::assertFalse(AsaasCheckoutAccess::allowed(null));
            self::assertSame('account_not_authorized', AsaasCheckoutAccess::status(['id'=>43,'type'=>'customer','email'=>'outro@example.com']));
            $_ENV['ASAAS_SANDBOX_TEST_EMAIL']='';
            self::assertFalse(AsaasCheckoutAccess::allowed(['id'=>42,'type'=>'customer','email'=>'homologacao@example.com']));
            $_ENV['ASAAS_ENV']='production';
            $_ENV['ASAAS_PRODUCTION_APPROVED']='false';
            self::assertFalse(AsaasCheckoutAccess::allowed(['id'=>42,'type'=>'customer','email'=>'homologacao@example.com']));
        } finally {
            foreach ($prior as $name=>$value) {
                if ($value===null) unset($_ENV[$name]);
                else $_ENV[$name]=$value;
            }
        }
    }

    public function testPublicCheckoutAndOrderPlacementBothCheckAuthorization(): void
    {
        $root=dirname(__DIR__,2);
        foreach ([
            '/app/Http/Controllers/Public/CheckoutController.php',
            '/app/Services/Orders/OrderPlacementService.php',
        ] as $file) {
            self::assertStringContainsString('AsaasCheckoutAccess::allowed',file_get_contents($root.$file));
        }
    }

    public function testReadOnlySandboxDiagnostic(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/scripts/check-asaas-sandbox.php');
        self::assertStringContainsString("get('/payments?limit=1')",$source);
        self::assertStringContainsString('authorized_customer_account=', $source);
        self::assertStringNotContainsString("->post(",$source);
    }

    public function testCartAndCheckoutUseCommerceRefreshStyles(): void
    {
        $root=dirname(__DIR__,2);
        self::assertStringContainsString("commerce-refresh.css",file_get_contents($root.'/public/assets/css/app.css'));
        self::assertStringContainsString("cart-clean-heading",file_get_contents($root.'/resources/views/public/cart/index.php'));
        self::assertStringContainsString("commerce-sandbox-notice",file_get_contents($root.'/resources/views/public/checkout/index.php'));
    }
}
