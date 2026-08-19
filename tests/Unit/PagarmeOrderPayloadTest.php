<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\Pagarme\DTO\SplitRuleData;
use App\Services\Payments\Pagarme\PagarmeOrderService;
use PHPUnit\Framework\TestCase;

final class PagarmeOrderPayloadTest extends TestCase
{
    public function testBuildsPixOrderWithIntegerTotalsAndSplit(): void
    {
        $context = [
            'order_code' => 'TF-100-ABC',
            'user_id' => 8,
            'customer_name' => 'Cliente Teste',
            'customer_email' => 'cliente@example.com',
            'customer_phone' => '11999998888',
            'customer_document' => '52998224725',
            'recipient_name' => 'Cliente Teste',
            'postal_code' => '01001000',
            'street' => 'Praça da Sé',
            'number' => '10',
            'complement' => '',
            'neighborhood' => 'Sé',
            'city' => 'São Paulo',
            'state' => 'SP',
            'amount_cents' => 18_650,
            'shipping_amount_cents' => 1_650,
            'seller_orders' => [
                ['code' => 'TF-A', 'store_name' => 'Loja A', 'products_amount_cents' => 10_000, 'discount_amount_cents' => 1_000],
                ['code' => 'TF-B', 'store_name' => 'Loja B', 'products_amount_cents' => 8_000, 'discount_amount_cents' => 0],
            ],
        ];
        $options = ['liable' => false, 'charge_processing_fee' => false, 'charge_remainder_fee' => false];
        $payload = (new PagarmeOrderService())->buildPayload($context, [
            new SplitRuleData(16_950, 'rp_sellers', $options),
            new SplitRuleData(1_700, 'rp_platform', ['liable' => true, 'charge_processing_fee' => true, 'charge_remainder_fee' => true]),
        ]);

        self::assertSame('pix', $payload['payments'][0]['payment_method']);
        self::assertSame('flat', $payload['payments'][0]['split'][0]['type']);
        self::assertSame(18_650, array_sum(array_column($payload['items'], 'amount')) + $payload['shipping']['amount']);
        self::assertSame(18_650, array_sum(array_column($payload['payments'][0]['split'], 'amount')));
        self::assertSame('55', $payload['customer']['phones']['mobile_phone']['country_code']);
    }

    public function testCardEntryPointDoesNotAcceptRawCardData(): void
    {
        $this->expectExceptionMessage('tokenização segura');
        (new PagarmeOrderService())->createCreditCardOrder(1, '4111111111111111');
    }
}
