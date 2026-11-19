<?php
declare(strict_types=1);

namespace App\Services\Payments\Asaas;

/** Restringe o Sandbox à conta cliente explicitamente autorizada no .env. */
final class AsaasCheckoutAccess
{
    /** @param array<string,mixed>|null $user */
    public static function status(?array $user): string
    {
        if (!(new AsaasClient())->checkoutEnabled()) return 'gateway_disabled';
        $environment = strtolower(trim((string) ($_ENV['ASAAS_ENV'] ?? 'sandbox')));
        if ($environment === 'production') {
            return filter_var($_ENV['ASAAS_PRODUCTION_APPROVED'] ?? false, FILTER_VALIDATE_BOOL)
                ? 'allowed' : 'production_locked';
        }
        if ($environment !== 'sandbox') return 'invalid_environment';
        $testEmail = strtolower(trim((string) ($_ENV['ASAAS_SANDBOX_TEST_EMAIL'] ?? '')));
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) return 'test_account_not_configured';
        if (empty($user['id'])) return 'login_required';
        if (($user['type'] ?? null) !== 'customer') return 'customer_account_required';
        if (strtolower(trim((string) ($user['email'] ?? ''))) !== $testEmail) return 'account_not_authorized';
        return 'allowed';
    }

    /** @param array<string,mixed>|null $user */
    public static function allowed(?array $user): bool
    {
        return self::status($user) === 'allowed';
    }

    public static function sandbox(): bool
    {
        return strtolower(trim((string) ($_ENV['ASAAS_ENV'] ?? 'sandbox'))) === 'sandbox';
    }

    public static function explanation(string $status): string
    {
        return match ($status) {
            'allowed' => 'Conta autorizada para testar o Asaas Sandbox.',
            'gateway_disabled' => 'O checkout do Asaas está desativado no ambiente web. Confira ASAAS_CHECKOUT_ENABLED e as credenciais do servidor.',
            'test_account_not_configured' => 'Configure ASAAS_SANDBOX_TEST_EMAIL no .env com o e-mail exato da conta de cliente de teste.',
            'login_required' => 'Entre com a conta de cliente autorizada para testar o Asaas.',
            'customer_account_required' => 'Para testar, entre com uma conta de cliente (não vendedor nem administrador).',
            'account_not_authorized' => 'O e-mail da conta conectada não corresponde a ASAAS_SANDBOX_TEST_EMAIL. Ajuste essa variável ou entre com a conta autorizada.',
            'production_locked' => 'Cobranças reais bloqueadas até autorização explícita de produção.',
            default => 'Ambiente de pagamento indisponível. Verifique a configuração do Asaas.',
        };
    }
}
