<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\Pagarme\PagarmeEventFreshnessGuard;
use PHPUnit\Framework\TestCase;

final class PagarmeEventFreshnessGuardTest extends TestCase
{
    public function testRejectsWebhookOlderThanLastEventForSameCharge(): void
    {
        self::assertTrue((new PagarmeEventFreshnessGuard())->isStale(
            '2026-07-27 11:59:59',
            '2026-07-27 12:00:00'
        ));
    }

    public function testAcceptsDuplicateTimestampForIdempotencyLayerToHandle(): void
    {
        self::assertFalse((new PagarmeEventFreshnessGuard())->isStale(
            '2026-07-27 12:00:00',
            '2026-07-27 12:00:00'
        ));
    }
}
