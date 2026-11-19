<?php
declare(strict_types=1);

namespace App\Services\Payments\Asaas;

/**
 * Bloqueia vendas públicas durante a homologação do Asaas.
 * Em Sandbox, somente o e-mail explicitamente autorizado pode finalizar.
 */
final class AsaasCheckoutAccess
{
    public static function allowed(?array $user): bool
    {
        $client = new AsaasClient();
        if (!$client->checkoutEnabled()) return false;
        $environment = strtolower(trim((string) ($_ENV['ASAAS_ENV'] ?? 'sandbox')));
        if ($environment !== 'sandbox') return true;
        $testEmail = strtolower(trim((string) ($_ENV['ASAAS_SANDBOX_TEST_EMAIL'] ?? '')));
        if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) return false;
        return ($user['type'] ?? null) === 'customer'
            && strtolower(trim((string) ($user['email'] ?? ''))) === $testEmail;
    }

    public static function sandbox(): bool
    {
        return strtolower(trim((string) ($_ENV['ASAAS_ENV'] ?? 'sandbox'))) === 'sandbox';
    }
}
