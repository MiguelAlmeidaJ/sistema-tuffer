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
        $sellerIds = $this->pdo()->query(
            "SELECT DISTINCT s.id
             FROM stores st
             JOIN sellers s ON s.id=st.seller_id
             WHERE st.is_official_store=1
             ORDER BY s.id"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (count($sellerIds) > 1) {
            throw new RuntimeException('As lojas oficiais estão vinculadas a mais de um vendedor.');
        }
        if ($sellerIds === []) {
            return null;
        }

        $statement = $this->pdo()->prepare(
            "SELECT s.*,st.id official_store_id,st.name official_store_name,
                    st.slug official_store_slug,st.status official_store_status
             FROM sellers s
             JOIN stores st ON st.seller_id=s.id
             WHERE s.id=? AND st.is_official_store=1
             ORDER BY
                (LOWER(st.slug)='tuffer-oficial') DESC,
                (LOWER(st.name)='tuffer oficial') DESC,
                (LOWER(st.slug) LIKE 'tuffer%') DESC,
                (LOWER(st.name) LIKE 'tuffer%') DESC,
                (st.status='active') DESC,
                st.id
             LIMIT 1"
        );
        $statement->execute([(int) $sellerIds[0]]);
        $seller = $statement->fetch();
        return is_array($seller) ? $seller : null;
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
