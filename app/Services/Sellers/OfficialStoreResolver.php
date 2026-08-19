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
            'SELECT * FROM sellers WHERE is_official_store=1 ORDER BY status=\'active\' DESC,id'
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
        if (!is_array($seller) || ($seller['status'] ?? null) !== 'active') {
            throw new RuntimeException('A loja oficial não foi configurada ou está inativa.');
        }
        $statement = $this->pdo()->prepare("SELECT COUNT(*) FROM stores WHERE seller_id=? AND status='active'");
        $statement->execute([(int) $seller['id']]);
        if ((int) $statement->fetchColumn() < 1) {
            throw new RuntimeException('A loja oficial não possui uma loja operacional ativa.');
        }
        return $seller;
    }

    public function isOfficial(int $sellerId): bool
    {
        $statement = $this->pdo()->prepare('SELECT is_official_store FROM sellers WHERE id=?');
        $statement->execute([$sellerId]);
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
