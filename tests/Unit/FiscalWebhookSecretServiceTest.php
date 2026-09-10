<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Fiscal\FiscalWebhookSecretService;
use PHPUnit\Framework\TestCase;

final class FiscalWebhookSecretServiceTest extends TestCase
{
    public function testEncryptDecryptAndSignatureAreStable(): void
    {
        $service = new FiscalWebhookSecretService('test-app-key-with-at-least-sixteen-characters');
        $secret = $service->generate();
        $encrypted = $service->encrypt($secret);

        self::assertNotSame($secret, $encrypted);
        self::assertSame($secret, $service->decrypt($encrypted));
        self::assertSame(
            'sha256=' . hash_hmac('sha256', '1700000000.{"ok":true}', $secret),
            $service->signature($secret, 1700000000, '{"ok":true}')
        );
    }
}
