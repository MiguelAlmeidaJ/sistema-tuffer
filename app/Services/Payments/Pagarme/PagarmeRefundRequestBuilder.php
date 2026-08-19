<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use RuntimeException;

final class PagarmeRefundRequestBuilder
{
    /** @param array<int,array<string,mixed>> $snapshotRows @return array{split:array<int,array<string,mixed>>} */
    public function full(array $snapshotRows, int $chargeAmountCents): array
    {
        $rules = [];
        foreach ($snapshotRows as $row) {
            $amount = (int) ($row['split_amount_cents'] ?? 0);
            if ($amount < 1) {
                throw new RuntimeException('O split de estorno contém uma entrada zerada.');
            }
            $rules[] = [
                'amount' => $amount,
                'recipient_id' => (string) ($row['recipient_id'] ?? ''),
                'type' => 'flat',
                'options' => [
                    'liable' => (bool) ($row['liable'] ?? false),
                    'charge_processing_fee' => (bool) ($row['charge_processing_fee'] ?? false),
                    'charge_remainder_fee' => (bool) ($row['charge_remainder_fee'] ?? false),
                ],
            ];
        }
        if ($rules === [] || array_sum(array_column($rules, 'amount')) !== $chargeAmountCents) {
            throw new RuntimeException('O split imutável não fecha com a cobrança selecionada para estorno.');
        }
        return ['split' => $rules];
    }

    public function partial(int $amountCents): never
    {
        throw new RuntimeException(
            'Estorno parcial está estruturado, mas permanece desabilitado até homologação do recálculo de split.'
        );
    }
}
