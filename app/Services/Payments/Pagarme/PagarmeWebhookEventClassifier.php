<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

final class PagarmeWebhookEventClassifier
{
    public function classify(string $eventType, ?string $providerStatus = null): string
    {
        $status = strtolower(trim((string) $providerStatus));
        if ($status === 'expired') {
            return 'expired';
        }
        if (in_array($eventType, ['order.paid', 'charge.paid'], true) || $status === 'paid') {
            return 'paid';
        }
        if (in_array($eventType, ['order.payment_failed', 'charge.payment_failed'], true)
            || in_array($status, ['failed', 'payment_failed'], true)) {
            return 'failed';
        }
        if (in_array($eventType, ['order.canceled', 'checkout.canceled'], true)
            || in_array($status, ['canceled', 'cancelled'], true)) {
            return 'cancelled';
        }
        if (in_array($eventType, ['charge.refunded', 'charge.chargedback', 'chargeback.received'], true)
            || in_array($status, ['refunded', 'chargedback'], true)) {
            return 'refunded';
        }
        if (in_array($status, ['pending', 'waiting_payment'], true)) {
            return 'waiting_payment';
        }
        if ($status === 'processing') {
            return 'processing';
        }
        return 'informational';
    }
}
