<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\Pagarme\PagarmePayloadSanitizer;
use PHPUnit\Framework\TestCase;

final class PagarmePayloadSanitizerTest extends TestCase
{
    public function testRemovesCustomerBankAndFullPaymentData(): void
    {
        $safe = (new PagarmePayloadSanitizer())->orderResponse([
            'id' => 'or_test',
            'amount' => 1000,
            'customer' => ['document' => '52998224725'],
            'charges' => [[
                'id' => 'ch_test',
                'gateway_id' => 'GW-A1B2',
                'amount' => 1000,
                'last_transaction' => [
                    'id' => 'tran_test',
                    'gateway_id' => 'TX-Z9',
                    'qr_code' => 'PIX-CODE-COMPLETO',
                    'payer' => ['bank_account' => ['bank_name' => 'Banco']],
                ],
            ]],
        ]);

        $encoded = json_encode($safe);
        self::assertStringNotContainsString('52998224725', $encoded);
        self::assertStringNotContainsString('PIX-CODE-COMPLETO', $encoded);
        self::assertStringNotContainsString('bank_account', $encoded);
        self::assertSame('GW-A1B2', $safe['charges'][0]['gateway_id']);
        self::assertSame('TX-Z9', $safe['charges'][0]['last_transaction']['gateway_id']);
    }
}
