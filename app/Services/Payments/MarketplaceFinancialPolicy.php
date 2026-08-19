<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

final class MarketplaceFinancialPolicy
{
    public const VERSION = 'marketplace-split-v3-central-shipping';

    public function couponFundingSource(mixed $value): string
    {
        $source = strtolower(trim((string) $value));
        if ($source === '') {
            $source = strtolower(trim((string) ($_ENV['MARKETPLACE_COUPON_DEFAULT_FUNDER'] ?? 'seller')));
        }
        return $source === 'platform' ? 'platform' : 'seller';
    }

    /**
     * @return array{
     *   commission_cents:int,seller_discount_cents:int,platform_discount_cents:int,
     *   seller_net_cents:int,platform_contribution_cents:int,shipping_seller_cents:int,
     *   shipping_platform_cents:int,commission_rate_basis_points:int
     * }
     */
    public function sellerAmounts(
        int $productsCents,
        int $shippingCents,
        int $discountCents,
        string|int|float $commissionRate,
        string $couponFundingSource
    ): array {
        if ($productsCents < 0 || $shippingCents < 0 || $discountCents < 0 || $discountCents > $productsCents) {
            throw new RuntimeException('Os componentes financeiros do pedido são inválidos.');
        }

        $commissionBase = $productsCents - $discountCents;
        $commissionRateBasisPoints = $this->commissionBasisPoints($commissionRate);
        $commissionCents = intdiv(($commissionBase * $commissionRateBasisPoints) + 5_000, 10_000);
        $platformFunded = $this->couponFundingSource($couponFundingSource) === 'platform';
        $sellerDiscount = $platformFunded ? 0 : $discountCents;
        $platformDiscount = $platformFunded ? $discountCents : 0;
        $shippingRecipient = $this->shippingRecipient();
        $shippingSeller = $shippingRecipient === 'seller' ? $shippingCents : 0;
        $shippingPlatform = $shippingRecipient === 'platform' ? $shippingCents : 0;
        $sellerNet = $productsCents + $shippingSeller - $sellerDiscount - $commissionCents;

        if ($sellerNet < 0) {
            throw new RuntimeException('O líquido calculado para o vendedor não pode ser negativo.');
        }
        if ($platformDiscount > $commissionCents && $this->requiresCommissionCoverage()) {
            throw new RuntimeException('O desconto financiado pela plataforma excede a comissão da Tuffer neste pedido.');
        }

        return [
            'commission_cents' => $commissionCents,
            'seller_discount_cents' => $sellerDiscount,
            'platform_discount_cents' => $platformDiscount,
            'seller_net_cents' => $sellerNet,
            'platform_contribution_cents' => $commissionCents - $platformDiscount + $shippingPlatform,
            'shipping_seller_cents' => $shippingSeller,
            'shipping_platform_cents' => $shippingPlatform,
            'commission_rate_basis_points' => $commissionRateBasisPoints,
        ];
    }

    public function shippingRecipient(): string
    {
        return strtolower(trim((string) ($_ENV['MARKETPLACE_SHIPPING_RECIPIENT'] ?? 'platform'))) === 'platform'
            ? 'platform'
            : 'seller';
    }

    /** @return array{liable:bool,charge_processing_fee:bool,charge_remainder_fee:bool} */
    public function sellerOptions(): array
    {
        return ['liable' => false, 'charge_processing_fee' => false, 'charge_remainder_fee' => false];
    }

    /** @return array{liable:bool,charge_processing_fee:bool,charge_remainder_fee:bool} */
    public function platformOptions(): array
    {
        return ['liable' => true, 'charge_processing_fee' => true, 'charge_remainder_fee' => true];
    }

    public function assertPlatformAmount(int $amountCents): void
    {
        if ($amountCents < 0 && $this->requiresCommissionCoverage()) {
            throw new RuntimeException('O desconto financiado pela plataforma excede a receita da Tuffer neste pedido.');
        }
        if ($amountCents < 0) {
            throw new RuntimeException('O split não suporta uma parcela negativa para a plataforma.');
        }
    }

    public function commissionBasisPoints(string|int|float $rate): int
    {
        $normalized = number_format(max(0.0, min(100.0, (float) $rate)), 2, '.', '');
        [$whole, $fraction] = explode('.', $normalized);
        return ((int) $whole * 100) + (int) $fraction;
    }

    private function requiresCommissionCoverage(): bool
    {
        return filter_var(
            $_ENV['MARKETPLACE_PLATFORM_COUPON_REQUIRES_COMMISSION_COVERAGE'] ?? true,
            FILTER_VALIDATE_BOOL
        );
    }
}
