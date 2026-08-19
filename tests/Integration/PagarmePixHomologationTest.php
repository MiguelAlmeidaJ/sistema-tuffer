<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Payments\MarketplaceFinancialPolicy;
use App\Services\Payments\PagarmeApiClient;
use App\Services\Payments\PagarmeException;
use App\Services\Payments\Pagarme\DTO\SplitRuleData;
use App\Services\Payments\Pagarme\PagarmeEventFreshnessGuard;
use App\Services\Payments\Pagarme\PagarmePayloadSanitizer;
use App\Services\Payments\Pagarme\PagarmeRefundableChargeSelector;
use App\Services\Payments\Pagarme\PagarmeRefundRequestBuilder;
use App\Services\Payments\Pagarme\PagarmeRemoteOrderLocator;
use App\Services\Payments\Pagarme\PagarmeSplitService;
use App\Services\Payments\Pagarme\PagarmeWebhookEventClassifier;
use App\Services\Payments\Pagarme\PagarmeWebhookIdempotency;
use App\Services\Payments\PagarmeWebhookException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PagarmePixHomologationTest extends TestCase
{
    /** @var array<string,string|null> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach ([
            'MARKETPLACE_SHIPPING_RECIPIENT',
            'MARKETPLACE_PLATFORM_COUPON_REQUIRES_COMMISSION_COVERAGE',
        ] as $name) {
            $this->environment[$name] = $_ENV[$name] ?? null;
        }
        $_ENV['MARKETPLACE_SHIPPING_RECIPIENT'] = 'seller';
        $_ENV['MARKETPLACE_PLATFORM_COUPON_REQUIRES_COMMISSION_COVERAGE'] = 'true';
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            if ($value === null) unset($_ENV[$name]); else $_ENV[$name] = $value;
        }
    }

    public function testOneSellerSplitInCents(): void
    {
        $rules = [
            new SplitRuleData(9_001, 'rp_seller1', $this->sellerOptions()),
            new SplitRuleData(1_000, 'rp_platform', $this->platformOptions()),
        ];
        self::assertSame(10_001, array_sum(array_map(static fn(SplitRuleData $rule): int => $rule->amount, $rules)));
    }

    public function testTwoSellerSplitInCents(): void
    {
        $rules = [
            new SplitRuleData(3_333, 'rp_seller1', $this->sellerOptions()),
            new SplitRuleData(5_556, 'rp_seller2', $this->sellerOptions()),
            new SplitRuleData(1_112, 'rp_platform', $this->platformOptions()),
        ];
        self::assertSame(10_001, array_sum(array_map(static fn(SplitRuleData $rule): int => $rule->amount, $rules)));
    }

    public function testZeroSplitEntryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SplitRuleData(0, 'rp_platform', $this->platformOptions());
    }

    public function testSplitWhoseSumDoesNotCloseIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('soma do split');
        (new PagarmeSplitService())->rulesFromRows([
            $this->splitRow('seller:1', 'seller', 'rp_seller1', 9_000),
            $this->splitRow('platform', 'platform', 'rp_platform', 1_000),
        ], 10_001);
    }

    public function testSellerBlockedAfterCartIsRejectedAtFinalEligibilityCheck(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deixou de estar habilitado');
        (new PagarmeSplitService())->assertRecipientEligible(
            ['recipient_id' => 'rp_seller1'],
            ['recipient_id' => 'rp_seller1', 'recipient_status' => 'blocked', 'kyc_status' => 'approved']
        );
    }

    public function testSellerAndPlatformFundedCouponsUseDifferentNets(): void
    {
        $policy = new MarketplaceFinancialPolicy();
        $seller = $policy->sellerAmounts(10_000, 1_000, 500, '10', 'seller');
        $platform = $policy->sellerAmounts(10_000, 1_000, 500, '10', 'platform');

        self::assertSame(9_550, $seller['seller_net_cents']);
        self::assertSame(10_050, $platform['seller_net_cents']);
        self::assertSame(450, $platform['platform_contribution_cents']);
    }

    public function testPlatformCouponGreaterThanCommissionIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new MarketplaceFinancialPolicy())->sellerAmounts(10_000, 0, 2_000, '10', 'platform');
    }

    public function testShippingCanBelongToSellerOrPlatform(): void
    {
        $policy = new MarketplaceFinancialPolicy();
        $sellerShipping = $policy->sellerAmounts(10_000, 1_000, 0, '10', 'seller');
        $_ENV['MARKETPLACE_SHIPPING_RECIPIENT'] = 'platform';
        $platformShipping = $policy->sellerAmounts(10_000, 1_000, 0, '10', 'seller');

        self::assertSame(10_000, $sellerShipping['seller_net_cents']);
        self::assertSame(9_000, $platformShipping['seller_net_cents']);
        self::assertSame(2_000, $platformShipping['platform_contribution_cents']);
    }

    public function testDuplicateAndOutOfOrderWebhooksAreDetected(): void
    {
        $idempotency = new PagarmeWebhookIdempotency();
        $hash = hash('sha256', 'same');
        $idempotency->assertPayload($hash, $hash);

        self::assertTrue($idempotency->alreadyHandled('processed'));
        self::assertTrue((new PagarmeEventFreshnessGuard())->isStale(
            '2026-07-27T10:00:00Z',
            '2026-07-27T11:00:00Z'
        ));
    }

    public function testPixPaidExpiredFailedAndRefundedStatuses(): void
    {
        $classifier = new PagarmeWebhookEventClassifier();

        self::assertSame('paid', $classifier->classify('charge.updated', 'paid'));
        self::assertSame('expired', $classifier->classify('charge.updated', 'expired'));
        self::assertSame('failed', $classifier->classify('charge.updated', 'failed'));
        self::assertSame('refunded', $classifier->classify('charge.updated', 'refunded'));
    }

    public function testNetworkFailureThenCheckoutRetryReusesRemoteOrder(): void
    {
        $client = new HomologationFakeClient();
        $locator = new PagarmeRemoteOrderLocator($client);

        try {
            $locator->byCode('TF-RETRY', 10_001);
            self::fail('A primeira consulta deveria simular falha de rede.');
        } catch (PagarmeException) {
            self::assertSame(1, $client->listCalls);
        }

        $order = $locator->byCode('TF-RETRY', 10_001);
        self::assertSame('or_retry', $order['id']);
        self::assertSame(0, $client->postCalls);
    }

    public function testMultipleChargeIdsRemainDistinctAndCorrectPaidChargeIsSelected(): void
    {
        $sanitizer = new PagarmePayloadSanitizer();
        $safe = $sanitizer->orderResponse([
            'id' => 'or_multi',
            'amount' => 10_001,
            'charges' => [
                ['id' => 'ch_failed', 'status' => 'failed', 'amount' => 10_001],
                ['id' => 'ch_paid', 'status' => 'paid', 'amount' => 10_001],
            ],
        ]);
        self::assertSame(['ch_failed', 'ch_paid'], array_column($safe['charges'], 'id'));

        $selected = (new PagarmeRefundableChargeSelector())->select([
            ['pagarme_charge_id' => 1, 'external_charge_id' => 'ch_failed', 'status' => 'failed', 'amount_cents' => 10_001, 'paid_amount_cents' => 0, 'refunded_amount_cents' => 0],
            ['pagarme_charge_id' => 2, 'external_charge_id' => 'ch_paid', 'status' => 'paid', 'amount_cents' => 10_001, 'paid_amount_cents' => 10_001, 'refunded_amount_cents' => 0, 'paid_at' => '2026-07-27 12:00:00'],
        ], 10_001);
        self::assertSame('ch_paid', $selected['external_charge_id']);
    }

    public function testFullRefundUsesImmutableSplitAndPartialRefundStaysDisabled(): void
    {
        $builder = new PagarmeRefundRequestBuilder();
        $payload = $builder->full([
            ['recipient_id' => 'rp_seller', 'split_amount_cents' => 9_001],
            ['recipient_id' => 'rp_platform', 'split_amount_cents' => 1_000, 'liable' => true, 'charge_processing_fee' => true, 'charge_remainder_fee' => true],
        ], 10_001);
        self::assertSame(10_001, array_sum(array_column($payload['split'], 'amount')));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('permanece desabilitado');
        $builder->partial(500);
    }

    public function testRefundDoesNotGuessWhenTwoChargesArePaid(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mais de uma cobrança paga');
        (new PagarmeRefundableChargeSelector())->select([
            ['pagarme_charge_id' => 1, 'external_charge_id' => 'ch_paid_1', 'status' => 'paid', 'amount_cents' => 10_001, 'paid_amount_cents' => 10_001, 'refunded_amount_cents' => 0],
            ['pagarme_charge_id' => 2, 'external_charge_id' => 'ch_paid_2', 'status' => 'paid', 'amount_cents' => 10_001, 'paid_amount_cents' => 10_001, 'refunded_amount_cents' => 0],
        ], 10_001);
    }

    public function testRefundSplitWhoseSumDoesNotCloseIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não fecha');
        (new PagarmeRefundRequestBuilder())->full([
            ['recipient_id' => 'rp_seller', 'split_amount_cents' => 9_000],
            ['recipient_id' => 'rp_platform', 'split_amount_cents' => 1_000],
        ], 10_001);
    }

    /** @return array{liable:bool,charge_processing_fee:bool,charge_remainder_fee:bool} */
    private function sellerOptions(): array
    {
        return ['liable' => false, 'charge_processing_fee' => false, 'charge_remainder_fee' => false];
    }

    /** @return array{liable:bool,charge_processing_fee:bool,charge_remainder_fee:bool} */
    private function platformOptions(): array
    {
        return ['liable' => true, 'charge_processing_fee' => true, 'charge_remainder_fee' => true];
    }

    /** @return array<string,mixed> */
    private function splitRow(string $key, string $type, string $recipientId, int $amount): array
    {
        return [
            'participant_key' => $key,
            'participant_type' => $type,
            'recipient_id' => $recipientId,
            'split_amount_cents' => $amount,
            'liable' => $type === 'platform',
            'charge_processing_fee' => $type === 'platform',
            'charge_remainder_fee' => $type === 'platform',
        ];
    }
}

final class HomologationFakeClient implements PagarmeApiClient
{
    public int $listCalls = 0;
    public int $postCalls = 0;

    public function configured(): bool { return true; }
    public function environment(): string { return 'test'; }
    public function get(string $endpoint): array
    {
        if (str_starts_with($endpoint, '/orders?')) {
            $this->listCalls++;
            if ($this->listCalls === 1) {
                throw new PagarmeException('Falha de rede simulada.');
            }
            return ['data' => [['id' => 'or_retry', 'code' => 'TF-RETRY', 'amount' => 10_001]]];
        }
        return [
            'id' => 'or_retry',
            'code' => 'TF-RETRY',
            'amount' => 10_001,
            'charges' => [['id' => 'ch_retry', 'status' => 'pending', 'amount' => 10_001]],
        ];
    }
    public function post(string $endpoint, array $payload = [], ?string $idempotencyKey = null): array
    {
        $this->postCalls++;
        return [];
    }
    public function put(string $endpoint, array $payload, ?string $idempotencyKey = null): array { return []; }
    public function delete(string $endpoint, array $payload = [], ?string $idempotencyKey = null): array { return []; }
}
