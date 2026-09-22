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

    public function testNormalizesDetailedProviderMovementHistoryWhenAvailable(): void
    {
        $method = new ReflectionMethod(MelhorEnvioTrackingService::class, 'movementEvents');
        $events = $method->invoke(new MelhorEnvioTrackingService(), [
            'events' => [
                [
                    'status' => 'posted',
                    'description' => 'Objeto postado na agência.',
                    'city' => 'Marília',
                    'state' => 'SP',
                    'occurred_at' => '2026-09-16T20:36:00-03:00',
                ],
                [
                    'event' => 'received',
                    'message' => 'Objeto recebido na unidade.',
                    'location' => ['city' => 'Campinas', 'state' => 'SP'],
                    'created_at' => '2026-09-16T23:12:00-03:00',
                ],
            ],
        ]);

        self::assertCount(2, $events);
        self::assertSame('posted', $events[0]['code']);
        self::assertSame('Marília', $events[0]['city']);
        self::assertSame('received', $events[1]['code']);
        self::assertSame('Campinas', $events[1]['city']);
        self::assertSame('Objeto recebido na unidade.', $events[1]['description']);
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
