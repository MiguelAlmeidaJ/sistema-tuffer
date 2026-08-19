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
            && $this->allowedSellerIds() !== []
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

        $allowed = array_flip($this->allowedSellerIds());
        foreach (array_unique(array_map('intval', $sellerIds)) as $sellerId) {
            if ($sellerId < 1 || !isset($allowed[$sellerId])) {
                return false;
            }
        }

        return true;
    }

    public function ordersPixEnabled(): bool
    {
        return $this->boolean('PAGARME_ORDERS_PIX_ENABLED');
    }

    public function splitEnabled(): bool
    {
        return $this->boolean('PAGARME_SPLIT_ENABLED');
    }

    /** @return array<int,int> */
    public function allowedSellerIds(): array
    {
        $values = preg_split('/[\s,;]+/', trim((string) ($_ENV['PAGARME_SPLIT_ALLOWED_SELLERS'] ?? ''))) ?: [];
        $ids = [];
        foreach ($values as $value) {
            if (ctype_digit($value) && (int) $value > 0) {
                $ids[(int) $value] = (int) $value;
            }
        }
        ksort($ids);
        return array_values($ids);
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
