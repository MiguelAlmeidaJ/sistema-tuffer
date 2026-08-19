<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Newsletter\NewsletterService;
use PHPUnit\Framework\TestCase;

final class NewsletterServiceSafetyTest extends TestCase
{
    public function testRejectsMalformedManagementTokensWithoutDatabaseLookup(): void
    {
        $service=new NewsletterService();
        self::assertNull($service->confirm('invalid-token'));
        self::assertFalse($service->unsubscribe('../invalid'));
    }

    public function testConsentIsSpecificAndVersioned(): void
    {
        self::assertNotSame('',NewsletterService::CONSENT_VERSION);
        self::assertStringContainsString('novidades, ofertas e conteúdos',NewsletterService::CONSENT_STATEMENT);
        self::assertStringContainsString('cancelar gratuitamente',NewsletterService::CONSENT_STATEMENT);
    }
}
