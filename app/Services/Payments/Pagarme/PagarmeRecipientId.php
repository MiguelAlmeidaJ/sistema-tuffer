<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

final class PagarmeRecipientId
{
    public static function isValid(string $recipientId): bool
    {
        return preg_match('/^(?:re|rp)_[A-Za-z0-9_-]+$/', trim($recipientId)) === 1;
    }
}
