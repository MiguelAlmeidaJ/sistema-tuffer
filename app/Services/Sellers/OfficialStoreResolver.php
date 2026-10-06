<?php

declare(strict_types=1);

namespace App\Services\Sellers;

use App\Core\Database;
use App\Services\Payments\Pagarme\PagarmeCheckoutConfiguration;
use App\Services\Payments\Pagarme\PagarmeRecipientId;
use PDO;
use RuntimeException;

final class OfficialStoreResolver
{
    public function __construct(
        private readonly ?PDO $database = null,
        private readonly ?PagarmeCheckoutConfiguration $configuration = null
    ) {
    }

    /** @return array<string,mixed>|null */
    public function find(): ?array
    {
        $rows = $this->pdo()->query(
            "SELECT s.*,st.id official_store_id,st.name official_store_name,st.slug official_store_slug,st.status official_store_status
             FROM stores st
             JOIN sellers s ON s.id=st.seller_id
             WHERE st.is_official_store=1
             ORDER BY st.status='active' DESC,st.id"
        )->fetchAll();
        if (count($rows) > 1) {
            throw new RuntimeException('Existe mais de um seller identificado como loja oficial.');
        }
        return is_array($rows[0] ?? null) ? $rows[0] : null;
    }

    /** @return array<string,mixed> */
    public function active(): array
    {
        $seller = $this->find();
        if (!is_array($seller)
            || ($seller['status'] ?? null) !== 'active'
            || ($seller['official_store_status'] ?? null) !== 'active') {
            throw new RuntimeException('A loja oficial não foi configurada ou está inativa.');
        }
        return $seller;
    }

    public function isOfficial(int $storeId): bool
    {
        $statement = $this->pdo()->prepare('SELECT is_official_store FROM stores WHERE id=?');
        $statement->execute([$storeId]);
        return (int) $statement->fetchColumn() === 1;
    }

    public function recipientId(): string
    {
        $recipientId = ($this->configuration ?? new PagarmeCheckoutConfiguration())->platformRecipientId();
        if (!PagarmeRecipientId::isValid($recipientId)) {
            throw new RuntimeException('O recebedor da plataforma não está configurado.');
        }
        return $recipientId;
    }

    private function pdo(): PDO
    {
        return $this->database ?? Database::connection();
    }
}
