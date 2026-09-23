<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Database;
use App\Services\Queue\JobQueue;
use PDO;

final class ShipmentTrackingScheduler
{
    public function __construct(
        private readonly ?PDO $database = null,
        private readonly ?JobQueue $queue = null,
        private readonly ?MelhorEnvioTrackingService $tracking = null,
    ) {
    }

    public function enqueueDue(int $limit = 100, int $staleAfterSeconds = 300): int
    {
        $limit = max(1, min(250, $limit));
        $staleAfterSeconds = max(60, min(3600, $staleAfterSeconds));

        $pdo = $this->database ?? Database::connection();
        $tracking = $this->tracking ?? new MelhorEnvioTrackingService($pdo);
        if (!$tracking->configured()) {
            return 0;
        }

        $statement = $pdo->query(
            "SELECT sh.id
             FROM shipments sh
             WHERE sh.external_id IS NOT NULL
               AND sh.external_id<>''
               AND sh.status IN ('pending','purchased','posted','in_transit','exception')
               AND (sh.last_synced_at IS NULL OR sh.last_synced_at<DATE_SUB(NOW(),INTERVAL {$staleAfterSeconds} SECOND))
               AND NOT EXISTS (
                   SELECT 1
                   FROM async_jobs j
                   WHERE j.job_type='shipping.sync_tracking'
                     AND j.status IN ('pending','processing')
                     AND CAST(JSON_UNQUOTE(JSON_EXTRACT(j.payload,'$.shipment_id')) AS UNSIGNED)=sh.id
               )
             ORDER BY COALESCE(sh.last_synced_at,'1970-01-01 00:00:00'),sh.id
             LIMIT {$limit}"
        );
        $shipmentIds = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($shipmentIds === []) {
            return 0;
        }

        $queue = $this->queue ?? new JobQueue($pdo);
        $bucket = intdiv(time(), $staleAfterSeconds);
        foreach ($shipmentIds as $shipmentId) {
            $shipmentId = (int) $shipmentId;
            if ($shipmentId < 1) {
                continue;
            }
            $queue->dispatch(
                'shipping.sync_tracking',
                ['shipment_id' => $shipmentId],
                'shipping-tracking:' . $shipmentId . ':' . $bucket,
                'shipping',
                8,
                60
            );
        }

        return count($shipmentIds);
    }
}
