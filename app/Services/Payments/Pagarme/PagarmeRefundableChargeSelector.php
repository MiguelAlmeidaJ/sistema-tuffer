<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use RuntimeException;

final class PagarmeRefundableChargeSelector
{
    /** @param array<int,array<string,mixed>> $charges @return array<string,mixed> */
    public function select(array $charges, int $paymentAmountCents): array
    {
        $eligible = array_values(array_filter($charges, static fn(array $charge): bool =>
            ($charge['status'] ?? null) === 'paid'
            && (int) ($charge['amount_cents'] ?? 0) === $paymentAmountCents
            && (int) ($charge['paid_amount_cents'] ?? 0) === $paymentAmountCents
            && (int) ($charge['refunded_amount_cents'] ?? 0) === 0
            && trim((string) ($charge['external_charge_id'] ?? '')) !== ''
        ));
        usort($eligible, static fn(array $left, array $right): int =>
            strcmp(
                (string) ($right['paid_at'] ?? '') . '|' . str_pad((string) ($right['pagarme_charge_id'] ?? 0), 20, '0', STR_PAD_LEFT),
                (string) ($left['paid_at'] ?? '') . '|' . str_pad((string) ($left['pagarme_charge_id'] ?? 0), 20, '0', STR_PAD_LEFT)
            )
        );
        if ($eligible === []) {
            throw new RuntimeException('Não existe uma cobrança Pix paga e integralmente estornável para este pagamento.');
        }
        if (count($eligible) > 1) {
            throw new RuntimeException('Há mais de uma cobrança paga; o estorno exige revisão administrativa do charge_id correto.');
        }
        return $eligible[0];
    }
}
