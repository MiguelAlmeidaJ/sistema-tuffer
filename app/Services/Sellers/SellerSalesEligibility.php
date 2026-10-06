<?php

declare(strict_types=1);

namespace App\Services\Sellers;

use App\Core\Database;
use App\Services\Payments\PagarmeClient;
use App\Services\Payments\Pagarme\PagarmeRecipientEligibility;
use App\Services\Payments\Pagarme\PagarmeRecipientId;
use App\Services\Settings\PlatformSettings;
use PDO;

final class SellerSalesEligibility
{
    public function __construct(private readonly ?PDO $database = null)
    {
    }

    /** @param array<string,mixed> $seller */
    public function canSell(array $seller): bool
    {
        if (($seller['seller_status'] ?? $seller['status'] ?? null) !== 'active'
            || ($seller['store_status'] ?? 'active') !== 'active') {
            return false;
        }

        if ((int) ($seller['is_official_store'] ?? 0) === 1) {
            return (int) ($seller['platform_account_eligible'] ?? 0) === 1
                && PlatformSettings::enabled('pagarme_enabled')
                && (new PagarmeClient())->configured();
        }

        $legacy = (int) ($seller['payment_enabled'] ?? 0) === 1
            && PagarmeRecipientId::isValid((string) ($seller['pagarme_recipient_id'] ?? ''))
            && ($seller['payment_onboarding_status'] ?? null) === 'active';

        $provider = !array_key_exists('recipient_status', $seller)
            || (PagarmeRecipientEligibility::isEligible(
                    (string) ($seller['recipient_status'] ?? ''),
                    isset($seller['kyc_status']) ? (string) $seller['kyc_status'] : null
                )
                && (int) ($seller['enabled_for_sales'] ?? 0) === 1);

        return $legacy && $provider;
    }

    public function storeCanSell(int $storeId): bool
    {
        $statement = $this->pdo()->prepare($this->eligibilitySql('st.id=?'));
        $statement->execute([$this->environment(), $this->platformRecipientId(), $this->environment(), $storeId]);
        $row = $statement->fetch();
        return is_array($row) && $this->canSell($row);
    }

    public function sellerCanSell(int $sellerId): bool
    {
        $statement = $this->pdo()->prepare($this->eligibilitySql('s.id=?'));
        $statement->execute([$this->environment(), $this->platformRecipientId(), $this->environment(), $sellerId]);
        foreach ($statement->fetchAll() as $row) {
            if ($this->canSell($row)) {
                return true;
            }
        }
        return false;
    }

    public function userCanSell(int $userId): bool
    {
        $statement = $this->pdo()->prepare($this->eligibilitySql('s.user_id=?'));
        $statement->execute([$this->environment(), $this->platformRecipientId(), $this->environment(), $userId]);
        foreach ($statement->fetchAll() as $row) {
            if ($this->canSell($row)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,int> $storeIds */
    public function assertAllStoresCanSell(array $storeIds): void
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
        if ($storeIds === []) {
            throw new \RuntimeException('Nenhuma loja válida foi localizada.');
        }

        $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
        $statement = $this->pdo()->prepare($this->eligibilitySql("st.id IN ({$placeholders})"));
        $statement->execute([$this->environment(), $this->platformRecipientId(), $this->environment(), ...$storeIds]);

        $eligible = [];
        foreach ($statement->fetchAll() as $row) {
            if ($this->canSell($row)) {
                $eligible[(int) $row['store_id']] = true;
            }
        }

        foreach ($storeIds as $storeId) {
            if (!isset($eligible[$storeId])) {
                throw new \RuntimeException('Uma das lojas não está habilitada para receber pagamentos.');
            }
        }
    }

    /** @param array<int,int> $sellerIds */
    public function assertAllCanSell(array $sellerIds): void
    {
        $sellerIds = array_values(array_unique(array_filter(array_map('intval', $sellerIds))));
        if ($sellerIds === []) {
            throw new \RuntimeException('Nenhum vendedor válido foi localizado.');
        }
        foreach ($sellerIds as $sellerId) {
            if (!$this->sellerCanSell($sellerId)) {
                throw new \RuntimeException('Uma das lojas não está habilitada para receber pagamentos.');
            }
        }
    }

    private function eligibilitySql(string $where): string
    {
        return "SELECT
                    s.id seller_id,
                    s.status seller_status,
                    s.payment_enabled,
                    s.pagarme_recipient_id,
                    s.payment_onboarding_status,
                    st.id store_id,
                    st.status store_status,
                    st.is_official_store,
                    spa.recipient_status,
                    spa.kyc_status,
                    spa.enabled_for_sales,
                    EXISTS(
                        SELECT 1 FROM marketplace_payment_accounts mpa
                        WHERE mpa.provider='pagarme'
                          AND mpa.environment=?
                          AND mpa.recipient_id=?
                          AND mpa.payment_enabled=1
                          AND mpa.recipient_status='active'
                          AND mpa.kyc_status IN ('approved','legacy_not_required')
                    ) platform_account_eligible
                FROM stores st
                JOIN sellers s ON s.id=st.seller_id
                LEFT JOIN seller_payment_accounts spa
                  ON spa.seller_id=s.id
                 AND spa.provider='pagarme'
                 AND spa.environment=?
                WHERE {$where}
                ORDER BY st.id
                LIMIT 100";
    }

    private function environment(): string
    {
        return (new PagarmeClient())->environment();
    }

    private function platformRecipientId(): string
    {
        return trim((string) ($_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? ''));
    }

    private function pdo(): PDO
    {
        return $this->database ?? Database::connection();
    }
}
