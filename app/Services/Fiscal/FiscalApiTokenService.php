<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Database;
use PDO;
use RuntimeException;

final class FiscalApiTokenService
{
    public const PREFIX = 'tf_fiscal_';

    private readonly PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function rotate(int $storeId, int $sellerId): string
    {
        if ($storeId < 1 || $sellerId < 1) throw new RuntimeException('Loja inválida para gerar credencial fiscal.');
        $token = self::PREFIX . bin2hex(random_bytes(32));
        $hash = self::hashToken($token);
        $prefix = mb_substr($token, 0, 20);
        $sql = "INSERT INTO store_fiscal_api_credentials(store_id,seller_id,token_hash,token_prefix,enabled,last_used_at,rotated_at,revoked_at)
                VALUES(?,?,?,?,1,NULL,NOW(),NULL)
                ON DUPLICATE KEY UPDATE seller_id=VALUES(seller_id),token_hash=VALUES(token_hash),token_prefix=VALUES(token_prefix),enabled=1,last_used_at=NULL,rotated_at=NOW(),revoked_at=NULL";
        $this->pdo->prepare($sql)->execute([$storeId, $sellerId, $hash, $prefix]);
        return $token;
    }

    public function revoke(int $storeId, int $sellerId): void
    {
        $this->pdo->prepare('UPDATE store_fiscal_api_credentials SET enabled=0,revoked_at=NOW() WHERE store_id=? AND seller_id=?')
            ->execute([$storeId, $sellerId]);
    }

    /** @return array<string,mixed>|null */
    public function metadata(int $storeId, int $sellerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id,store_id,seller_id,token_prefix,enabled,last_used_at,rotated_at,revoked_at,created_at FROM store_fiscal_api_credentials WHERE store_id=? AND seller_id=? LIMIT 1');
        $stmt->execute([$storeId, $sellerId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function authenticate(string $authorizationHeader): ?array
    {
        if (!preg_match('/^Bearer\s+([^\s]+)$/i', trim($authorizationHeader), $matches)) return null;
        $token = (string) $matches[1];
        if (!self::tokenLooksValid($token)) return null;
        $hash = self::hashToken($token);
        $stmt = $this->pdo->prepare(
            "SELECT c.id credential_id,c.store_id,c.seller_id,c.token_hash,c.token_prefix,c.last_used_at,
                    sfp.issuance_mode,sfp.enabled profile_enabled,sfp.environment,sfp.provider
             FROM store_fiscal_api_credentials c
             JOIN store_fiscal_profiles sfp ON sfp.store_id=c.store_id AND sfp.seller_id=c.seller_id
             WHERE c.token_hash=? AND c.enabled=1 AND c.revoked_at IS NULL
               AND sfp.enabled=1 AND sfp.issuance_mode='external'
             LIMIT 1"
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!is_array($row) || !hash_equals((string) $row['token_hash'], $hash)) return null;
        $this->pdo->prepare('UPDATE store_fiscal_api_credentials SET last_used_at=NOW() WHERE id=?')->execute([(int) $row['credential_id']]);
        unset($row['token_hash']);
        return $row;
    }

    public static function tokenLooksValid(string $token): bool
    {
        return preg_match('/^' . preg_quote(self::PREFIX, '/') . '[a-f0-9]{64}$/', $token) === 1;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
