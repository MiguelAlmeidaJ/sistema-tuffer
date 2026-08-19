<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Core\Database;
use App\Services\Finance\MarketplaceFinancialLedgerService;
use PDO;
use Throwable;

final class ExpiredOrderService
{
    public function __construct(private readonly ?PDO $database = null)
    {
    }

    /** @return array{expired:int,failed:int} */
    public function expire(int $limit = 100): array
    {
        $pdo = $this->database ?? Database::connection();
        $limit = max(1, min(500, $limit));
        $ids = $pdo->query("SELECT p.id FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.status IN ('pending','waiting_payment','processing') AND p.expires_at<=NOW() AND o.status='pending_payment' ORDER BY p.id LIMIT {$limit}")->fetchAll(PDO::FETCH_COLUMN);
        $result = ['expired' => 0, 'failed' => 0];
        foreach ($ids as $paymentId) {
            try {
                $pdo->beginTransaction();
                $statement = $pdo->prepare("SELECT p.id,p.order_id,o.user_id,o.code FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.id=? AND p.status IN ('pending','waiting_payment','processing') AND p.expires_at<=NOW() AND o.status='pending_payment' FOR UPDATE");
                $statement->execute([(int) $paymentId]);
                $payment = $statement->fetch();
                if (!$payment) { $pdo->rollBack(); continue; }
                (new OrderInventoryService())->release($pdo, (int) $payment['order_id']);
                (new OrderCouponService())->release($pdo, (int) $payment['order_id']);
                $pdo->prepare("UPDATE payments SET status='expired' WHERE id=? AND status IN ('pending','waiting_payment','processing')")->execute([$payment['id']]);
                $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND status='pending_payment'")->execute([$payment['order_id']]);
                $pdo->prepare("UPDATE seller_orders SET status='cancelled' WHERE order_id=? AND status='pending_payment'")->execute([$payment['order_id']]);
                (new MarketplaceFinancialLedgerService($pdo))->voidPending((int) $payment['id']);
                $pdo->prepare("INSERT INTO order_status_history(order_id,status,notes) VALUES(?,'cancelled','Prazo de pagamento expirado; estoque e cupom liberados.')")->execute([$payment['order_id']]);
                $pdo->prepare("INSERT INTO user_notifications(user_id,type,title,message,action_url) VALUES(?,'payment_expired','Pagamento expirado',?,?)")
                    ->execute([$payment['user_id'], 'O prazo de pagamento do pedido ' . $payment['code'] . ' expirou.', '/minha-conta/pedidos/' . $payment['code']]);
                $pdo->commit();
                $result['expired']++;
            } catch (Throwable) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $result['failed']++;
            }
        }
        return $result;
    }
}
