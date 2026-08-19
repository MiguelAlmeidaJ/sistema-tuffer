<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Services\Settings\PlatformSettings;
use JsonException;
use RuntimeException;

final class MelhorEnvioWalletService
{
    public function configured(): bool
    {
        return $this->token() !== '' && PlatformSettings::enabled('melhor_envio_enabled');
    }

    public function balance(): float
    {
        $response = $this->request('GET', '/api/v2/me/balance');
        if (!array_key_exists('balance', $response) || !is_numeric($response['balance'])) {
            throw new RuntimeException('O Melhor Envio não retornou o saldo da carteira.');
        }
        return round(max(0, (float) $response['balance']), 2);
    }

    /** @return array{reference:?string,payment_url:?string,status:string,response:array<string,mixed>} */
    public function createTopUp(string $method, float $amount, string $companyName = '', string $cnpj = ''): array
    {
        if (!in_array($method, ['pix', 'boleto'], true)) {
            throw new RuntimeException('Selecione PIX ou boleto para a recarga.');
        }
        if ($amount < 10 || $amount > 50_000) {
            throw new RuntimeException('A recarga deve ficar entre R$ 10,00 e R$ 50.000,00.');
        }
        $payload = [
            'gateway' => 'yapay-transparente',
            'slug' => $method,
            'value' => number_format($amount, 2, '.', ''),
        ];
        $cnpj = preg_replace('/\D+/', '', $cnpj) ?? '';
        if ($method === 'boleto' && $companyName !== '' && strlen($cnpj) === 14) {
            $payload['company_name'] = mb_substr($companyName, 0, 190);
            $payload['cnpj'] = $cnpj;
        }

        $response = $this->request('POST', '/api/v2/me/balance', $payload);
        $paymentUrl = $this->findHttpsUrl($response);
        $reference = $this->findReference($response);
        $status = mb_substr(trim((string) ($response['status'] ?? 'created')), 0, 50) ?: 'created';
        return [
            'reference' => $reference,
            'payment_url' => $paymentUrl,
            'status' => $status,
            'response' => $response,
        ];
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function request(string $method, string $endpoint, ?array $payload = null): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('A integração com o Melhor Envio não está configurada.');
        }
        $curl = curl_init($this->baseUrl() . '/' . ltrim($endpoint, '/'));
        if ($curl === false) throw new RuntimeException('Não foi possível iniciar a comunicação com o Melhor Envio.');
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token(),
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: ' . (string) ($_ENV['MELHOR_ENVIO_USER_AGENT'] ?? 'Tuffer Marketplace (suporte@tuffer.com.br)'),
            ],
        ];
        if ($payload !== null) {
            try {
                $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException) {
                throw new RuntimeException('Não foi possível preparar a solicitação de recarga.');
            }
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($raw)) {
            throw new RuntimeException('Falha de conexão com o Melhor Envio' . ($error !== '' ? ': ' . $error : '.'));
        }
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? $this->errorMessage($decoded) : '';
            throw new RuntimeException($message !== ''
                ? 'O Melhor Envio recusou a recarga: ' . mb_substr($message, 0, 400)
                : "O Melhor Envio recusou a recarga (HTTP {$status}).");
        }
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $response */
    private function findHttpsUrl(array $response): ?string
    {
        foreach (['url', 'link', 'payment_url', 'redirect_url', 'qr_code_url', 'boleto_url'] as $key) {
            if (isset($response[$key]) && is_string($response[$key]) && $this->isHttpsUrl($response[$key])) {
                return $response[$key];
            }
        }
        foreach ($response as $value) {
            if (is_array($value)) {
                $found = $this->findHttpsUrl($value);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $response */
    private function findReference(array $response): ?string
    {
        foreach (['id', 'transaction_id', 'reference', 'token'] as $key) {
            if (isset($response[$key]) && (is_string($response[$key]) || is_int($response[$key]))) {
                return mb_substr(trim((string) $response[$key]), 0, 190) ?: null;
            }
        }
        foreach ($response as $value) {
            if (is_array($value)) {
                $found = $this->findReference($value);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        return ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']);
    }

    /** @param array<string,mixed> $body */
    private function errorMessage(array $body): string
    {
        $message = $body['message'] ?? $body['error'] ?? null;
        if (is_string($message)) return trim(strip_tags($message));
        $errors = $body['errors'] ?? null;
        if (!is_array($errors)) return '';
        $messages = [];
        array_walk_recursive($errors, static function (mixed $value) use (&$messages): void {
            if (is_string($value) && trim($value) !== '') $messages[] = trim(strip_tags($value));
        });
        return implode(' ', array_slice(array_unique($messages), 0, 5));
    }

    private function token(): string
    {
        return trim((string) ($_ENV['MELHOR_ENVIO_TOKEN'] ?? $_ENV['MELHOR_ENVIO_ACCESS_TOKEN'] ?? ''));
    }

    private function baseUrl(): string
    {
        $sandbox = filter_var($_ENV['MELHOR_ENVIO_SANDBOX'] ?? true, FILTER_VALIDATE_BOOL);
        $configured = rtrim(trim((string) ($_ENV['MELHOR_ENVIO_BASE_URL'] ?? '')), '/');
        $base = $configured !== '' ? $configured : ($sandbox ? 'https://sandbox.melhorenvio.com.br' : 'https://melhorenvio.com.br');
        $parts = parse_url($base);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, ['sandbox.melhorenvio.com.br', 'melhorenvio.com.br', 'www.melhorenvio.com.br'], true)) {
            throw new RuntimeException('A URL configurada do Melhor Envio não é válida.');
        }
        return str_ends_with($base, '/api/v2') ? substr($base, 0, -7) : $base;
    }
}
