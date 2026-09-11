<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\Pagarme\DTO\SplitRuleData;
use App\Services\Payments\Pagarme\PagarmeCreditCardOrderService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CheckoutCardExperienceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testCheckoutCollectsCardWithoutPostingRawPanOrCvvToTuffer(): void
    {
        $view = file_get_contents($this->root . '/resources/views/public/checkout/index.php');
        self::assertIsString($view);
        self::assertStringContainsString('data-checkout-card', $view);
        self::assertStringContainsString('name="card_token"', $view);
        self::assertStringContainsString('name="card_installments"', $view);
        self::assertStringContainsString('data-card-number', $view);
        self::assertStringContainsString('data-card-cvv', $view);
        self::assertStringContainsString('data-card-camera-input', $view);
        self::assertStringNotContainsString('name="card_number"', $view);
        self::assertStringNotContainsString('name="card_cvv"', $view);
    }

    public function testBrowserTokenizationUsesOnlyPublicKeyAndLocalCameraOcr(): void
    {
        $script = file_get_contents($this->root . '/public/assets/js/checkout-card.js');
        self::assertIsString($script);
        self::assertStringContainsString('appId=', $script);
        self::assertStringContainsString("'Content-Type':'application/json'", $script);
        self::assertStringContainsString('TextDetector', $script);
        self::assertStringContainsString('capture="environment"', file_get_contents($this->root . '/resources/views/public/checkout/index.php'));
        self::assertStringNotContainsString('Authorization', $script);
        self::assertStringNotContainsString('SECRET_KEY', $script);
    }

    public function testCardOrderUsesWalletCardIdAndSplitAtPaymentLevel(): void
    {
        $reflection = new ReflectionClass(PagarmeCreditCardOrderService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('payload');
        $method->setAccessible(true);

        $context = [
            'order_code' => 'TF-TEST',
            'amount_cents' => 5000,
            'shipping_amount_cents' => 500,
            'recipient_name' => 'Cliente Teste',
            'customer_phone' => '22999999999',
            'seller_orders' => [[
                'code' => 'TF-TEST-L1',
                'store_name' => 'Loja Teste',
                'products_amount_cents' => 5000,
                'discount_amount_cents' => 500,
            ]],
        ];
        $rules = [
            new SplitRuleData(4500, 're_seller_test', ['liable' => true, 'charge_processing_fee' => false, 'charge_remainder_fee' => false]),
            new SplitRuleData(500, 're_platform_test', ['liable' => false, 'charge_processing_fee' => true, 'charge_remainder_fee' => true]),
        ];
        $address = ['line_1' => '10, Rua Teste, Centro', 'line_2' => '', 'zip_code' => '28600000', 'city' => 'Nova Friburgo', 'state' => 'RJ', 'country' => 'BR'];

        $payload = $method->invoke($service, $context, 'cus_test', 'card_test', 3, $rules, $address);
        $payment = $payload['payments'][0];
        self::assertSame('credit_card', $payment['payment_method']);
        self::assertSame('card_test', $payment['credit_card']['card_id']);
        self::assertSame(3, $payment['credit_card']['installments']);
        self::assertArrayHasKey('split', $payment);
        self::assertArrayNotHasKey('split', $payment['credit_card']);
        self::assertSame(5000, array_sum(array_column($payment['split'], 'amount')));
    }

    public function testHeaderShowsLogoutAndSavedAddressForAuthenticatedCustomer(): void
    {
        $header = file_get_contents($this->root . '/resources/views/components/public/header.php');
        $bootstrap = file_get_contents($this->root . '/bootstrap/app.php');
        self::assertIsString($header);
        self::assertIsString($bootstrap);
        self::assertStringContainsString("action=\"<?= e(url('/sair')) ?>\"", $header);
        self::assertStringContainsString('deliveryAddress', $header);
        self::assertStringContainsString('SELECT label,street,number,city,state,postal_code FROM user_addresses', $bootstrap);
        self::assertStringContainsString('camera=(self)', $bootstrap);
        self::assertStringContainsString('https://api.pagar.me', $bootstrap);
    }

    public function testSellerEligibilityComesFromPaymentStateInsteadOfEnvAllowlist(): void
    {
        $controller = file_get_contents($this->root . '/app/Http/Controllers/Public/CheckoutController.php');
        $configuration = file_get_contents($this->root . '/app/Services/Payments/Pagarme/PagarmeCheckoutConfiguration.php');
        $env = file_get_contents($this->root . '/.env.example');

        self::assertIsString($controller);
        self::assertIsString($configuration);
        self::assertIsString($env);
        self::assertStringContainsString('SellerSalesEligibility', $controller);
        self::assertStringContainsString('assertAllCanSell', $controller);
        self::assertStringNotContainsString('allowedSellerIds', $controller);
        self::assertStringNotContainsString('PAGARME_SPLIT_ALLOWED_SELLERS', $configuration);
        self::assertStringNotContainsString('PAGARME_SPLIT_ALLOWED_SELLERS', $env);
    }
}
