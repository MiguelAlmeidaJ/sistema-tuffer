<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

final class PagarmeCheckoutConfiguration
{
    public function mode(): string
    {
        return $this->ordersPixEnabled()
            && $this->splitEnabled()
            && $this->validPlatformRecipientId()
            ? 'orders_pix_limited'
            : 'payment_link';
    }

    /** @param array<int,int> $sellerIds */
    public function usesOrders(string $paymentMethod, array $sellerIds = []): bool
    {
        if ($paymentMethod !== 'pix'
            || !$this->ordersPixEnabled()
            || !$this->splitEnabled()
            || !$this->validPlatformRecipientId()
            || $sellerIds === []) {
            return false;
        }

        foreach (array_unique(array_map('intval', $sellerIds)) as $sellerId) {
            if ($sellerId < 1) {
                return false;
            }
        }

        return true;
    }

    public function ordersPixEnabled(): bool
    {
        return $this->boolean('PAGARME_ORDERS_PIX_ENABLED');
    }

    public function publicKey(): string
    {
        return trim((string) ($_ENV['PAGARME_PUBLIC_KEY'] ?? ''));
    }

    public function validPublicKey(): bool
    {
        return preg_match('/^pk_(?:test_)?[A-Za-z0-9_-]+$/', $this->publicKey()) === 1;
    }

    public function tokenizationUrl(): string
    {
        return str_starts_with($this->publicKey(), 'pk_test_')
            ? 'https://sdx-api.pagar.me/core/v5/tokens'
            : 'https://api.pagar.me/core/v5/tokens';
    }

    public function cardCheckoutConfigured(): bool
    {
        return $this->validPublicKey()
            && $this->splitEnabled()
            && $this->validPlatformRecipientId();
    }

    public function splitEnabled(): bool
    {
        return $this->boolean('PAGARME_SPLIT_ENABLED');
    }

    public function platformRecipientId(): string
    {
        return trim((string) ($_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? ''));
    }

    public function validPlatformRecipientId(): bool
    {
        return PagarmeRecipientId::isValid($this->platformRecipientId());
    }

    public function pixExpiresIn(): int
    {
        return max(300, min(86_400, (int) ($_ENV['PAGARME_PIX_EXPIRES_IN'] ?? 3600)));
    }

    private function boolean(string $name): bool
    {
        return filter_var($_ENV[$name] ?? false, FILTER_VALIDATE_BOOL);
    }
}
