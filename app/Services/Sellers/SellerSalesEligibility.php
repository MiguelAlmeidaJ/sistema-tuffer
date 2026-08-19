<?php

declare(strict_types=1);

namespace App\Services\Sellers;

use App\Core\Database;
use App\Services\Payments\PagarmeClient;
use App\Services\Payments\Pagarme\PagarmeRecipientId;
use App\Services\Payments\Pagarme\PagarmeRecipientEligibility;
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
        $operational = ($seller['status'] ?? null) === 'active'
            && (!array_key_exists('has_active_store', $seller) || (int) $seller['has_active_store'] === 1);
        if (!$operational) {
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

    public function sellerCanSell(int $sellerId): bool
    {
        $statement = ($this->database ?? Database::connection())->prepare($this->eligibilitySql('s.id=?'));
        $statement->execute([$this->environment(), $this->platformRecipientId(), $this->environment(), $sellerId]);
        $seller = $statement->fetch();
        return is_array($seller) && $this->canSell($seller);
    }

    public function userCanSell(int $userId): bool
    {
        $statement = ($this->database ?? Database::connection())->prepare($this->eligibilitySql('s.user_id=?'));
        $statement->execute([$this->environment(), $this->platformRecipientId(), $this->environment(), $userId]);
        $seller = $statement->fetch();
        return is_array($seller) && $this->canSell($seller);
    }

    /** @param array<int,int> $sellerIds */
    public function assertAllCanSell(array $sellerIds): void
    {
        $sellerIds = array_values(array_unique(array_filter(array_map('intval', $sellerIds))));
        if ($sellerIds === []) {
            throw new \RuntimeException('Nenhum vendedor válido foi localizado.');
        }
        $placeholders = implode(',', array_fill(0, count($sellerIds), '?'));
        $statement = ($this->database ?? Database::connection())->prepare($this->eligibilitySql("s.id IN ({$placeholders})"));
        $statement->execute([$this->environment(), $this->platformRecipientId(), $this->environment(), ...$sellerIds]);
        $eligible = 0;
        foreach ($statement->fetchAll() as $seller) {
            if ($this->canSell($seller)) {
                $eligible++;
            }
        }
        if ($eligible !== count($sellerIds)) {
            throw new \RuntimeException('Uma das lojas não está habilitada para receber pagamentos.');
        }
    }

    private function eligibilitySql(string $where): string
    {
        return "SELECT s.id,s.status,s.is_official_store,s.payment_enabled,s.pagarme_recipient_id,
                       s.payment_onboarding_status,spa.recipient_status,spa.kyc_status,spa.enabled_for_sales,
                       EXISTS(SELECT 1 FROM stores st WHERE st.seller_id=s.id AND st.status='active') has_active_store,
                       EXISTS(
                           SELECT 1 FROM marketplace_payment_accounts mpa
                           WHERE mpa.provider='pagarme' AND mpa.environment=?
                             AND mpa.recipient_id=?
                             AND mpa.payment_enabled=1 AND mpa.recipient_status='active'
                             AND mpa.kyc_status IN ('approved','legacy_not_required')
                       ) platform_account_eligible
                FROM sellers s
                LEFT JOIN seller_payment_accounts spa
                  ON spa.seller_id=s.id AND spa.provider='pagarme' AND spa.environment=?
                WHERE {$where} LIMIT 100";
    }

    private function environment(): string
    {
        return (new PagarmeClient())->environment();
    }

    private function platformRecipientId(): string
    {
        return trim((string) ($_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? ''));
    }
}
