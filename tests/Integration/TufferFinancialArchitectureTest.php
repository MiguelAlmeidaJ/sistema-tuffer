<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Finance\FinancialSettlementService;
use App\Services\Finance\FinancialSplitConsolidator;
use App\Services\Finance\OfficialStoreTransferPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TufferFinancialArchitectureTest extends TestCase
{
    public function testOfficialStoreAndMarketplaceAmountsConsolidateIntoPlatformRecipient(): void
    {
        $rules = (new FinancialSplitConsolidator())->consolidateRows([
            ['recipient_id' => 'rp_TUFFER', 'direction' => 'credit', 'amount_cents' => 9_000],
            ['recipient_id' => 'rp_TUFFER', 'direction' => 'credit', 'amount_cents' => 1_001],
        ], 10_001, 'rp_TUFFER');

        self::assertCount(1, $rules);
        self::assertSame('rp_TUFFER', $rules[0]->recipientId);
        self::assertSame(10_001, $rules[0]->amount);
        self::assertTrue($rules[0]->options['liable']);
    }

    public function testMixedCartKeepsExternalRecipientSeparate(): void
    {
        $rules = (new FinancialSplitConsolidator())->consolidateRows([
            ['recipient_id' => 'rp_TUFFER', 'direction' => 'credit', 'amount_cents' => 6_001],
            ['recipient_id' => 'rp_EXTERNAL', 'direction' => 'credit', 'amount_cents' => 4_000],
        ], 10_001, 'rp_TUFFER');

        self::assertCount(2, $rules);
        self::assertSame(10_001, array_sum(array_map(static fn($rule): int => $rule->amount, $rules)));
        self::assertFalse($rules[0]->options['liable']);
        self::assertTrue($rules[1]->options['liable']);
    }

    public function testSplitRejectsDivergenceAndZeroEntries(): void
    {
        $service = new FinancialSplitConsolidator();
        try {
            $service->consolidateRows([
                ['recipient_id' => 'rp_TUFFER', 'direction' => 'credit', 'amount_cents' => 10_000],
            ], 10_001, 'rp_TUFFER');
            self::fail('A soma divergente deveria ser recusada.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('soma', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $service->consolidateRows([
            ['recipient_id' => 'rp_TUFFER', 'direction' => 'credit', 'amount_cents' => 0],
        ], 0, 'rp_TUFFER');
    }

    public function testPercentageReserveUsesIntegerCents(): void
    {
        $result = (new OfficialStoreTransferPolicy([
            'official_store_reserve_percentage' => 5,
            'official_store_reserve_min_amount' => 0,
        ]))->calculate(10_001, 2_000, 500, 0, 0, 0, 0);

        self::assertSame(500, $result['reserve_cents']);
        self::assertSame(7_001, $result['transferable_cents']);
    }

    public function testMinimumReserveWinsOverPercentage(): void
    {
        $result = (new OfficialStoreTransferPolicy([
            'official_store_reserve_percentage' => 5,
            'official_store_reserve_min_amount' => 1_000,
        ]))->calculate(10_000, 2_000, 500, 0, 0, 0, 0);

        self::assertSame(1_000, $result['reserve_cents']);
        self::assertSame(6_500, $result['transferable_cents']);
    }

    public function testTransferPolicyBlocksDisabledOverdraftDivergenceAndChargeback(): void
    {
        $disabled = new OfficialStoreTransferPolicy(['official_store_transfer_enabled' => false]);
        try {
            $disabled->assertTransferAllowed(100, 100, false, false, 'approved');
            self::fail('Transferência desabilitada deveria ser recusada.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $enabled = new OfficialStoreTransferPolicy(['official_store_transfer_enabled' => true]);
        foreach ([
            [101, 100, false, false, 'approved'],
            [100, 100, true, false, 'approved'],
            [100, 100, false, true, 'approved'],
            [100, 100, false, false, 'awaiting_review'],
        ] as $case) {
            try {
                $enabled->assertTransferAllowed(...$case);
                self::fail('A condição insegura deveria bloquear a transferência.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPartialAndTotalTransfersCanOnlyUseRemainingBalance(): void
    {
        $policy = new OfficialStoreTransferPolicy(['official_store_transfer_enabled' => true]);
        $policy->assertTransferAllowed(4_000, 10_000, false, false, 'approved');
        $policy->assertTransferAllowed(6_000, 6_000, false, false, 'partially_transferred');
        self::assertTrue(true);
    }

    public function testSettlementKeepsRevenueSeparateFromRefund(): void
    {
        $totals = (new FinancialSettlementService())->totals([
            $this->entry('official_store_net_revenue', 'credit', 10_001, true, 'financial_snapshot_line', ['product_cost_known' => true]),
            $this->entry('official_store_refund', 'debit', 2_000, true, 'financial_entry_reversal', ['reason' => 'refund']),
        ], 'official_store');

        self::assertSame(10_001, $totals['net_revenue']);
        self::assertSame(2_000, $totals['refunds']);
        self::assertSame(0, $totals['chargebacks']);
    }

    public function testSettlementSeparatesChargebackFromRefund(): void
    {
        $totals = (new FinancialSettlementService())->totals([
            $this->entry('marketplace_service_fee', 'credit', 1_001, true),
            $this->entry('marketplace_chargeback', 'debit', 1_001, true, 'financial_entry_reversal', ['reason' => 'chargeback']),
        ], 'marketplace');

        self::assertSame(1_001, $totals['net_revenue']);
        self::assertSame(0, $totals['refunds']);
        self::assertSame(1_001, $totals['chargebacks']);
    }

    public function testMissingProductCostDoesNotSilentlyBecomeZero(): void
    {
        $totals = (new FinancialSettlementService())->totals([
            $this->entry('official_store_net_revenue', 'credit', 10_000, true, 'financial_snapshot_line', ['product_cost_known' => false]),
        ], 'official_store');

        self::assertNull($totals['product_cost']);
    }

    public function testKnownZeroProductCostRemainsKnown(): void
    {
        $totals = (new FinancialSettlementService())->totals([
            $this->entry('official_store_net_revenue', 'credit', 10_000, true, 'financial_snapshot_line', ['product_cost_known' => true]),
        ], 'official_store');

        self::assertSame(0, $totals['product_cost']);
    }

    public function testProductCostAndMarketplaceCommissionStayInDifferentCenters(): void
    {
        $service = new FinancialSettlementService();
        $official = $service->totals([
            $this->entry('official_store_net_revenue', 'credit', 9_000, true, 'financial_snapshot_line', ['product_cost_known' => true]),
            $this->entry('official_store_product_cost', 'debit', 4_000),
        ], 'official_store');
        $marketplace = $service->totals([
            $this->entry('marketplace_service_fee', 'credit', 1_000, true),
            $this->entry('marketplace_commission', 'credit', 1_000),
        ], 'marketplace');

        self::assertSame(4_000, $official['product_cost']);
        self::assertSame(9_000, $official['net_revenue']);
        self::assertSame(1_000, $marketplace['net_revenue']);
        self::assertSame(1_000, $marketplace['gross']);
    }

    public function testFinancialMigrationsProtectSnapshotsLedgerAndTransfers(): void
    {
        $root = dirname(__DIR__, 2);
        $ledger = file_get_contents($root . '/database/migrations/027_create_financial_ledger_and_expand_snapshots.sql');
        $cost = file_get_contents($root . '/database/migrations/028_create_product_cost_history.sql');
        $transfer = file_get_contents($root . '/database/migrations/030_create_financial_transfers.sql');

        self::assertStringContainsString('trg_financial_entries_no_delete', (string) $ledger);
        self::assertStringContainsString('trg_financial_snapshot_items_no_update', (string) $ledger);
        self::assertStringContainsString('uk_financial_entry_idempotency', (string) $ledger);
        self::assertStringContainsString('product_cost_history', (string) $cost);
        self::assertStringContainsString('idempotency_key', (string) $transfer);
    }

    public function testOfficialStoreIsResolvedExplicitlyWithoutNameSlugOrFixedId(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Sellers/OfficialStoreResolver.php');
        self::assertStringContainsString('is_official_store=1', (string) $source);
        self::assertStringNotContainsString('Tuffer Oficial', (string) $source);
        self::assertStringNotContainsString('sellerId = 1', (string) $source);
    }

    public function testSnapshotPolicyVersionIsCopiedIntoLedgerInsteadOfRecalculated(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/Finance/MarketplaceFinancialLedgerService.php');
        self::assertStringContainsString("\$line['policy_version']", (string) $source);
        self::assertStringContainsString("\$common['policy_version']", (string) $source);
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function entry(
        string $type,
        string $direction,
        int $amount,
        bool $split = false,
        string $source = 'financial_snapshot_line',
        array $metadata = []
    ): array {
        return [
            'entry_type' => $type,
            'direction' => $direction,
            'amount_cents' => $amount,
            'is_split_component' => $split ? 1 : 0,
            'source_type' => $source,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ];
    }
}
