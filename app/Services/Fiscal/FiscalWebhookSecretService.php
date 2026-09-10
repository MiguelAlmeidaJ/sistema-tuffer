<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class FiscalWebhookSecretService
{
    private const CIPHER = 'aes-256-gcm';
    private const AAD = 'tuffer:fiscal:webhook:v1';

    public function __construct(private readonly ?string $appKey = null) {}

    public function generate(): string
    {
        return 'tfwh_' . $this->base64Url(random_bytes(32));
    }

    public function encrypt(string $secret): string
    {
        $key = $this->key();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, self::AAD, 16);
        if (!is_string($ciphertext) || $tag === '') throw new RuntimeException('Não foi possível proteger o segredo do webhook fiscal.');
        return 'v1.' . $this->base64Url($iv) . '.' . $this->base64Url($tag) . '.' . $this->base64Url($ciphertext);
    }

    public function decrypt(string $encrypted): string
    {
        $parts = explode('.', $encrypted);
        if (count($parts) !== 4 || $parts[0] !== 'v1') throw new RuntimeException('Segredo do webhook fiscal possui formato inválido.');
        $iv = $this->base64UrlDecode($parts[1]);
        $tag = $this->base64UrlDecode($parts[2]);
        $ciphertext = $this->base64UrlDecode($parts[3]);
        $plain = openssl_decrypt($ciphertext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, self::AAD);
        if (!is_string($plain) || $plain === '') throw new RuntimeException('Não foi possível descriptografar o segredo do webhook fiscal.');
        return $plain;
    }

    public function prefix(string $secret): string
    {
        return mb_substr($secret, 0, 12);
    }

    public function signature(string $secret, int $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    private function key(): string
    {
        $source = trim((string)($this->appKey ?? ($_ENV['APP_KEY'] ?? '')));
        if (strlen($source) < 16) throw new RuntimeException('APP_KEY precisa estar configurada antes de habilitar webhooks fiscais.');
        return hash('sha256', $source, true);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded)) throw new RuntimeException('Segredo do webhook fiscal possui codificação inválida.');
        return $decoded;
    }
}
