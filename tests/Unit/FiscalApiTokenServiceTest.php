<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Fiscal\FiscalApiTokenService;
use PHPUnit\Framework\TestCase;

final class FiscalApiTokenServiceTest extends TestCase
{
    public function testExpectedTokenFormatIsAccepted(): void
    {
        $token = FiscalApiTokenService::PREFIX . str_repeat('a', 64);
        self::assertTrue(FiscalApiTokenService::tokenLooksValid($token));
        self::assertSame(hash('sha256', $token), FiscalApiTokenService::hashToken($token));
    }

    public function testMalformedTokensAreRejected(): void
    {
        self::assertFalse(FiscalApiTokenService::tokenLooksValid('Bearer ' . FiscalApiTokenService::PREFIX . str_repeat('a', 64)));
        self::assertFalse(FiscalApiTokenService::tokenLooksValid(FiscalApiTokenService::PREFIX . str_repeat('z', 64)));
        self::assertFalse(FiscalApiTokenService::tokenLooksValid('wrong_' . str_repeat('a', 64)));
    }
}
