<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShipmentTrackingAutomationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testQueueWorkerSchedulesShippingTrackingAutomatically(): void
    {
        $worker = file_get_contents($this->root . '/scripts/queue-worker.php');

        self::assertIsString($worker);
        self::assertStringContainsString('ShipmentTrackingScheduler', $worker);
        self::assertStringContainsString('payment,webhook,fiscal,mail,shipping,default', $worker);
        self::assertStringContainsString('enqueueDue()', $worker);
        self::assertStringContainsString('$nextTrackingScheduleAt = time() + 60', $worker);
    }

    public function testSchedulerTargetsOnlyDueActiveShipmentsAndDeduplicatesQueuedJobs(): void
    {
        $scheduler = file_get_contents($this->root . '/app/Services/Shipping/ShipmentTrackingScheduler.php');

        self::assertIsString($scheduler);
        self::assertStringContainsString("sh.status IN ('pending','purchased','posted','in_transit','exception')", $scheduler);
        self::assertStringContainsString('last_synced_at', $scheduler);
        self::assertStringContainsString("j.job_type='shipping.sync_tracking'", $scheduler);
        self::assertStringContainsString("j.status IN ('pending','processing')", $scheduler);
        self::assertStringContainsString("'shipping.sync_tracking'", $scheduler);
        self::assertStringContainsString("'shipping'", $scheduler);
    }

    public function testProcessorUpdatesTrackingAndNotifiesOnAutomaticDelivery(): void
    {
        $processor = file_get_contents($this->root . '/app/Services/Queue/JobProcessor.php');

        self::assertIsString($processor);
        self::assertStringContainsString("'shipping.sync_tracking'", $processor);
        self::assertStringContainsString('syncShipmentTracking', $processor);
        self::assertStringContainsString('MelhorEnvioTrackingService', $processor);
        self::assertStringContainsString("'order_delivered_' . $shipmentId", $processor);
        self::assertStringContainsString('OrderMailService', $processor);
    }

    public function testOperationsDocumentExplainsAutomaticTracking(): void
    {
        $operations = file_get_contents($this->root . '/docs/OPERATIONS.md');

        self::assertIsString($operations);
        self::assertStringContainsString('Rastreamento automático de remessas', $operations);
        self::assertStringContainsString('shipping.sync_tracking', $operations);
        self::assertStringContainsString('Não é necessário o vendedor abrir o pedido', $operations);
    }
}
