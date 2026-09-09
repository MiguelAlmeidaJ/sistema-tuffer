<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Database;
use PDO;

final class FiscalWebhookSchedulerService
{
    public function __construct(private readonly ?PDO $database = null) {}

    public function schedulePaidOrder(int $orderId): void
    {
        if ($orderId < 1) return;
        $pdo = $this->database ?? Database::connection();
        $stmt = $pdo->prepare("SELECT id FROM seller_orders WHERE order_id=? AND status IN ('paid','processing','shipped','delivered') ORDER BY id");
        $stmt->execute([$orderId]);
        $webhooks = new FiscalOutboundWebhookService($pdo);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sellerOrderId) {
            $webhooks->scheduleSellerOrderReady((int)$sellerOrderId);
        }
    }
}
