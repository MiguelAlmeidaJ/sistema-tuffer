<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Fiscal\FiscalIssuanceMode;
use PHPUnit\Framework\TestCase;

final class FiscalIssuanceModeTest extends TestCase
{
    public function testSupportedModesAreManualAndExternal(): void
    {
        self::assertSame('manual', FiscalIssuanceMode::normalize('manual'));
        self::assertSame('external', FiscalIssuanceMode::normalize(' external '));
        self::assertTrue(FiscalIssuanceMode::isExternal('EXTERNAL'));
        self::assertFalse(FiscalIssuanceMode::isExternal('manual'));
    }

    public function testLegacyPlatformAndUnknownModesFallBackToManual(): void
    {
        self::assertSame('manual', FiscalIssuanceMode::normalize('platform'));
        self::assertSame('manual', FiscalIssuanceMode::normalize('unknown'));
        self::assertSame('manual', FiscalIssuanceMode::normalize(null));
    }
}
