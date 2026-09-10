<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class FiscalWebhookEndpointPolicy
{
    /** @return array{url:string,host:string,ip:string} */
    public function validate(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Informe uma URL HTTPS válida para o webhook fiscal.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('O webhook fiscal deve usar HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('A URL do webhook fiscal possui componentes não permitidos.');
        }
        $port = (int)($parts['port'] ?? 443);
        if ($port !== 443) throw new RuntimeException('O webhook fiscal deve usar a porta HTTPS padrão 443.');
        $host = strtolower(trim((string)($parts['host'] ?? '')));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new RuntimeException('O webhook fiscal deve apontar para um host público.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new RuntimeException('Webhooks fiscais aceitam atualmente host IPv4 público ou hostname com resolução IPv4.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) throw new RuntimeException('Não foi possível resolver o host IPv4 do webhook fiscal.');
        foreach ($ips as $ip) {
            if (!$this->publicIpv4($ip)) throw new RuntimeException('O webhook fiscal não pode apontar para rede privada, local ou reservada.');
        }
        return ['url'=>$url,'host'=>$host,'ip'=>$ips[0]];
    }

    private function publicIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
