<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use App\Services\Payments\PagarmeApiClient;
use App\Services\Payments\PagarmeClient;
use Throwable;

final class PagarmePlatformDiagnosticService
{
    private readonly PagarmeApiClient $client;
    private readonly PagarmeCheckoutConfiguration $configuration;

    public function __construct(
        ?PagarmeApiClient $client = null,
        ?PagarmeCheckoutConfiguration $configuration = null
    ) {
        $this->client = $client ?? new PagarmeClient();
        $this->configuration = $configuration ?? new PagarmeCheckoutConfiguration();
    }

    /**
     * Executa exclusivamente GET no recebedor configurado.
     *
     * @return array<string,mixed>
     */
    public function inspect(): array
    {
        $recipientId = $this->configuration->platformRecipientId();
        $base = [
            'ok' => false,
            'operation' => 'read_only',
            'checkout_mode' => $this->configuration->mode(),
            'orders_pix_enabled' => $this->configuration->ordersPixEnabled(),
            'split_enabled' => $this->configuration->splitEnabled(),
            'allowed_sellers_count' => count($this->configuration->allowedSellerIds()),
            'recipient_id' => $this->mask($recipientId),
            'recipient_id_valid' => $this->configuration->validPlatformRecipientId(),
            'key_environment' => $this->client->environment(),
            'recipient_status' => null,
            'kyc_status' => null,
            'kyc_evidence' => null,
            'environment_match' => false,
        ];

        if (!$this->configuration->validPlatformRecipientId()) {
            $base['error'] = 'PAGARME_PLATFORM_RECIPIENT_ID ausente ou inválido.';
            return $base;
        }
        if (!$this->client->configured()) {
            $base['error'] = 'Cliente Pagar.me não configurado ou desabilitado.';
            return $base;
        }

        try {
            $recipient = $this->client->get('/recipients/' . rawurlencode($recipientId));
            $returnedId = trim((string) ($recipient['id'] ?? ''));
            $kyc = is_array($recipient['kyc_details'] ?? null) ? $recipient['kyc_details'] : [];
            $base['recipient_status'] = strtolower(trim((string) ($recipient['status'] ?? ''))) ?: null;
            $base['kyc_status'] = PagarmeRecipientEligibility::effectiveKycStatus(
                (string) $base['recipient_status'],
                isset($kyc['status']) ? (string) $kyc['status'] : null
            );
            $base['kyc_evidence'] = $base['kyc_status'] === PagarmeRecipientEligibility::LEGACY_KYC_NOT_REQUIRED
                ? 'active_existing_recipient'
                : ($base['kyc_status'] === 'approved' ? 'kyc_details.approved' : null);
            // Um recipient só é retornado quando existe no ambiente autenticado pela chave atual.
            $base['environment_match'] = hash_equals($recipientId, $returnedId);
            $base['ok'] = $base['environment_match']
                && PagarmeRecipientEligibility::isEligible(
                    (string) $base['recipient_status'],
                    (string) $base['kyc_status']
                );
            if (!$base['ok']) {
                $base['error'] = 'O recebedor não está ativo/aprovado no ambiente autenticado.';
            }
        } catch (Throwable $exception) {
            $base['error'] = mb_substr(strip_tags($exception->getMessage()), 0, 240);
        }

        return $base;
    }

    private function mask(string $value): string
    {
        if ($value === '') {
            return '(não configurado)';
        }
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }
        return substr($value, 0, 3) . str_repeat('*', strlen($value) - 7) . substr($value, -4);
    }
}
