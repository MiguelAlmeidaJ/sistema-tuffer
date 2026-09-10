<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class FiscalConnectorCredentialService
{
    private const CIPHER = 'aes-256-gcm';
    private const AAD = 'tuffer:fiscal:connector:v1';

    public function __construct(private readonly ?string $appKey = null) {}

    /** @param array<string,mixed> $credentials */
    public function encrypt(array $credentials): string
    {
        $json = json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($json, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, self::AAD, 16);
        if (!is_string($ciphertext) || $tag === '') throw new RuntimeException('Não foi possível proteger a credencial do conector fiscal.');
        return 'v1.' . $this->b64($iv) . '.' . $this->b64($tag) . '.' . $this->b64($ciphertext);
    }

    /** @return array<string,mixed> */
    public function decrypt(string $encrypted): array
    {
        $parts = explode('.', $encrypted);
        if (count($parts) !== 4 || $parts[0] !== 'v1') throw new RuntimeException('Credencial do conector fiscal possui formato inválido.');
        $plain = openssl_decrypt($this->decode($parts[3]), self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $this->decode($parts[1]), $this->decode($parts[2]), self::AAD);
        if (!is_string($plain) || $plain === '') throw new RuntimeException('Não foi possível descriptografar a credencial do conector fiscal.');
        $decoded = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('Credencial do conector fiscal está corrompida.');
        return $decoded;
    }

    public function prefix(string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') return 'não informado';
        return mb_substr($secret, 0, 6) . '…' . mb_substr($secret, -4);
    }

    private function key(): string
    {
        $source = trim((string)($this->appKey ?? ($_ENV['APP_KEY'] ?? '')));
        if (strlen($source) < 16) throw new RuntimeException('APP_KEY precisa estar configurada antes de conectar um provedor fiscal.');
        return hash('sha256', $source, true);
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded)) throw new RuntimeException('Credencial do conector fiscal possui codificação inválida.');
        return $decoded;
    }
}
