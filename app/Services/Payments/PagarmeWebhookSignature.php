<?php

declare(strict_types=1);

namespace App\Services\Payments;

final class PagarmeWebhookSignature
{
    public function __construct(private readonly ?string $secret = null)
    {
    }

    /** Returns the algorithm used when the signature is valid. */
    public function verify(string $rawBody, ?string $signature): string
    {
        $secret = trim($this->secret ?? (string) ($_ENV['PAGARME_WEBHOOK_SECRET'] ?? ''));
        if (strlen($secret) < 16) {
            throw new PagarmeWebhookException('O segredo do webhook Pagar.me não está configurado com segurança.', 503);
        }
        if ($rawBody === '' || !is_string($signature) || trim($signature) === '') {
            throw new PagarmeWebhookException('Assinatura do webhook ausente.', 401);
        }

        foreach ($this->candidates($signature) as [$algorithm, $provided]) {
            $expected = hash_hmac($algorithm, $rawBody, $secret);
            if (hash_equals($expected, strtolower($provided))) {
                return $algorithm;
            }
        }

        throw new PagarmeWebhookException('Assinatura do webhook inválida.', 401);
    }

    /** @return array<int,array{string,string}> */
    private function candidates(string $signature): array
    {
        $candidates = [];
        foreach (preg_split('/[,;]\s*/', trim($signature)) ?: [] as $part) {
            if (preg_match('/^(sha256|sha1)=([a-f0-9]+)$/i', trim($part), $matches)) {
                $candidates[] = [strtolower($matches[1]), strtolower($matches[2])];
                continue;
            }
            if (preg_match('/^[a-f0-9]{64}$/i', trim($part))) {
                $candidates[] = ['sha256', strtolower(trim($part))];
            } elseif (preg_match('/^[a-f0-9]{40}$/i', trim($part))) {
                $candidates[] = ['sha1', strtolower(trim($part))];
            }
        }
        return $candidates;
    }
}
