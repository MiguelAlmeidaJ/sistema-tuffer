<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\Pagarme\PagarmePayloadSanitizer;
use App\Services\Payments\Pagarme\PagarmeWebhookEventClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PagarmeWebhookLifecycleTest extends TestCase
{
    #[DataProvider('states')]
    public function testClassifiesPaymentLifecycle(string $event, string $providerStatus, string $expected): void
    {
        self::assertSame(
            $expected,
            (new PagarmeWebhookEventClassifier())->classify($event, $providerStatus)
        );
    }

    public function testKeepsDistinctChargeIdsFromAReprocessedOrder(): void
    {
        $sanitizer = new PagarmePayloadSanitizer();
        $first = $sanitizer->webhookEvent($this->chargeEvent('hook_1', 'ch_original', 'failed'));
        $second = $sanitizer->webhookEvent($this->chargeEvent('hook_2', 'ch_reprocessed', 'paid'));

        self::assertNotSame($first['data']['id'], $second['data']['id']);
        self::assertSame('or_shared', $first['data']['order']['id']);
        self::assertSame('or_shared', $second['data']['order']['id']);
    }

    public function testMigrationMakesEventAndChargeIdentitiesUnique(): void
    {
        $root = dirname(__DIR__, 2);
        $webhooks = file_get_contents($root . '/database/migrations/001_create_marketplace_schema.sql');
        $orders = file_get_contents($root . '/database/migrations/024_create_pagarme_orders_split.sql');

        self::assertStringContainsString('UNIQUE KEY uk_payment_webhooks_provider_event (provider_event_id)', $webhooks);
        self::assertStringContainsString('UNIQUE KEY uk_pagarme_charges_external (external_charge_id)', $orders);
        self::assertStringContainsString('charge_gateway_id VARCHAR(128)', $orders);
        self::assertStringContainsString('transaction_gateway_id VARCHAR(128)', $orders);
    }

    /** @return array<string,array{string,string,string}> */
    public static function states(): array
    {
        return [
            'approved' => ['charge.paid', 'paid', 'paid'],
            'expired' => ['charge.updated', 'expired', 'expired'],
            'failed' => ['charge.payment_failed', 'failed', 'failed'],
            'refunded' => ['charge.refunded', 'refunded', 'refunded'],
            'chargeback' => ['chargeback.received', '', 'refunded'],
            'pending' => ['charge.pending', 'pending', 'waiting_payment'],
            'processing' => ['charge.processing', 'processing', 'processing'],
        ];
    }

    /** @return array<string,mixed> */
    private function chargeEvent(string $hookId, string $chargeId, string $status): array
    {
        return [
            'id' => $hookId,
            'type' => 'charge.updated',
            'created_at' => '2026-07-27T12:00:00Z',
            'data' => [
                'id' => $chargeId,
                'status' => $status,
                'amount' => 10_000,
                'order' => ['id' => 'or_shared', 'code' => 'TF-1'],
                'last_transaction' => ['id' => 'tran_' . $chargeId, 'gateway_id' => 'GW-A1B2'],
            ],
        ];
    }
}
