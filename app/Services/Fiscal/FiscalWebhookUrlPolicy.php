<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use Closure;
use RuntimeException;

final class FiscalWebhookUrlPolicy
{
    /** @param Closure(string):array<int,string>|null $resolver */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /** @return array{url:string,host:string,port:int,ip:string} */
    public function validate(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) throw new RuntimeException('Informe uma URL HTTPS válida para o webhook fiscal.');

        $parts = parse_url($url);
        if (!is_array($parts) || mb_strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('O webhook fiscal deve usar HTTPS.');
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) throw new RuntimeException('A URL do webhook não pode conter usuário ou senha.');
        if (!empty($parts['fragment'])) throw new RuntimeException('A URL do webhook não pode conter fragmento.');

        $host = mb_strtolower(trim((string)($parts['host'] ?? '')));
        if ($host === '') throw new RuntimeException('A URL do webhook deve possuir um host válido.');
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new RuntimeException('Hosts locais ou internos não são permitidos para webhook fiscal.');
        }

        $port = (int)($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535) throw new RuntimeException('Porta inválida na URL do webhook fiscal.');

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if ($ips === []) throw new RuntimeException('Não foi possível resolver o host do webhook fiscal.');

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) throw new RuntimeException('O webhook fiscal deve apontar para um endereço público.');
        }

        return ['url'=>$url,'host'=>$host,'port'=>$port,'ip'=>$ips[0]];
    }

    /** @return array<int,string> */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) return array_values(array_unique(array_filter(($this->resolver)($host), 'is_string')));
        $ips = gethostbynamel($host);
        return is_array($ips) ? array_values(array_unique($ips)) : [];
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
