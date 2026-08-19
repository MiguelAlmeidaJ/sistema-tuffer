<?php

declare(strict_types=1);

namespace App\Services\Payments;

use JsonException;
use App\Services\Settings\PlatformSettings;

final class PagarmeClient implements PaymentGateway, PagarmeApiClient
{
    private string $secretKey;
    private string $baseUrl;

    public function __construct(?string $secretKey = null, ?string $baseUrl = null)
    {
        $this->secretKey = trim($secretKey ?? (string) ($_ENV['PAGARME_SECRET_KEY'] ?? ''));
        $configuredUrl = trim($baseUrl ?? (string) ($_ENV['PAGARME_BASE_URL'] ?? ''));
        $this->baseUrl = rtrim($configuredUrl !== '' ? $configuredUrl : 'https://api.pagar.me/core/v5', '/');
        if (str_starts_with($this->secretKey, 'sk_test_') && $this->baseUrl === 'https://api.pagar.me/core/v5') {
            $this->baseUrl = 'https://sdx-api.pagar.me/core/v5';
        }
    }

    public function configured(): bool
    {
        return $this->secretKey !== '' && PlatformSettings::enabled('pagarme_enabled');
    }

    public function environment(): string
    {
        return str_starts_with($this->secretKey, 'sk_test_') || str_contains($this->baseUrl, 'sdx-api.pagar.me')
            ? 'test'
            : 'production';
    }

    /** @return array<string,mixed> */
    public function get(string $endpoint): array
    {
        $this->assertOperational();
        return $this->request('GET', $this->endpoint($endpoint));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function post(string $endpoint, array $payload = [], ?string $idempotencyKey = null): array
    {
        $this->assertOperational();
        return $this->request('POST', $this->endpoint($endpoint), $payload, $idempotencyKey);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function put(string $endpoint, array $payload, ?string $idempotencyKey = null): array
    {
        $this->assertOperational();
        return $this->request('PUT', $this->endpoint($endpoint), $payload, $idempotencyKey);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function delete(string $endpoint, array $payload = [], ?string $idempotencyKey = null): array
    {
        $this->assertOperational();
        return $this->request('DELETE', $this->endpoint($endpoint), $payload, $idempotencyKey);
    }

    public function createPaymentLink(array $payload, string $idempotencyKey): array
    {
        $this->assertOperational();

        $response = $this->request('POST', '/paymentlinks', $payload, $idempotencyKey);
        $id = (string) ($response['id'] ?? '');
        $url = (string) ($response['url'] ?? '');
        if (!str_starts_with($id, 'pl_') || !$this->isTrustedPaymentUrl($url)) {
            throw new PagarmeException('A Pagar.me respondeu sem um link de pagamento válido.');
        }

        return $response;
    }

    public function cancelPaymentLink(string $paymentLinkId): void
    {
        if (!str_starts_with($paymentLinkId, 'pl_') || !$this->configured()) {
            return;
        }

        try {
            $this->request('PATCH', '/paymentlinks/' . rawurlencode($paymentLinkId) . '/cancel');
        } catch (PagarmeException $exception) {
            error_log('Não foi possível cancelar o link Pagar.me de uma transação revertida: ' . $exception->getMessage());
        }
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function request(string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null): array
    {
        $this->assertBaseUrl();
        $curl = curl_init($this->baseUrl . $path);
        if ($curl === false) {
            throw new PagarmeException('Não foi possível iniciar a comunicação com a Pagar.me.');
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: Tuffer-Marketplace/1.0',
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($payload !== null) {
            try {
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new PagarmeException('Não foi possível preparar os dados do pagamento.', 0, $exception);
            }
        }
        curl_setopt_array($curl, $options);

        $raw = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($raw)) {
            throw new PagarmeException('Não foi possível conectar à Pagar.me. Tente novamente em instantes.' . ($curlError !== '' ? ' (' . $curlError . ')' : ''));
        }

        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            error_log("Pagar.me respondeu HTTP {$status} ao endpoint {$path}.");
            throw new PagarmeException($this->safeErrorMessage(is_array($decoded) ? $decoded : [], $status));
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function assertOperational(): void
    {
        $localEnvironment = strtolower((string) ($_ENV['APP_ENV'] ?? 'production')) === 'local';
        $productionKey = str_starts_with($this->secretKey, 'sk_') && !str_starts_with($this->secretKey, 'sk_test_');
        $liveOverride = filter_var($_ENV['PAGARME_ALLOW_LIVE_IN_LOCAL'] ?? false, FILTER_VALIDATE_BOOL);
        if ($localEnvironment && $productionKey && !$liveOverride) {
            throw new PagarmeException('A chave Pagar.me parece ser de produção, mas o sistema está em ambiente local. Use uma chave sk_test_ para testar sem gerar transações reais.');
        }
        if (!$this->configured()) {
            throw new PagarmeException('A integração com a Pagar.me ainda não está configurada.');
        }
    }

    private function endpoint(string $endpoint): string
    {
        $endpoint = '/' . ltrim(trim($endpoint), '/');
        if (str_contains($endpoint, '..') || preg_match('#[^A-Za-z0-9/_?&=.%~-]#', $endpoint)) {
            throw new PagarmeException('Endpoint Pagar.me inválido.');
        }
        return $endpoint;
    }

    private function assertBaseUrl(): void
    {
        $parts = parse_url($this->baseUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, ['api.pagar.me', 'sdx-api.pagar.me'], true)) {
            throw new PagarmeException('A URL configurada para a Pagar.me não é válida.');
        }
    }

    private function isTrustedPaymentUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return ($parts['scheme'] ?? '') === 'https' && ($host === 'pagar.me' || str_ends_with($host, '.pagar.me'));
    }

    /** @param array<string,mixed> $body */
    private function safeErrorMessage(array $body, int $status): string
    {
        $message = $body['message'] ?? null;
        if (!is_string($message) || trim($message) === '') {
            $errors = $body['errors'] ?? [];
            if (is_array($errors)) {
                $first = reset($errors);
                if (is_array($first)) {
                    $candidate = $first['message'] ?? reset($first);
                    $message = is_string($candidate) ? $candidate : null;
                } elseif (is_string($first)) {
                    $message = $first;
                }
            }
        }
        $message = is_string($message) ? trim(strip_tags($message)) : '';
        if ($message !== '') {
            return 'A Pagar.me recusou a solicitação: ' . mb_substr($message, 0, 240);
        }
        return "A Pagar.me recusou a solicitação (HTTP {$status}).";
    }
}
