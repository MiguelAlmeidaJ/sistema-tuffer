<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\FinancialSplitConsolidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FinancialSplitConsolidatorTest extends TestCase
{
    public function testConsolidatesOfficialStoreAndMarketplaceBySameRecipient(): void
    {
        $rules = (new FinancialSplitConsolidator())->consolidateRows([
            ['recipient_id' => 'rp_TUFFER', 'direction' => 'credit', 'amount_cents' => 10_000],
            ['recipient_id' => 'rp_TUFFER', 'direction' => 'credit', 'amount_cents' => 1_000],
            ['recipient_id' => 'rp_EXTERNAL', 'direction' => 'credit', 'amount_cents' => 9_000],
        ], 20_000, 'rp_TUFFER');

        self::assertCount(2, $rules);
        self::assertSame(20_000, array_sum(array_map(static fn($rule): int => $rule->amount, $rules)));
        self::assertSame(11_000, array_values(array_filter($rules, static fn($rule): bool => $rule->recipientId === 'rp_TUFFER'))[0]->amount);
    }

    public function testRejectsDivergentConsolidatedTotal(): void
    {
        $this->expectException(RuntimeException::class);
        (new FinancialSplitConsolidator())->consolidateRows([
            ['recipient_id' => 'rp_TUFFER', 'direction' => 'credit', 'amount_cents' => 9_999],
        ], 10_000, 'rp_TUFFER');
    }
}
