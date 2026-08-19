<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme\DTO;

use InvalidArgumentException;

final readonly class OrderItemData
{
    public function __construct(
        public int $amount,
        public string $description,
        public int $quantity = 1,
        public ?string $code = null
    ) {
        if ($amount < 1 || $quantity < 1 || trim($description) === '') {
            throw new InvalidArgumentException('O item do pedido Pagar.me é inválido.');
        }
    }

    /** @return array<string,int|string> */
    public function toArray(): array
    {
        $item = [
            'amount' => $this->amount,
            'description' => mb_substr(trim($this->description), 0, 255),
            'quantity' => $this->quantity,
        ];
        if ($this->code !== null && trim($this->code) !== '') {
            $item['code'] = mb_substr(trim($this->code), 0, 52);
        }
        return $item;
    }
}
