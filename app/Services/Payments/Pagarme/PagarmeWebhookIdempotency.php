<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use App\Services\Payments\PagarmeWebhookException;

final class PagarmeWebhookIdempotency
{
    public function assertPayload(string $storedHash, string $incomingHash): void
    {
        if (!hash_equals($storedHash, $incomingHash)) {
            throw new PagarmeWebhookException('Conflito de idempotência no webhook.', 409);
        }
    }

    public function alreadyHandled(string $status): bool
    {
        return in_array($status, ['processed', 'ignored'], true);
    }
}
