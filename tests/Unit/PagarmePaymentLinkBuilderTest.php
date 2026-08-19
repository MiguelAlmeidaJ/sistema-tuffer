<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\PagarmePaymentLinkBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PagarmePaymentLinkBuilderTest extends TestCase
{
    #[DataProvider('paymentMethods')]
    public function testBuildsExactTotalAndMethodSettings(string $localMethod, string $gatewayMethod, string $settingsKey): void
    {
        $payload = (new PagarmePaymentLinkBuilder())->build(
            'TF-10-ABC123',
            18_650,
            [
                ['store_id' => 1, 'store_name' => 'Loja A', 'subtotal' => 100.00, 'discount' => 10.00],
                ['store_id' => 2, 'store_name' => 'Loja B', 'subtotal' => 80.00, 'discount' => 0.00],
            ],
            [
                1 => ['id' => '1', 'price' => 9.90],
                2 => ['id' => '2', 'price' => 6.60],
            ],
            ['id' => 7, 'name' => 'Cliente Teste', 'email' => 'cliente@example.com'],
            ['state' => 'SP', 'city' => 'São Paulo', 'postal_code' => '01001-000', 'number' => '10', 'street' => 'Praça da Sé', 'neighborhood' => 'Sé', 'complement' => ''],
            $localMethod,
            'https://loja.example.com/minha-conta/pedidos/TF-10-ABC123'
        );

        self::assertSame('order', $payload['type']);
        self::assertSame([$gatewayMethod], $payload['payment_settings']['accepted_payment_methods']);
        self::assertArrayHasKey($settingsKey, $payload['payment_settings']);
        self::assertSame(18_650, array_sum(array_column($payload['cart_settings']['items'], 'amount')));
        self::assertSame('01001000', $payload['customer_settings']['customer']['address']['zip_code']);
    }

    /** @return array<string,array{string,string,string}> */
    public static function paymentMethods(): array
    {
        return [
            'pix' => ['pix', 'pix', 'pix_settings'],
            'credit card' => ['card', 'credit_card', 'credit_card_settings'],
            'boleto' => ['boleto', 'boleto', 'boleto_settings'],
        ];
    }
}
