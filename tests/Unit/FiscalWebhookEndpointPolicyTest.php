<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Fiscal\FiscalWebhookEndpointPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FiscalWebhookEndpointPolicyTest extends TestCase
{
    #[DataProvider('unsafeUrls')]
    public function testUnsafeTargetsAreRejected(string $url): void
    {
        $this->expectException(RuntimeException::class);
        (new FiscalWebhookEndpointPolicy())->validate($url);
    }

    public static function unsafeUrls(): array
    {
        return [
            ['http://example.com/webhook'],
            ['https://localhost/webhook'],
            ['https://127.0.0.1/webhook'],
            ['https://10.0.0.10/webhook'],
            ['https://example.com:8443/webhook'],
        ];
    }
}
