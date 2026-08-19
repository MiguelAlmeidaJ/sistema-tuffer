<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mail\QueuedMailService;
use App\Services\Payments\PagarmeWebhookException;
use App\Services\Payments\PagarmeWebhookProcessor;
use App\Services\Queue\JobQueue;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AsyncQueueSafetyTest extends TestCase
{
    public function testRejectsInvalidJobBeforeDatabaseAccess(): void
    {
        $this->expectException(RuntimeException::class);
        (new JobQueue())->dispatch('', []);
    }

    public function testQueuedMailRejectsHeaderInjectionBeforeDatabaseAccess(): void
    {
        $this->expectException(RuntimeException::class);
        (new QueuedMailService())->enqueue("Cliente\r\nBcc: attacker@example.com", 'cliente@example.com', 'Assunto', 'Mensagem');
    }

    public function testMalformedWebhookIsRejectedBeforeItCanBeQueued(): void
    {
        try {
            (new PagarmeWebhookProcessor())->receive('{invalid', 'sha256');
            self::fail('O payload inválido deveria ter sido recusado.');
        } catch (PagarmeWebhookException $exception) {
            self::assertSame(400, $exception->httpStatus());
        }
    }
}
