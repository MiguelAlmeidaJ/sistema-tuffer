<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Database;
use PDO;

final class StoreFiscalProfileRepository
{
    private readonly PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /** @return array<string,mixed>|null */
    public function find(int $storeId, int $sellerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM store_fiscal_profiles WHERE store_id=? AND seller_id=? LIMIT 1');
        $stmt->execute([$storeId, $sellerId]);
        $profile = $stmt->fetch();
        if (is_array($profile)) {
            return $this->normalize($profile, 'store');
        }

        $stmt = $this->pdo->prepare('SELECT sfp.*,s.legal_name,s.trade_name,s.document,s.state_registration FROM seller_fiscal_profiles sfp JOIN sellers s ON s.id=sfp.seller_id WHERE sfp.seller_id=? LIMIT 1');
        $stmt->execute([$sellerId]);
        $legacy = $stmt->fetch();
        if (!is_array($legacy)) {
            return null;
        }

        $legacy['store_id'] = $storeId;
        $legacy['issuance_mode'] = FiscalIssuanceMode::MANUAL;
        $legacy['provider'] = 'manual';
        $legacy['environment'] = 'production';
        return $this->normalize($legacy, 'seller_fallback');
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private function normalize(array $profile, string $source): array
    {
        $profile['profile_source'] = $source;
        $profile['issuance_mode'] = FiscalIssuanceMode::normalize((string) ($profile['issuance_mode'] ?? FiscalIssuanceMode::MANUAL));
        $provider = mb_strtolower(trim((string) ($profile['provider'] ?? 'manual')));
        if ($profile['issuance_mode'] === FiscalIssuanceMode::MANUAL) {
            $provider = 'manual';
        } elseif ($provider === '' || $provider === 'disabled' || $provider === 'manual') {
            $provider = 'external';
        }
        $profile['provider'] = $provider;
        $profile['environment'] = ((string) ($profile['environment'] ?? 'production')) === 'homologation' ? 'homologation' : 'production';
        return $profile;
    }
}
