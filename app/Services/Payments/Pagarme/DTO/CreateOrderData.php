<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme\DTO;

use InvalidArgumentException;

final readonly class CreateOrderData
{
    /**
     * @param array<int,OrderItemData> $items
     * @param array<string,mixed> $customer
     * @param array<string,mixed> $shipping
     */
    public function __construct(
        public string $code,
        public array $items,
        public array $customer,
        public array $shipping,
        public PixPaymentData $payment
    ) {
        if (trim($code) === '' || $items === [] || $customer === []) {
            throw new InvalidArgumentException('Os dados do pedido Pagar.me estão incompletos.');
        }
        foreach ($items as $item) {
            if (!$item instanceof OrderItemData) {
                throw new InvalidArgumentException('Um item do pedido Pagar.me é inválido.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code' => mb_substr(trim($this->code), 0, 52),
            'closed' => true,
            'items' => array_map(static fn(OrderItemData $item): array => $item->toArray(), $this->items),
            'customer' => $this->customer,
            'shipping' => $this->shipping,
            'payments' => [$this->payment->toArray()],
            'metadata' => ['integration' => 'tuffer-marketplace-orders-v1'],
        ];
    }
}
