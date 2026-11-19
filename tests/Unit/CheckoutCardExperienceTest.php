<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;

final class CheckoutCardExperienceTest extends TestCase
{
    private string $root;
    protected function setUp(): void { $this->root=dirname(__DIR__,2); }

    public function testCardIsHandledOnlyInHostedAsaasInvoice(): void
    {
        $view=file_get_contents($this->root.'/resources/views/public/checkout/index.php');
        $controller=file_get_contents($this->root.'/app/Http/Controllers/Public/CheckoutController.php');
        self::assertIsString($view);
        self::assertStringContainsString('fatura segura do Asaas',$view);
        self::assertStringNotContainsString('data-card-number',$view);
        self::assertStringNotContainsString('data-card-cvv',$view);
        self::assertStringNotContainsString('data-card-camera-input',$view);
        self::assertStringNotContainsString('token_[A-Za-z0-9_-]+',$controller);
        self::assertStringContainsString('AsaasClient',$controller);
    }

    public function testAsaasPaymentHasNoMarketplaceSplitOrRawCardDetails(): void
    {
        $service=file_get_contents($this->root.'/app/Services/Payments/Asaas/AsaasChargeService.php');
        self::assertStringContainsString("'CREDIT_CARD'",$service);
        self::assertStringContainsString("'externalReference'",$service);
        self::assertStringNotContainsString("'creditCardNumber'",$service);
        self::assertStringNotContainsString("'split' =>",$service);
    }

    public function testCheckoutIsPausedUntilExplicitAsaasActivation(): void
    {
        $controller=file_get_contents($this->root.'/app/Http/Controllers/Public/CheckoutController.php');
        self::assertStringContainsString('checkoutEnabled()',$controller);
        $env=file_get_contents($this->root.'/.env.example');
        self::assertStringContainsString('ASAAS_CHECKOUT_ENABLED=false',$env);
    }

    public function testHeaderKeepsLogoutAndDeliveryAddress(): void
    {
        $header=file_get_contents($this->root.'/resources/views/components/public/header.php');
        $bootstrap=file_get_contents($this->root.'/bootstrap/app.php');
        self::assertStringContainsString('deliveryAddress',$header);
        self::assertStringContainsString('user_addresses',$bootstrap);
    }
}
