<?php
declare(strict_types=1);

namespace App\Services\Payments\Asaas;

use RuntimeException;

/**
 * Cliente HTTP Asaas: endpoint fixo, segredo somente no servidor e nenhuma
 * ativação implícita de produção.
 */
final class AsaasClient
{
    private string $key;
    private string $baseUrl;

    public function __construct(?string $key = null, ?string $environment = null)
    {
        $this->key = trim($key ?? (string) ($_ENV['ASAAS_API_KEY'] ?? ''));
        $environment = strtolower(trim($environment ?? (string) ($_ENV['ASAAS_ENV'] ?? 'sandbox')));
        if (!in_array($environment, ['sandbox', 'production'], true)) {
            throw new RuntimeException('Ambiente Asaas inválido.');
        }
        $this->baseUrl = $environment === 'production'
            ? 'https://api.asaas.com/v3'
            : 'https://api-sandbox.asaas.com/v3';
    }

    public function configured(): bool
    {
        return strlen($this->key) >= 20
            && strlen(trim((string) ($_ENV['ASAAS_WEBHOOK_TOKEN'] ?? ''))) >= 16;
    }

    public function checkoutEnabled(): bool
    {
        return $this->configured()
            && filter_var($_ENV['ASAAS_CHECKOUT_ENABLED'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /** @return array<string,mixed> */
    public function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /** @return array<string,mixed> */
    public function deletePayment(string $paymentId): array
    {
        if (!preg_match('/^pay_[A-Za-z0-9_-]+$/', $paymentId)) {
            throw new RuntimeException('Identificador de cobrança inválido.');
        }
        return $this->request('DELETE', '/payments/' . rawurlencode($paymentId), null);
    }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    private function request(string $method, string $path, ?array $body): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('Asaas não está ativado e configurado para o checkout.');
        }
        if (!preg_match('~^/(?:customers|payments)(?:[/?][A-Za-z0-9_?=&.%-]+)*$~', $path)) {
            throw new RuntimeException('Endpoint Asaas não permitido.');
        }
        $curl = curl_init($this->baseUrl . $path);
        if ($curl === false) {
            throw new RuntimeException('Não foi possível iniciar a comunicação com o Asaas.');
        }
        $headers = ['accept: application/json', 'content-type: application/json',
            'access_token: ' . $this->key, 'User-Agent: Tuffer/1.0'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($raw)) {
            throw new RuntimeException('Não foi possível conectar ao Asaas.');
        }
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            // Nunca registrar payloads, documentos ou credenciais em logs.
            throw new RuntimeException('O Asaas não aceitou ou não concluiu a solicitação (HTTP ' . $status . ').');
        }
        return $decoded;
    }
}
