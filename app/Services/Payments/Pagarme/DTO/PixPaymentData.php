<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme\DTO;

use InvalidArgumentException;

final readonly class PixPaymentData
{
    /** @param array<int,SplitRuleData> $split */
    public function __construct(public int $expiresIn, public array $split)
    {
        if ($expiresIn < 300 || $split === []) {
            throw new InvalidArgumentException('A configuração Pix do pedido é inválida.');
        }
        foreach ($split as $rule) {
            if (!$rule instanceof SplitRuleData) {
                throw new InvalidArgumentException('A regra de split do Pix é inválida.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'payment_method' => 'pix',
            'pix' => ['expires_in' => $this->expiresIn],
            'split' => array_map(static fn(SplitRuleData $rule): array => $rule->toArray(), $this->split),
        ];
    }
}
