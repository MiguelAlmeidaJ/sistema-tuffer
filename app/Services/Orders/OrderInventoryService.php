<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Services\Payments\PagarmeWebhookException;
use PDO;

final class OrderInventoryService
{
    public function consume(PDO $pdo, int $orderId): void
    {
        $allocations = $this->remaining($pdo, $orderId);
        if ($allocations === []) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE reference_type='order' AND reference_id=? AND type='out'");
            $check->execute([$orderId]);
            if ((int) $check->fetchColumn() === 0) throw new PagarmeWebhookException('Reserva de estoque do pedido não encontrada.', 409);
            return;
        }
        foreach ($allocations as $allocation) {
            $quantity = (int) $allocation['remaining'];
            $stock = $pdo->prepare('SELECT quantity,reserved_quantity FROM stocks WHERE id=? FOR UPDATE');
            $stock->execute([$allocation['stock_id']]);
            $current = $stock->fetch();
            if (!is_array($current) || (int) $current['reserved_quantity'] < $quantity || (int) $current['quantity'] < $quantity) throw new PagarmeWebhookException('A reserva de estoque do pedido está inconsistente.', 409);
            $pdo->prepare('UPDATE stocks SET quantity=quantity-?,reserved_quantity=reserved_quantity-? WHERE id=?')->execute([$quantity, $quantity, $allocation['stock_id']]);
            $pdo->prepare("INSERT INTO stock_movements(stock_id,type,quantity,reference_type,reference_id,notes) VALUES(?,'out',?,'order',?,'Baixa após confirmação do pagamento')")->execute([$allocation['stock_id'], $quantity, $orderId]);
        }
    }

    public function release(PDO $pdo, int $orderId): void
    {
        foreach ($this->remaining($pdo, $orderId) as $allocation) {
            $quantity = (int) $allocation['remaining'];
            $stock = $pdo->prepare('SELECT reserved_quantity FROM stocks WHERE id=? FOR UPDATE');
            $stock->execute([$allocation['stock_id']]);
            if ((int) $stock->fetchColumn() < $quantity) throw new PagarmeWebhookException('A reserva de estoque do pedido está inconsistente.', 409);
            $pdo->prepare('UPDATE stocks SET reserved_quantity=reserved_quantity-? WHERE id=?')->execute([$quantity, $allocation['stock_id']]);
            $pdo->prepare("INSERT INTO stock_movements(stock_id,type,quantity,reference_type,reference_id,notes) VALUES(?,'release',?,'order',?,'Liberação após cancelamento do checkout')")->execute([$allocation['stock_id'], $quantity, $orderId]);
        }
    }

    /** @return array<int,array{stock_id:int,remaining:int}> */
    private function remaining(PDO $pdo, int $orderId): array
    {
        $statement = $pdo->prepare("SELECT stock_id,type,quantity FROM stock_movements WHERE reference_type='order' AND reference_id=? ORDER BY stock_id,id FOR UPDATE");
        $statement->execute([$orderId]);
        $remaining = [];
        foreach ($statement->fetchAll() as $row) {
            $stockId = (int) $row['stock_id'];
            $remaining[$stockId] ??= 0;
            $remaining[$stockId] += $row['type'] === 'reserve' ? (int) $row['quantity'] : (in_array($row['type'], ['out', 'release'], true) ? -(int) $row['quantity'] : 0);
        }
        $result = [];
        foreach ($remaining as $stockId => $quantity) if ($quantity > 0) $result[] = ['stock_id' => $stockId, 'remaining' => $quantity];
        return $result;
    }
}
