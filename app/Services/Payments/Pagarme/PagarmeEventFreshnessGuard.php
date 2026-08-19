<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

final class PagarmeEventFreshnessGuard
{
    public function isStale(?string $incomingAt, ?string $persistedAt): bool
    {
        if ($incomingAt === null || $persistedAt === null) {
            return false;
        }
        $incoming = strtotime($incomingAt);
        $persisted = strtotime($persistedAt);
        return $incoming !== false && $persisted !== false && $incoming < $persisted;
    }
}
