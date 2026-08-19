<?php

declare(strict_types=1);

namespace App\Services\Payments;

interface PaymentGateway
{
    public function configured(): bool;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createPaymentLink(array $payload, string $idempotencyKey): array;

    public function cancelPaymentLink(string $paymentLinkId): void;
}
