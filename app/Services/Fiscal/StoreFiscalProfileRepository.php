<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Database;
use PDO;

final class StoreFiscalProfileRepository
{
    private readonly PDO $pdo;
    private readonly FiscalConfiguration $configuration;

    public function __construct(?PDO $pdo = null, ?FiscalConfiguration $configuration = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->configuration = $configuration ?? new FiscalConfiguration();
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
        $legacy['issuance_mode'] = FiscalIssuanceMode::PLATFORM;
        $legacy['provider'] = $this->configuration->provider();
        $legacy['environment'] = $this->configuration->environment();
        $legacy['auto_issue'] = $this->configuration->autoIssue() ? 1 : 0;
        return $this->normalize($legacy, 'seller_fallback');
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private function normalize(array $profile, string $source): array
    {
        $profile['profile_source'] = $source;
        $profile['issuance_mode'] = FiscalIssuanceMode::normalize((string) ($profile['issuance_mode'] ?? FiscalIssuanceMode::MANUAL));
        $provider = mb_strtolower(trim((string) ($profile['provider'] ?? 'disabled')));
        $profile['provider'] = $provider === '' ? 'disabled' : $provider;
        $profile['environment'] = ((string) ($profile['environment'] ?? 'homologation')) === 'production' ? 'production' : 'homologation';
        $profile['auto_issue'] = filter_var($profile['auto_issue'] ?? false, FILTER_VALIDATE_BOOL);
        return $profile;
    }
}
