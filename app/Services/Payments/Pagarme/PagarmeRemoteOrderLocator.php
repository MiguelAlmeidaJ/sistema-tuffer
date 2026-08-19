<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use App\Services\Payments\PagarmeApiClient;
use RuntimeException;

final class PagarmeRemoteOrderLocator
{
    public function __construct(private readonly PagarmeApiClient $client)
    {
    }

    /** @return array<string,mixed>|null */
    public function byCode(string $orderCode, int $amountCents): ?array
    {
        $response = $this->client->get(
            '/orders?code=' . rawurlencode($orderCode) . '&page=1&size=30'
        );
        $orders = is_array($response['data'] ?? null) ? $response['data'] : [];
        foreach ($orders as $order) {
            if (!is_array($order) || !hash_equals($orderCode, (string) ($order['code'] ?? ''))) {
                continue;
            }
            if ((int) ($order['amount'] ?? -1) !== $amountCents) {
                throw new RuntimeException('O pedido remoto com o mesmo código possui valor divergente.');
            }
            $externalOrderId = trim((string) ($order['id'] ?? ''));
            if (!preg_match('/^or_[A-Za-z0-9_-]+$/', $externalOrderId)) {
                throw new RuntimeException('O pedido remoto localizado possui identificador inválido.');
            }
            $detail = $this->client->get('/orders/' . rawurlencode($externalOrderId));
            if (!hash_equals($orderCode, (string) ($detail['code'] ?? ''))
                || (int) ($detail['amount'] ?? -1) !== $amountCents) {
                throw new RuntimeException('Os detalhes do pedido remoto divergem da tentativa local.');
            }
            return $detail;
        }
        return null;
    }
}
