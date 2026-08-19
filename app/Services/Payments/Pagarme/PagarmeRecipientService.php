<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use App\Core\Database;
use App\Services\Payments\PagarmeClient;
use App\Services\Payments\PagarmeException;
use App\Services\Wholesale\CnpjValidator;
use PDO;
use RuntimeException;
use Throwable;

final class PagarmeRecipientService
{
    private readonly PagarmeClient $client;
    private readonly PDO $pdo;

    public function __construct(?PagarmeClient $client = null, ?PDO $database = null)
    {
        $this->client = $client ?? new PagarmeClient();
        $this->pdo = $database ?? Database::connection();
    }

    public function environment(): string
    {
        return $this->client->environment();
    }

    /** @return array<string,mixed>|null */
    public function accountForSeller(int $sellerId): ?array
    {
        if ($this->isOfficialSeller($sellerId)) {
            return null;
        }
        $statement = $this->pdo->prepare(
            "SELECT * FROM seller_payment_accounts WHERE seller_id=? AND provider='pagarme' AND environment=? LIMIT 1"
        );
        $statement->execute([$sellerId, $this->environment()]);
        $account = $statement->fetch();
        if (is_array($account)) {
            return $account;
        }

        $legacy = $this->pdo->prepare(
            "SELECT pagarme_recipient_id FROM sellers s
             WHERE s.id=? AND s.pagarme_recipient_id IS NOT NULL
               AND NOT EXISTS(SELECT 1 FROM seller_payment_accounts spa WHERE spa.seller_id=s.id)
             LIMIT 1"
        );
        $legacy->execute([$sellerId]);
        $recipientId = (string) ($legacy->fetchColumn() ?: '');
        if (!$this->validRecipientId($recipientId)) {
            return null;
        }
        $this->pdo->prepare(
            "INSERT INTO seller_payment_accounts
             (seller_id,provider,environment,recipient_id,onboarding_status,enabled_for_sales)
             VALUES (?,'pagarme',?,?,'registration_pending',0)"
        )->execute([$sellerId, $this->environment(), $recipientId]);
        return $this->accountForSeller($sellerId);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createForSeller(int $sellerId, array $input): array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT s.*,u.email user_email,u.phone user_phone FROM sellers s JOIN users u ON u.id=s.user_id WHERE s.id=? FOR UPDATE'
            );
            $statement->execute([$sellerId]);
            $seller = $statement->fetch();
            if (!is_array($seller) || ($seller['status'] ?? null) !== 'active') {
                throw new RuntimeException('O vendedor precisa estar aprovado pela Tuffer antes da configuração financeira.');
            }
            if ((int) ($seller['is_official_store'] ?? 0) === 1) {
                throw new RuntimeException('A loja oficial usa exclusivamente o recebedor global da plataforma.');
            }
            $existing = $this->accountForSeller($sellerId);
            if (is_array($existing) && $this->validRecipientId((string) ($existing['recipient_id'] ?? ''))) {
                throw new RuntimeException('Este vendedor já possui um recebedor Pagar.me neste ambiente.');
            }

            $payload = $this->recipientPayload($seller, $input);
            $response = $this->client->post(
                '/recipients',
                $payload,
                'recipient-seller-' . $sellerId . '-' . $this->environment()
            );
            $recipientId = trim((string) ($response['id'] ?? ''));
            if (!$this->validRecipientId($recipientId)) {
                throw new PagarmeException('A Pagar.me não retornou um identificador de recebedor válido.');
            }

            $bank = $payload['default_bank_account'];
            $branch = (string) $bank['branch_number'];
            $account = (string) $bank['account_number'];
            $this->pdo->prepare(
                "INSERT INTO seller_payment_accounts
                 (seller_id,provider,environment,recipient_id,registration_type,bank_code,bank_branch_masked,bank_account_masked,bank_account_type,onboarding_status,enabled_for_sales,last_synced_at)
                 VALUES (?,'pagarme',?,?,?,?,?,?,?,'registration_pending',0,NOW())
                 ON DUPLICATE KEY UPDATE recipient_id=VALUES(recipient_id),registration_type=VALUES(registration_type),
                    bank_code=VALUES(bank_code),bank_branch_masked=VALUES(bank_branch_masked),
                    bank_account_masked=VALUES(bank_account_masked),bank_account_type=VALUES(bank_account_type),
                    onboarding_status='registration_pending',enabled_for_sales=0,last_synced_at=NOW()"
            )->execute([
                $sellerId,
                $this->environment(),
                $recipientId,
                'corporation',
                (string) $bank['bank'],
                $this->mask($branch, 1),
                $this->mask($account, 2) . '-' . (string) $bank['account_check_digit'],
                (string) $bank['type'],
            ]);
            $this->pdo->prepare(
                "UPDATE sellers SET pagarme_recipient_id=?,payment_onboarding_status='registration_pending',
                 payment_enabled=0,payment_block_reason='Aguardando liberação cadastral da Pagar.me' WHERE id=?"
            )->execute([$recipientId, $sellerId]);
            $this->applyProviderStatus($sellerId, $response);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $this->accountForSeller($sellerId) ?? [];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function synchronizeStatus(int $sellerId): array
    {
        $account = $this->accountForSeller($sellerId);
        $recipientId = (string) ($account['recipient_id'] ?? '');
        if (!$this->validRecipientId($recipientId)) {
            throw new RuntimeException('O recebedor Pagar.me ainda não foi criado.');
        }
        $recipient = $this->client->get('/recipients/' . rawurlencode($recipientId));
        $this->applyProviderStatus($sellerId, $recipient);
        return $this->accountForSeller($sellerId) ?? [];
    }

    /** @return array<string,mixed> */
    public function synchronizeByRecipientId(string $recipientId): array
    {
        if (!$this->validRecipientId($recipientId)) {
            throw new RuntimeException('Identificador de recebedor inválido.');
        }
        $statement = $this->pdo->prepare(
            "SELECT seller_id FROM seller_payment_accounts
             WHERE provider='pagarme' AND environment=? AND recipient_id=? LIMIT 1"
        );
        $statement->execute([$this->environment(), $recipientId]);
        $sellerId = (int) $statement->fetchColumn();
        if ($sellerId < 1) {
            throw new RuntimeException('Recebedor Pagar.me não localizado na Tuffer.');
        }
        return $this->synchronizeStatus($sellerId);
    }

    public function generateKycLink(int $sellerId): string
    {
        $account = $this->synchronizeStatus($sellerId);
        $recipientId = (string) ($account['recipient_id'] ?? '');
        if (in_array((string) ($account['onboarding_status'] ?? ''), ['active', 'rejected', 'blocked'], true)) {
            throw new RuntimeException('O estado atual do recebedor não permite iniciar uma nova validação.');
        }
        if (($account['recipient_status'] ?? null) !== 'affiliation'
            || ($account['kyc_status'] ?? null) !== 'partially_denied') {
            throw new RuntimeException('A Pagar.me ainda não liberou a etapa de validação de identidade.');
        }

        $response = $this->client->post('/recipients/' . rawurlencode($recipientId) . '/kyc_link');
        $url = $this->trustedKycUrl((string) ($response['url'] ?? ''));
        $expiration = strtotime((string) ($response['expiration_date'] ?? ''));
        if ($url === '' || $expiration === false || $expiration <= time()) {
            throw new PagarmeException('A Pagar.me não retornou um link de validação válido.');
        }
        $this->pdo->prepare(
            "UPDATE seller_payment_accounts SET kyc_url=?,kyc_url_expires_at=?,
             onboarding_status='kyc_pending',updated_at=NOW() WHERE seller_id=? AND provider='pagarme' AND environment=?"
        )->execute([$url, date('Y-m-d H:i:s', $expiration), $sellerId, $this->environment()]);
        $this->pdo->prepare(
            "UPDATE sellers SET payment_onboarding_status='kyc_pending',payment_enabled=0,
             payment_block_reason='Conclua a validação de identidade na Pagar.me' WHERE id=?"
        )->execute([$sellerId]);
        return $url;
    }

    /** @param array<string,mixed> $recipient */
    public function applyProviderStatus(int $sellerId, array $recipient): void
    {
        $recipientId = trim((string) ($recipient['id'] ?? ''));
        if (!$this->validRecipientId($recipientId)) {
            throw new PagarmeException('Resposta de recebedor inválida.');
        }
        $recipientStatus = strtolower(trim((string) ($recipient['status'] ?? '')));
        $kyc = is_array($recipient['kyc_details'] ?? null) ? $recipient['kyc_details'] : [];
        $kycStatus = PagarmeRecipientEligibility::effectiveKycStatus(
            $recipientStatus,
            isset($kyc['status']) ? (string) $kyc['status'] : null
        );
        $reason = strtolower(trim((string) ($kyc['status_reason'] ?? '')));
        $onboarding = self::mapOnboardingStatus($recipientStatus, (string) $kycStatus, $reason);
        $enabled = PagarmeRecipientEligibility::isEligible($recipientStatus, $kycStatus);
        $blockReason = $enabled ? null : $this->blockReason($onboarding);

        $update = $this->pdo->prepare(
            "UPDATE seller_payment_accounts SET recipient_status=?,kyc_status=?,kyc_status_reason=?,
             onboarding_status=?,enabled_for_sales=?,last_synced_at=NOW(),
             approved_at=IF(?,COALESCE(approved_at,NOW()),approved_at),
             rejected_at=IF(?='rejected',COALESCE(rejected_at,NOW()),rejected_at),
             kyc_url=IF(? IN ('active','rejected','blocked'),NULL,kyc_url),
             kyc_url_expires_at=IF(? IN ('active','rejected','blocked'),NULL,kyc_url_expires_at)
             WHERE seller_id=? AND provider='pagarme' AND environment=? AND recipient_id=?"
        );
        $update->execute([
            $recipientStatus ?: null,
            $kycStatus,
            $reason ?: null,
            $onboarding,
            $enabled ? 1 : 0,
            $enabled ? 1 : 0,
            $onboarding,
            $onboarding,
            $onboarding,
            $sellerId,
            $this->environment(),
            $recipientId,
        ]);
        if ($update->rowCount() === 0 && $this->accountForSeller($sellerId) === null) {
            throw new RuntimeException('Conta de recebimento local não localizada.');
        }
        $this->pdo->prepare(
            'UPDATE sellers SET pagarme_recipient_id=?,payment_onboarding_status=?,payment_enabled=?,payment_block_reason=? WHERE id=?'
        )->execute([$recipientId, $onboarding, $enabled ? 1 : 0, $blockReason, $sellerId]);
    }

    public static function mapOnboardingStatus(string $recipientStatus, string $kycStatus, string $reason = ''): string
    {
        if (PagarmeRecipientEligibility::isEligible($recipientStatus, $kycStatus)) {
            return 'active';
        }
        if ($recipientStatus === 'refused' || $kycStatus === 'denied') {
            return 'rejected';
        }
        if (in_array($recipientStatus, ['blocked', 'suspended', 'inactive'], true)) {
            return 'blocked';
        }
        if ($recipientStatus === 'registration') {
            return 'registration_pending';
        }
        if ($recipientStatus === 'affiliation' && $kycStatus === 'partially_denied'
            && $reason === 'additional_documents_required') {
            return 'kyc_pending';
        }
        if ($recipientStatus === 'affiliation' || $kycStatus === 'pending') {
            return 'analyzing';
        }
        return 'registration_pending';
    }

    /** @param array<string,mixed> $seller @param array<string,mixed> $input @return array<string,mixed> */
    private function recipientPayload(array $seller, array $input): array
    {
        $cnpj = preg_replace('/\D+/', '', (string) ($seller['document'] ?? '')) ?? '';
        if (!(new CnpjValidator())->isValid($cnpj)) {
            throw new RuntimeException('O vendedor precisa possuir um CNPJ válido para criar o recebedor.');
        }
        $required = [
            'site_url', 'annual_revenue', 'corporation_type', 'founding_date',
            'company_street', 'company_number', 'company_complement', 'company_neighborhood',
            'company_city', 'company_state', 'company_zip_code', 'company_reference_point',
            'company_phone', 'partner_name', 'partner_email', 'partner_document',
            'partner_mother_name', 'partner_birthdate', 'partner_monthly_income',
            'partner_occupation', 'partner_street', 'partner_number', 'partner_complement',
            'partner_neighborhood', 'partner_city', 'partner_state', 'partner_zip_code',
            'partner_reference_point', 'partner_phone', 'bank_code', 'branch_number',
            'account_number', 'account_check_digit', 'bank_account_type',
            'bank_holder_name', 'bank_holder_document',
        ];
        foreach ($required as $field) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                throw new RuntimeException('Preencha todos os dados empresariais, do representante e da conta bancária.');
            }
        }
        if (!filter_var((string) $input['site_url'], FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url((string) $input['site_url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new RuntimeException('Informe um site válido para a empresa.');
        }
        if (!filter_var((string) $input['partner_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido para o representante legal.');
        }
        $financialEmail = trim((string) ($input['financial_email'] ?? $seller['user_email']));
        if (!filter_var($financialEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail financeiro válido.');
        }
        if ((float) $input['annual_revenue'] <= 0 || (float) $input['partner_monthly_income'] <= 0) {
            throw new RuntimeException('Informe faturamento e renda estimados maiores que zero.');
        }
        $partnerDocument = preg_replace('/\D+/', '', (string) $input['partner_document']) ?? '';
        if (!$this->validCpf($partnerDocument)) {
            throw new RuntimeException('Informe um CPF válido para o representante legal.');
        }
        $holderDocument = preg_replace('/\D+/', '', (string) $input['bank_holder_document']) ?? '';
        $validHolder = strlen($holderDocument) === 11
            ? $this->validCpf($holderDocument)
            : (new CnpjValidator())->isValid($holderDocument);
        if (!$validHolder) {
            throw new RuntimeException('Informe um CPF ou CNPJ válido para o titular da conta.');
        }
        foreach (['bank_code', 'branch_number', 'account_number'] as $numericField) {
            if (!preg_match('/^\d+$/', trim((string) $input[$numericField]))) {
                throw new RuntimeException('Banco, agência e conta devem conter somente números.');
            }
        }
        $companyPhone = $this->phone((string) $input['company_phone']);
        $partnerPhone = $this->phone((string) $input['partner_phone']);

        return [
            'code' => 'seller_' . (int) $seller['id'],
            'register_information' => [
                'company_name' => mb_substr(trim((string) $seller['trade_name']), 0, 255),
                'trading_name' => mb_substr(trim((string) $seller['legal_name']), 0, 255),
                'email' => mb_substr($financialEmail, 0, 255),
                'document' => $cnpj,
                'type' => 'corporation',
                'site_url' => trim((string) $input['site_url']),
                'annual_revenue' => (int) round(max(0, (float) $input['annual_revenue'])),
                'corporation_type' => mb_substr(trim((string) $input['corporation_type']), 0, 50),
                'founding_date' => $this->date((string) $input['founding_date']),
                'main_address' => $this->address($input, 'company'),
                'phone_numbers' => [$companyPhone],
                'managing_partners' => [[
                    'name' => mb_substr(trim((string) $input['partner_name']), 0, 255),
                    'email' => mb_substr(trim((string) $input['partner_email']), 0, 255),
                    'document' => $partnerDocument,
                    'type' => 'individual',
                    'mother_name' => mb_substr(trim((string) $input['partner_mother_name']), 0, 255),
                    'birthdate' => $this->date((string) $input['partner_birthdate']) . 'T00:00:00',
                    'monthly_income' => (int) round(max(0, (float) $input['partner_monthly_income'])),
                    'professional_occupation' => mb_substr(trim((string) $input['partner_occupation']), 0, 100),
                    'self_declared_legal_representative' => true,
                    'address' => $this->address($input, 'partner'),
                    'phone_numbers' => [$partnerPhone],
                ]],
            ],
            'transfer_settings' => [
                'transfer_enabled' => true,
                'transfer_interval' => 'Daily',
                'transfer_day' => 0,
            ],
            'default_bank_account' => [
                'holder_name' => mb_substr(trim((string) $input['bank_holder_name']), 0, 255),
                'holder_type' => strlen($holderDocument) === 14 ? 'company' : 'individual',
                'holder_document' => $holderDocument,
                'bank' => preg_replace('/\D+/', '', (string) $input['bank_code']) ?? '',
                'branch_number' => preg_replace('/\D+/', '', (string) $input['branch_number']) ?? '',
                'branch_check_digit' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['branch_check_digit'] ?? '')) ?? '', 0, 2),
                'account_number' => preg_replace('/\D+/', '', (string) $input['account_number']) ?? '',
                'account_check_digit' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $input['account_check_digit']) ?? '', 0, 2),
                'type' => in_array($input['bank_account_type'], ['checking', 'savings'], true) ? $input['bank_account_type'] : 'checking',
            ],
            'metadata' => ['tuffer_seller_id' => (string) $seller['id']],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,string> */
    private function address(array $input, string $prefix): array
    {
        $zipCode = preg_replace('/\D+/', '', (string) $input[$prefix . '_zip_code']) ?? '';
        $state = mb_strtoupper(trim((string) $input[$prefix . '_state']));
        if (strlen($zipCode) !== 8 || !preg_match('/^[A-Z]{2}$/', $state)) {
            throw new RuntimeException('Informe CEP e UF válidos nos endereços.');
        }
        return [
            'street' => mb_substr(trim((string) $input[$prefix . '_street']), 0, 255),
            'complementary' => mb_substr(trim((string) $input[$prefix . '_complement']), 0, 255),
            'street_number' => mb_substr(trim((string) $input[$prefix . '_number']), 0, 30),
            'neighborhood' => mb_substr(trim((string) $input[$prefix . '_neighborhood']), 0, 100),
            'city' => mb_substr(trim((string) $input[$prefix . '_city']), 0, 100),
            'state' => $state,
            'zip_code' => $zipCode,
            'reference_point' => mb_substr(trim((string) $input[$prefix . '_reference_point']), 0, 255),
        ];
    }

    /** @return array{ddd:string,number:string,type:string} */
    private function phone(string $value): array
    {
        $phone = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($phone) < 10 || strlen($phone) > 11) {
            throw new RuntimeException('Informe telefones válidos com DDD.');
        }
        return ['ddd' => substr($phone, 0, 2), 'number' => substr($phone, 2), 'type' => 'mobile'];
    }

    private function date(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false || date('Y-m-d', $timestamp) !== $value) {
            throw new RuntimeException('Informe datas válidas.');
        }
        return $value;
    }

    private function validCpf(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($position = 9; $position <= 10; $position++) {
            $sum = 0;
            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $cpf[$index] * (($position + 1) - $index);
            }
            $digit = (10 * $sum) % 11;
            if ($digit === 10) {
                $digit = 0;
            }
            if ((int) $cpf[$position] !== $digit) {
                return false;
            }
        }
        return true;
    }

    private function validRecipientId(string $recipientId): bool
    {
        return PagarmeRecipientId::isValid($recipientId);
    }

    private function isOfficialSeller(int $sellerId): bool
    {
        $statement = $this->pdo->prepare('SELECT is_official_store FROM sellers WHERE id=? LIMIT 1');
        $statement->execute([$sellerId]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function trustedKycUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, 'www.pagar.me/')) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return ($parts['scheme'] ?? '') === 'https'
            && ($host === 'pagar.me' || str_ends_with($host, '.pagar.me'))
            ? $url
            : '';
    }

    private function mask(string $value, int $visible): string
    {
        $length = strlen($value);
        return str_repeat('*', max(0, $length - $visible)) . substr($value, -$visible);
    }

    private function blockReason(string $onboarding): string
    {
        return match ($onboarding) {
            'kyc_pending' => 'Conclua a validação de identidade na Pagar.me',
            'analyzing' => 'Cadastro financeiro em análise pela Pagar.me',
            'rejected' => 'Cadastro financeiro recusado pela Pagar.me',
            'blocked' => 'Recebedor bloqueado ou suspenso pela Pagar.me',
            default => 'Aguardando liberação cadastral da Pagar.me',
        };
    }
}
