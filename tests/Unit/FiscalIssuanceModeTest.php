<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Fiscal\FiscalIssuanceMode;
use PHPUnit\Framework\TestCase;

final class FiscalIssuanceModeTest extends TestCase
{
    public function testSupportedModesIncludeConnector(): void
    {
        self::assertSame('manual', FiscalIssuanceMode::normalize('manual'));
        self::assertSame('external', FiscalIssuanceMode::normalize(' external '));
        self::assertSame('connector', FiscalIssuanceMode::normalize('CONNECTOR'));
        self::assertTrue(FiscalIssuanceMode::isExternal('EXTERNAL'));
        self::assertTrue(FiscalIssuanceMode::isConnector('connector'));
        self::assertFalse(FiscalIssuanceMode::isConnector('external'));
    }

    public function testLegacyPlatformAndUnknownModesFallBackToManual(): void
    {
        self::assertSame('manual', FiscalIssuanceMode::normalize('platform'));
        self::assertSame('manual', FiscalIssuanceMode::normalize('unknown'));
        self::assertSame('manual', FiscalIssuanceMode::normalize(null));
    }
}
