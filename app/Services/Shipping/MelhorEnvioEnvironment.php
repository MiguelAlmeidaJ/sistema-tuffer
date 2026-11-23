<?php
declare(strict_types=1);

namespace App\Services\Shipping;

use RuntimeException;

/** Ambiente de frete independente de APP_DEBUG, com URL fixada em hosts oficiais. */
final class MelhorEnvioEnvironment
{
    public static function sandbox(): bool
    {
        return filter_var($_ENV['MELHOR_ENVIO_SANDBOX'] ?? true, FILTER_VALIDATE_BOOL);
    }

    public static function baseUrl(): string
    {
        $sandbox=self::sandbox();
        $configured=rtrim(trim((string)($_ENV['MELHOR_ENVIO_BASE_URL'] ?? '')), '/');
        $base=$configured !== '' ? $configured : ($sandbox ? 'https://sandbox.melhorenvio.com.br' : 'https://melhorenvio.com.br');
        $parts=parse_url($base);
        $host=strtolower((string)($parts['host'] ?? ''));
        $expected=$sandbox?['sandbox.melhorenvio.com.br']:['melhorenvio.com.br','www.melhorenvio.com.br'];
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($host,$expected,true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || !in_array((string)($parts['path'] ?? ''),['','/api/v2'],true)
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('URL do Melhor Envio incompatível com MELHOR_ENVIO_SANDBOX; revise o .env.');
        }
        return str_ends_with($base,'/api/v2')?substr($base,0,-7):$base;
    }
}
