<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

final class PagarmePayloadSanitizer
{
    /** @param array<string,mixed> $response @return array<string,mixed> */
    public function orderResponse(array $response): array
    {
        $safe = [
            'id' => $this->string($response['id'] ?? null),
            'code' => $this->string($response['code'] ?? null),
            'status' => $this->string($response['status'] ?? null),
            'amount' => $this->integer($response['amount'] ?? null),
            'currency' => $this->string($response['currency'] ?? null),
            'charges' => [],
        ];
        foreach (is_array($response['charges'] ?? null) ? $response['charges'] : [] as $charge) {
            if (is_array($charge)) {
                $safe['charges'][] = $this->charge($charge);
            }
        }
        return $safe;
    }

    /** @param array<string,mixed> $charge @return array<string,mixed> */
    public function charge(array $charge): array
    {
        $transaction = is_array($charge['last_transaction'] ?? null) ? $charge['last_transaction'] : [];
        return [
            'id' => $this->string($charge['id'] ?? null),
            'gateway_id' => $this->string($charge['gateway_id'] ?? null),
            'status' => $this->string($charge['status'] ?? null),
            'amount' => $this->integer($charge['amount'] ?? null),
            'paid_amount' => $this->integer($charge['paid_amount'] ?? null),
            'refunded_amount' => $this->integer($charge['refunded_amount'] ?? null),
            'payment_method' => $this->string($charge['payment_method'] ?? null),
            'paid_at' => $this->string($charge['paid_at'] ?? null),
            'last_transaction' => [
                'id' => $this->string($transaction['id'] ?? null),
                'gateway_id' => $this->string($transaction['gateway_id'] ?? null),
                'status' => $this->string($transaction['status'] ?? null),
                'expires_at' => $this->string($transaction['expires_at'] ?? null),
            ],
        ];
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    public function webhookEvent(array $event): array
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $safeData = [
            'id' => $this->string($data['id'] ?? null),
            'code' => $this->string($data['code'] ?? null),
            'status' => $this->string($data['status'] ?? null),
            'amount' => $this->integer($data['amount'] ?? null),
            'paid_amount' => $this->integer($data['paid_amount'] ?? null),
            'refunded_amount' => $this->integer($data['refunded_amount'] ?? null),
            'currency' => $this->string($data['currency'] ?? null),
            'paid_at' => $this->string($data['paid_at'] ?? null),
            'charge_id' => $this->string($data['charge_id'] ?? null),
            'order' => $this->identifier($data['order'] ?? null),
            'charge' => is_array($data['charge'] ?? null) ? $this->charge($data['charge']) : null,
            'last_transaction' => is_array($data['last_transaction'] ?? null)
                ? $this->charge(['last_transaction' => $data['last_transaction']])['last_transaction']
                : null,
            'charges' => [],
            'metadata' => [
                'order_code' => $this->string(
                    is_array($data['metadata'] ?? null) ? ($data['metadata']['order_code'] ?? null) : null
                ),
            ],
            'chargeback' => is_array($data['chargeback'] ?? null)
                ? ['charge_id' => $this->string($data['chargeback']['charge_id'] ?? null)]
                : null,
        ];
        foreach (is_array($data['charges'] ?? null) ? $data['charges'] : [] as $charge) {
            if (is_array($charge)) {
                $safeData['charges'][] = $this->charge($charge);
            }
        }
        return [
            'id' => $this->string($event['id'] ?? null),
            'type' => $this->string($event['type'] ?? $event['event'] ?? null),
            'created_at' => $this->string($event['created_at'] ?? null),
            'data' => $safeData,
        ];
    }

    /** @return array{id:?string,code:?string}|null */
    private function identifier(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        return ['id' => $this->string($value['id'] ?? null), 'code' => $this->string($value['code'] ?? null)];
    }

    private function string(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, 191);
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) || is_numeric($value) ? (int) $value : null;
    }
}
