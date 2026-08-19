<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use App\Core\Database;
use App\Services\Payments\PagarmeApiClient;
use App\Services\Payments\PagarmeClient;
use PDO;
use RuntimeException;
use Throwable;

final class PagarmePlatformAccountService
{
    private readonly PDO $pdo;
    private readonly PagarmeApiClient $client;
    private readonly PagarmeCheckoutConfiguration $configuration;

    public function __construct(
        ?PagarmeApiClient $client = null,
        ?PDO $database = null,
        ?PagarmeCheckoutConfiguration $configuration = null
    ) {
        $this->pdo = $database ?? Database::connection();
        $this->client = $client ?? new PagarmeClient();
        $this->configuration = $configuration ?? new PagarmeCheckoutConfiguration();
    }

    /** @return array<string,mixed>|null */
    public function account(): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM marketplace_payment_accounts
             WHERE provider='pagarme' AND environment=? LIMIT 1"
        );
        $statement->execute([$this->client->environment()]);
        $account = $statement->fetch();
        return is_array($account) ? $account : null;
    }

    /** @return array<string,mixed> */
    public function synchronize(): array
    {
        $recipientId = $this->configuration->platformRecipientId();
        if (!PagarmeRecipientId::isValid($recipientId)) {
            throw new RuntimeException('PAGARME_PLATFORM_RECIPIENT_ID ausente ou inválido.');
        }
        try {
            $recipient = $this->client->get('/recipients/' . rawurlencode($recipientId));
            if (!hash_equals($recipientId, (string) ($recipient['id'] ?? ''))) {
                throw new RuntimeException('O recipient retornado diverge da configuração da plataforma.');
            }
            $status = strtolower((string) ($recipient['status'] ?? ''));
            $kyc = is_array($recipient['kyc_details'] ?? null) ? $recipient['kyc_details'] : [];
            $kycStatus = PagarmeRecipientEligibility::effectiveKycStatus(
                $status,
                isset($kyc['status']) ? (string) $kyc['status'] : null
            );
            $enabled = PagarmeRecipientEligibility::isEligible($status, $kycStatus);
            $bank = is_array($recipient['default_bank_account'] ?? null) ? $recipient['default_bank_account'] : [];
            $bankName = trim((string) ($bank['bank'] ?? $bank['bank_name'] ?? ''));
            $account = preg_replace('/\D+/', '', (string) ($bank['account_number'] ?? '')) ?? '';
            $maskedAccount = $account === '' ? null : str_repeat('*', max(0, strlen($account) - 4)) . substr($account, -4);
            $this->pdo->prepare(
                "INSERT INTO marketplace_payment_accounts(
                    provider,environment,recipient_id,recipient_status,kyc_status,payment_enabled,
                    bank_name_masked,bank_account_masked,last_synced_at,last_sync_status,last_sync_error,approved_at
                 ) VALUES('pagarme',?,?,?,?,?,?,?,NOW(),'success',NULL,?)
                 ON DUPLICATE KEY UPDATE recipient_id=VALUES(recipient_id),
                    recipient_status=VALUES(recipient_status),kyc_status=VALUES(kyc_status),
                    payment_enabled=VALUES(payment_enabled),bank_name_masked=VALUES(bank_name_masked),
                    bank_account_masked=VALUES(bank_account_masked),last_synced_at=NOW(),
                    last_sync_status='success',last_sync_error=NULL,
                    approved_at=IF(VALUES(payment_enabled)=1,COALESCE(approved_at,NOW()),approved_at)"
            )->execute([
                $this->client->environment(),
                $recipientId,
                $status ?: null,
                $kycStatus,
                $enabled ? 1 : 0,
                $bankName === '' ? null : mb_substr($bankName, 0, 100),
                $maskedAccount,
                $enabled ? date('Y-m-d H:i:s') : null,
            ]);
            $this->pdo->prepare(
                "UPDATE sellers SET pagarme_recipient_id=?,payment_enabled=?,
                    payment_onboarding_status=?,payment_block_reason=?
                 WHERE is_official_store=1"
            )->execute([
                $recipientId,
                $enabled ? 1 : 0,
                $enabled ? 'active' : 'platform_pending',
                $enabled ? null : 'Recebedor da plataforma aguardando aprovação',
            ]);
            return $this->account() ?? [];
        } catch (Throwable $exception) {
            $this->recordFailure($recipientId, $exception);
            throw $exception;
        }
    }

    public function eligible(): bool
    {
        $account = $this->account();
        return is_array($account)
            && hash_equals($this->configuration->platformRecipientId(), (string) $account['recipient_id'])
            && PagarmeRecipientEligibility::isEligible(
                (string) ($account['recipient_status'] ?? ''),
                isset($account['kyc_status']) ? (string) $account['kyc_status'] : null
            )
            && (int) ($account['payment_enabled'] ?? 0) === 1;
    }

    private function recordFailure(string $recipientId, Throwable $exception): void
    {
        if (!PagarmeRecipientId::isValid($recipientId)) {
            return;
        }
        $this->pdo->prepare(
            "INSERT INTO marketplace_payment_accounts(
                provider,environment,recipient_id,payment_enabled,last_sync_status,last_sync_error,last_synced_at
             ) VALUES('pagarme',?,?,0,'failed',?,NOW())
             ON DUPLICATE KEY UPDATE payment_enabled=0,last_sync_status='failed',
                last_sync_error=VALUES(last_sync_error),last_synced_at=NOW()"
        )->execute([
            $this->client->environment(),
            $recipientId,
            mb_substr(strip_tags($exception->getMessage()), 0, 500),
        ]);
        $this->pdo->prepare(
            "UPDATE sellers SET payment_enabled=0,payment_onboarding_status='platform_pending',
                payment_block_reason='Falha ao validar o recebedor da plataforma'
             WHERE is_official_store=1"
        )->execute();
    }
}
