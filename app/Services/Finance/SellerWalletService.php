<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use App\Services\Payments\PagarmeClient;
use App\Services\Payments\Pagarme\PagarmeRecipientId;
use PDO;
use RuntimeException;

final class SellerWalletService
{
    public function __construct(
        private readonly ?PDO $database = null,
        private readonly ?PagarmeClient $client = null
    ) {
    }

    /** @return array<string,mixed> */
    public function snapshot(int $sellerId): array
    {
        $account = $this->account($sellerId);
        $recipientId = (string) ($account['recipient_id'] ?? '');
        $platformRecipient = trim((string) ($_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? ''));

        if (!PagarmeRecipientId::isValid($recipientId)
            || ($platformRecipient !== '' && hash_equals($platformRecipient, $recipientId))) {
            return [
                'configured' => false,
                'available_cents' => 0,
                'waiting_cents' => 0,
                'transferred_cents' => 0,
                'account' => $account,
                'transfers' => [],
            ];
        }

        $client = $this->client();
        if (!$client->configured()) {
            return [
                'configured' => false,
                'available_cents' => 0,
                'waiting_cents' => 0,
                'transferred_cents' => 0,
                'account' => $account,
                'transfers' => [],
            ];
        }

        $balance = $client->get('/recipients/' . rawurlencode($recipientId) . '/balance');
        $transfersResponse = $client->get('/transfers?recipient_id=' . rawurlencode($recipientId) . '&count=20');
        $transfers = is_array($transfersResponse['data'] ?? null)
            ? $transfersResponse['data']
            : (array_is_list($transfersResponse) ? $transfersResponse : []);

        return [
            'configured' => true,
            'available_cents' => $this->amount($balance['available_amount'] ?? 0),
            'waiting_cents' => $this->amount($balance['waiting_funds_amount'] ?? 0),
            'transferred_cents' => $this->amount($balance['transferred_amount'] ?? 0),
            'account' => $account,
            'transfers' => $transfers,
        ];
    }

    /** @return array<string,mixed> */
    public function withdraw(int $sellerId, int $amountCents): array
    {
        if ($amountCents < 100) {
            throw new RuntimeException('O valor mínimo para transferência é R$ 1,00.');
        }

        $account = $this->account($sellerId);
        $recipientId = (string) ($account['recipient_id'] ?? '');
        $platformRecipient = trim((string) ($_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? ''));
        if (!PagarmeRecipientId::isValid($recipientId)
            || ($platformRecipient !== '' && hash_equals($platformRecipient, $recipientId))) {
            throw new RuntimeException('O vendedor ainda não possui um recebedor Pagar.me válido.');
        }
        if ((int) ($account['enabled_for_sales'] ?? 0) !== 1) {
            throw new RuntimeException('A conta de recebimento ainda não está liberada pela Pagar.me.');
        }

        $balance = $this->client()->get('/recipients/' . rawurlencode($recipientId) . '/balance');
        $available = $this->amount($balance['available_amount'] ?? 0);
        if ($amountCents > $available) {
            throw new RuntimeException('O valor solicitado é maior que o saldo disponível.');
        }

        $key = 'seller-wallet-' . $sellerId . '-' . bin2hex(random_bytes(16));
        return $this->client()->post('/transfers', [
            'amount' => $amountCents,
            'recipient_id' => $recipientId,
            'metadata' => [
                'source' => 'tuffer_seller_wallet',
                'seller_id' => (string) $sellerId,
            ],
        ], $key);
    }

    /** @return array<string,mixed> */
    private function account(int $sellerId): array
    {
        $statement = $this->pdo()->prepare(
            "SELECT spa.*,s.is_official_store,s.trade_name
               FROM sellers s
               LEFT JOIN seller_payment_accounts spa
                 ON spa.seller_id=s.id
                AND spa.provider='pagarme'
                AND spa.environment=?
              WHERE s.id=?
              ORDER BY spa.id DESC
              LIMIT 1"
        );
        $statement->execute([$this->client()->environment(), $sellerId]);
        return $statement->fetch() ?: [];
    }

    private function amount(mixed $value): int
    {
        if (is_array($value)) {
            $value = $value['amount'] ?? $value['value'] ?? 0;
        }
        return max(0, (int) $value);
    }

    private function pdo(): PDO
    {
        return $this->database ?? Database::connection();
    }

    private function client(): PagarmeClient
    {
        return $this->client ?? new PagarmeClient();
    }
}
