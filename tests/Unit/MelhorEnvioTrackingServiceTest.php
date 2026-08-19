<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Shipping\MelhorEnvioTrackingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MelhorEnvioTrackingServiceTest extends TestCase
{
    #[DataProvider('providerStatuses')]
    public function testMapsProviderLifecycleToShipmentStatus(string $provider, string $expected): void
    {
        $method = new ReflectionMethod(MelhorEnvioTrackingService::class, 'localStatus');

        self::assertSame($expected, $method->invoke(new MelhorEnvioTrackingService(), $provider));
    }

    #[DataProvider('trackingUrls')]
    public function testOnlyAcceptsOfficialHttpsTrackingUrls(string $url, bool $expected): void
    {
        $method = new ReflectionMethod(MelhorEnvioTrackingService::class, 'trustedTrackingUrl');

        self::assertSame($expected, $method->invoke(new MelhorEnvioTrackingService(), $url));
    }

    /** @return array<string,array{string,string}> */
    public static function providerStatuses(): array
    {
        return [
            'released' => ['released', 'purchased'],
            'generated' => ['generated', 'purchased'],
            'posted' => ['posted', 'posted'],
            'received' => ['received', 'in_transit'],
            'delivered' => ['delivered', 'delivered'],
            'paused' => ['paused', 'exception'],
            'cancelled' => ['cancelled', 'cancelled'],
        ];
    }

    /** @return array<string,array{string,bool}> */
    public static function trackingUrls(): array
    {
        return [
            'Melhor Rastreio' => ['https://www.melhorrastreio.com.br/rastreio/ABC', true],
            'Melhor Envio' => ['https://melhorenvio.com.br/rastreio/ABC', true],
            'subdomain' => ['https://sandbox.melhorenvio.com.br/rastreio/ABC', true],
            'insecure protocol' => ['http://melhorenvio.com.br/rastreio/ABC', false],
            'lookalike domain' => ['https://melhorenvio.com.br.attacker.test/ABC', false],
        ];
    }
}
