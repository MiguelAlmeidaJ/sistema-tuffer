<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\Pagarme\PagarmeWebhookIdempotency;
use App\Services\Payments\PagarmeWebhookException;
use PHPUnit\Framework\TestCase;

final class PagarmeWebhookIdempotencyTest extends TestCase
{
    public function testMarksProcessedDeliveryAsDuplicate(): void
    {
        $guard = new PagarmeWebhookIdempotency();
        $hash = hash('sha256', '{"id":"hook_1"}');

        $guard->assertPayload($hash, $hash);
        self::assertTrue($guard->alreadyHandled('processed'));
        self::assertTrue($guard->alreadyHandled('ignored'));
        self::assertFalse($guard->alreadyHandled('failed'));
    }

    public function testRejectsSameEventIdWithDifferentPayloadHash(): void
    {
        $this->expectException(PagarmeWebhookException::class);
        $this->expectExceptionMessage('Conflito de idempotência');
        (new PagarmeWebhookIdempotency())->assertPayload(
            hash('sha256', '{"status":"paid"}'),
            hash('sha256', '{"status":"failed"}')
        );
    }
}
