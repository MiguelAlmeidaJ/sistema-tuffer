<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Services\Settings\PlatformSettings;
use RuntimeException;

final class OfficialStoreTransferPolicy
{
    /** @param array<string,mixed>|null $settings */
    public function __construct(private readonly ?array $settings = null)
    {
    }

    /** @return array{reserve_cents:int,transferable_cents:int} */
    public function calculate(
        int $netRevenueCents,
        int $productCostCents,
        int $taxProvisionCents,
        int $refundsCents,
        int $chargebacksCents,
        int $adjustmentsCents,
        int $alreadyTransferredCents
    ): array {
        $settings = $this->settings ?? PlatformSettings::all();
        $percentage = max(0.0, min(100.0, (float) (
            $settings['official_store_reserve_percentage'] ?? $_ENV['OFFICIAL_STORE_RESERVE_PERCENTAGE'] ?? 5
        )));
        $minimum = max(0, (int) (
            $settings['official_store_reserve_min_amount'] ?? $_ENV['OFFICIAL_STORE_RESERVE_MIN_AMOUNT'] ?? 0
        ));
        $reserve = max((int) round(max(0, $netRevenueCents) * $percentage / 100), $minimum);
        $available = $netRevenueCents - $productCostCents - $taxProvisionCents
            - $refundsCents - $chargebacksCents - $reserve + $adjustmentsCents - $alreadyTransferredCents;
        return ['reserve_cents' => $reserve, 'transferable_cents' => max(0, $available)];
    }

    public function assertTransferAllowed(
        int $requestedCents,
        int $availableCents,
        bool $hasCriticalDivergence,
        bool $hasOpenChargeback,
        string $settlementStatus
    ): void {
        $enabled = ($this->settings ?? PlatformSettings::all())['official_store_transfer_enabled']
            ?? $_ENV['OFFICIAL_STORE_TRANSFER_ENABLED']
            ?? false;
        if (!filter_var($enabled, FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Transferências manuais permanecem desabilitadas por configuração.');
        }
        if ($settlementStatus !== 'approved' && $settlementStatus !== 'partially_transferred') {
            throw new RuntimeException('O fechamento precisa estar aprovado.');
        }
        if ($hasCriticalDivergence || $hasOpenChargeback) {
            throw new RuntimeException('Divergência crítica ou chargeback aberto impede a transferência.');
        }
        if ($requestedCents < 1 || $requestedCents > $availableCents) {
            throw new RuntimeException('O valor solicitado excede o saldo transferível.');
        }
    }
}
