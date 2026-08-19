<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use PDO;
use RuntimeException;

final class ProductCostService
{
    public function __construct(private readonly ?PDO $database = null)
    {
    }

    public function record(int $variantId, ?int $costAmountCents, ?int $createdBy = null): void
    {
        if ($costAmountCents === null) {
            return;
        }
        if ($costAmountCents < 0) {
            throw new RuntimeException('O custo do produto não pode ser negativo.');
        }
        $pdo = $this->database ?? Database::connection();
        $variant = $pdo->prepare('SELECT product_id FROM product_variants WHERE id=?');
        $variant->execute([$variantId]);
        $productId = (int) $variant->fetchColumn();
        if ($productId < 1) {
            throw new RuntimeException('Variação não encontrada para registrar o custo.');
        }
        $current = $pdo->prepare(
            'SELECT id,cost_amount_cents FROM product_cost_history
             WHERE product_variant_id=? AND effective_until IS NULL
             ORDER BY effective_from DESC,id DESC LIMIT 1 FOR UPDATE'
        );
        $current->execute([$variantId]);
        $row = $current->fetch();
        if (is_array($row) && (int) $row['cost_amount_cents'] === $costAmountCents) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        if (is_array($row)) {
            $pdo->prepare('UPDATE product_cost_history SET effective_until=? WHERE id=? AND effective_until IS NULL')
                ->execute([$now, $row['id']]);
        }
        $pdo->prepare(
            'INSERT INTO product_cost_history(
                product_id,product_variant_id,cost_amount_cents,effective_from,created_by
             ) VALUES(?,?,?,?,?)'
        )->execute([$productId, $variantId, $costAmountCents, $now, $createdBy]);
    }

    public function at(int $variantId, string $at): ?int
    {
        $statement = ($this->database ?? Database::connection())->prepare(
            'SELECT cost_amount_cents FROM product_cost_history
             WHERE product_variant_id=? AND effective_from<=?
               AND (effective_until IS NULL OR effective_until>?)
             ORDER BY effective_from DESC,id DESC LIMIT 1'
        );
        $statement->execute([$variantId, $at, $at]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }
}
