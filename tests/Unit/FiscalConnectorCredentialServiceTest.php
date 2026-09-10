<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Fiscal\FiscalConnectorCredentialService;
use PHPUnit\Framework\TestCase;

final class FiscalConnectorCredentialServiceTest extends TestCase
{
    public function testEncryptsAndDecryptsConnectorCredentialsWithoutPlaintext(): void
    {
        $service=new FiscalConnectorCredentialService('unit-test-app-key-that-is-long-enough');
        $secret='tiny-secret-token-123456789';
        $encrypted=$service->encrypt(['token'=>$secret,'account'=>'store-1']);

        self::assertStringStartsWith('v1.',$encrypted);
        self::assertStringNotContainsString($secret,$encrypted);
        self::assertSame(['token'=>$secret,'account'=>'store-1'],$service->decrypt($encrypted));
        self::assertSame('tiny-s…6789',$service->prefix($secret));
    }

    public function testRequiresStableAppKey(): void
    {
        $this->expectException(\RuntimeException::class);
        (new FiscalConnectorCredentialService('short'))->encrypt(['token'=>'abc']);
    }
}
