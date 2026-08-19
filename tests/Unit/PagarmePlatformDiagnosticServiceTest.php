<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\PagarmeApiClient;
use App\Services\Payments\Pagarme\PagarmePlatformDiagnosticService;
use PHPUnit\Framework\TestCase;

final class PagarmePlatformDiagnosticServiceTest extends TestCase
{
    private ?string $previousRecipient;

    protected function setUp(): void
    {
        $this->previousRecipient = $_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousRecipient === null) {
            unset($_ENV['PAGARME_PLATFORM_RECIPIENT_ID']);
        } else {
            $_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] = $this->previousRecipient;
        }
    }

    public function testReturnsOnlySafeRecipientStatusData(): void
    {
        $_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] = 're_platform123';
        $client = new class implements PagarmeApiClient {
            public int $getCalls = 0;
            public function configured(): bool { return true; }
            public function environment(): string { return 'test'; }
            public function get(string $endpoint): array
            {
                $this->getCalls++;
                return [
                    'id' => 're_platform123',
                    'status' => 'active',
                    'kyc_details' => ['status' => 'approved', 'document' => 'never-expose'],
                    'default_bank_account' => ['account_number' => 'never-expose'],
                ];
            }
            public function post(string $endpoint, array $payload = [], ?string $idempotencyKey = null): array
            {
                self::fail('O diagnóstico não pode executar POST.');
            }
            public function put(string $endpoint, array $payload, ?string $idempotencyKey = null): array
            {
                self::fail('O diagnóstico não pode executar PUT.');
            }
            public function delete(string $endpoint, array $payload = [], ?string $idempotencyKey = null): array
            {
                self::fail('O diagnóstico não pode executar DELETE.');
            }
        };

        $result = (new PagarmePlatformDiagnosticService($client))->inspect();

        self::assertTrue($result['ok']);
        self::assertTrue($result['environment_match']);
        self::assertSame('active', $result['recipient_status']);
        self::assertSame('approved', $result['kyc_status']);
        self::assertSame(1, $client->getCalls);
        self::assertStringNotContainsString('platform123', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('default_bank_account', $result);
    }

    public function testInvalidRecipientNeverCallsApi(): void
    {
        $_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] = '';
        $client = new class implements PagarmeApiClient {
            public function configured(): bool { return true; }
            public function environment(): string { return 'test'; }
            public function get(string $endpoint): array { self::fail('GET não deveria ser chamado.'); }
            public function post(string $endpoint, array $payload = [], ?string $idempotencyKey = null): array { self::fail(); }
            public function put(string $endpoint, array $payload, ?string $idempotencyKey = null): array { self::fail(); }
            public function delete(string $endpoint, array $payload = [], ?string $idempotencyKey = null): array { self::fail(); }
        };

        $result = (new PagarmePlatformDiagnosticService($client))->inspect();

        self::assertFalse($result['ok']);
        self::assertFalse($result['recipient_id_valid']);
    }
}
