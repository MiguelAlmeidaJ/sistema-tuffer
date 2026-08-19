<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme\DTO;

use App\Services\Payments\Pagarme\PagarmeRecipientId;
use InvalidArgumentException;

final readonly class SplitRuleData
{
    /** @param array{liable:bool,charge_processing_fee:bool,charge_remainder_fee:bool} $options */
    public function __construct(
        public int $amount,
        public string $recipientId,
        public array $options,
        public string $type = 'flat'
    ) {
        if ($amount < 1) {
            throw new InvalidArgumentException('O valor do split precisa ser maior que zero.');
        }
        if (!PagarmeRecipientId::isValid($recipientId)) {
            throw new InvalidArgumentException('O recebedor do split é inválido.');
        }
        if ($type !== 'flat') {
            throw new InvalidArgumentException('A Tuffer utiliza split fixo em centavos.');
        }
    }

    /** @return array{amount:int,recipient_id:string,type:string,options:array{liable:bool,charge_processing_fee:bool,charge_remainder_fee:bool}} */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'recipient_id' => $this->recipientId,
            'type' => $this->type,
            'options' => $this->options,
        ];
    }
}
