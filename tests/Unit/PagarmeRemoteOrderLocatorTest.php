<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\PagarmeApiClient;
use App\Services\Payments\PagarmeException;
use App\Services\Payments\Pagarme\PagarmeRemoteOrderLocator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PagarmeRemoteOrderLocatorTest extends TestCase
{
    public function testReusesRemoteOrderWithSameCodeAndAmount(): void
    {
        $client = new RemoteOrderFakeClient([
            ['id' => 'or_other', 'code' => 'OTHER', 'amount' => 100],
            ['id' => 'or_recovered', 'code' => 'TF-100', 'amount' => 10_001],
        ]);

        $order = (new PagarmeRemoteOrderLocator($client))->byCode('TF-100', 10_001);

        self::assertSame('or_recovered', $order['id']);
        self::assertSame('/orders/or_recovered', $client->lastEndpoint);
        self::assertSame([
            '/orders?code=TF-100&page=1&size=30',
            '/orders/or_recovered',
        ], $client->endpoints);
        self::assertSame(0, $client->postCalls);
    }

    public function testRejectsRemoteOrderWithDivergentAmount(): void
    {
        $client = new RemoteOrderFakeClient([
            ['id' => 'or_conflict', 'code' => 'TF-100', 'amount' => 10_000],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('valor divergente');
        (new PagarmeRemoteOrderLocator($client))->byCode('TF-100', 10_001);
    }

    public function testNetworkFailureIsPropagatedForSafeRetry(): void
    {
        $client = new RemoteOrderFakeClient([]);
        $client->failGet = true;

        $this->expectException(PagarmeException::class);
        (new PagarmeRemoteOrderLocator($client))->byCode('TF-100', 10_001);
    }
}

final class RemoteOrderFakeClient implements PagarmeApiClient
{
    public string $lastEndpoint = '';
    /** @var array<int,string> */
    public array $endpoints = [];
    public int $postCalls = 0;
    public bool $failGet = false;

    /** @param array<int,array<string,mixed>> $orders */
    public function __construct(private readonly array $orders)
    {
    }

    public function configured(): bool { return true; }
    public function environment(): string { return 'test'; }
    public function get(string $endpoint): array
    {
        $this->lastEndpoint = $endpoint;
        $this->endpoints[] = $endpoint;
        if ($this->failGet) {
            throw new PagarmeException('Falha de rede simulada.');
        }
        if (str_starts_with($endpoint, '/orders/or_')) {
            $id = substr($endpoint, strlen('/orders/'));
            foreach ($this->orders as $order) {
                if (($order['id'] ?? null) === $id) {
                    return $order;
                }
            }
            return [];
        }
        return ['data' => $this->orders];
    }
    public function post(string $endpoint, array $payload = [], ?string $idempotencyKey = null): array
    {
        $this->postCalls++;
        return [];
    }
    public function put(string $endpoint, array $payload, ?string $idempotencyKey = null): array { return []; }
    public function delete(string $endpoint, array $payload = [], ?string $idempotencyKey = null): array { return []; }
}
