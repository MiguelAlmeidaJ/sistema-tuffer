<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\Pagarme\PagarmeSplitService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PagarmeSplitServiceTest extends TestCase
{
    public function testBuildsFlatSplitForMultipleSellersAndPlatform(): void
    {
        $rows = [
            $this->row('seller:10', 'seller', 'rp_sellerA', 9_000),
            $this->row('seller:20', 'seller', 'rp_sellerB', 13_501),
            $this->row('platform', 'platform', 'rp_platform', 2_499, true),
        ];

        $rules = (new PagarmeSplitService())->rulesFromRows($rows, 25_000);

        self::assertCount(3, $rules);
        self::assertSame(25_000, array_sum(array_map(static fn($rule): int => $rule->amount, $rules)));
        self::assertSame('flat', $rules[0]->type);
        self::assertTrue($rules[2]->options['charge_processing_fee']);
    }

    public function testRejectsSplitWhoseSumDiffersByOneCent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('soma do split');
        (new PagarmeSplitService())->rulesFromRows([
            $this->row('seller:10', 'seller', 'rp_sellerA', 9_000),
            $this->row('platform', 'platform', 'rp_platform', 999, true),
        ], 10_000);
    }

    public function testRejectsDuplicateSellerParticipant(): void
    {
        $this->expectException(RuntimeException::class);
        (new PagarmeSplitService())->rulesFromRows([
            $this->row('seller:10', 'seller', 'rp_sellerA', 9_000),
            $this->row('seller:10', 'seller', 'rp_sellerA', 1),
            $this->row('platform', 'platform', 'rp_platform', 999, true),
        ], 10_000);
    }

    public function testRejectsZeroSplitEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maior que zero');

        (new PagarmeSplitService())->rulesFromRows([
            $this->row('seller:10', 'seller', 'rp_sellerA', 10_000),
            $this->row('platform', 'platform', 'rp_platform', 0, true),
        ], 10_000);
    }

    public function testBlocksSellerWhoseRecipientOrKycIsNotApproved(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deixou de estar habilitado');
        (new PagarmeSplitService())->assertRecipientEligible(
            ['recipient_id' => 'rp_sellerA'],
            ['recipient_id' => 'rp_sellerA', 'recipient_status' => 'active', 'kyc_status' => 'pending']
        );
    }

    /** @return array<string,mixed> */
    private function row(string $key, string $type, string $recipient, int $amount, bool $platform = false): array
    {
        return [
            'participant_key' => $key,
            'participant_type' => $type,
            'recipient_id' => $recipient,
            'split_amount_cents' => $amount,
            'liable' => $platform,
            'charge_processing_fee' => $platform,
            'charge_remainder_fee' => $platform,
        ];
    }
}
