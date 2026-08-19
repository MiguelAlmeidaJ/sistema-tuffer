<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mail\PasswordResetMailService;
use PHPUnit\Framework\TestCase;

final class PasswordResetMailServiceSafetyTest extends TestCase
{
    public function testRejectsHeaderInjectionBeforeOpeningSmtpConnection(): void
    {
        $mailer = new PasswordResetMailService();

        self::assertFalse($mailer->sendMessage(
            "Cliente\r\nBcc: attacker@example.com",
            'cliente@example.com',
            'Atualização do pedido',
            'Mensagem segura.'
        ));
        self::assertSame('Destinatário ou assunto inválido.', $mailer->lastError());
    }
}
