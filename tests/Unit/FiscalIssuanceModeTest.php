<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Fiscal\FiscalIssuanceMode;
use PHPUnit\Framework\TestCase;

final class FiscalIssuanceModeTest extends TestCase
{
    public function testKnownModesArePreserved(): void
    {
        self::assertSame('platform', FiscalIssuanceMode::normalize('PLATFORM'));
        self::assertSame('manual', FiscalIssuanceMode::normalize('manual'));
        self::assertSame('external', FiscalIssuanceMode::normalize(' external '));
    }

    public function testUnknownModeFallsBackToManual(): void
    {
        self::assertSame('manual', FiscalIssuanceMode::normalize('unknown'));
        self::assertFalse(FiscalIssuanceMode::usesPlatform('external'));
        self::assertTrue(FiscalIssuanceMode::usesPlatform('platform'));
    }
}
