<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use App\Core\Database;
use App\Services\Payments\PagarmeApiClient;
use App\Services\Payments\PagarmeClient;
use App\Services\Payments\PagarmeWebhookProcessor;
use App\Services\Finance\FinancialSplitConsolidator;
use PDO;
use RuntimeException;
use Throwable;

final class PagarmePixRefundService
{
    private readonly PDO $pdo;
    private readonly PagarmeApiClient $client;

    public function __construct(?PagarmeApiClient $client = null, ?PDO $database = null)
    {
        $this->pdo = $database ?? Database::connection();
        $this->client = $client ?? new PagarmeClient();
    }

    /** @return array<string,mixed> */
    public function refundFull(int $paymentId): array
    {
        $charge = $this->refundableCharge($paymentId);
        $idempotencyKey = 'pix-refund-full-' . $paymentId . '-' . (int) $charge['pagarme_charge_id'];
        $this->pdo->prepare(
            "INSERT INTO pagarme_refund_attempts(
                payment_id,pagarme_charge_id,idempotency_key,refund_type,status
             ) VALUES(?,?,?,'full','pending')
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        )->execute([$paymentId, $charge['pagarme_charge_id'], $idempotencyKey]);
        $attemptId = (int) $this->pdo->lastInsertId();
        $attempt = $this->pdo->prepare('SELECT * FROM pagarme_refund_attempts WHERE id=?');
        $attempt->execute([$attemptId]);
        $attemptRow = $attempt->fetch();
        if (is_array($attemptRow) && ($attemptRow['status'] ?? null) === 'confirmed') {
            return ['status' => 'confirmed', 'charge_id' => (string) $charge['external_charge_id']];
        }

        $split = $this->splitForRefund($paymentId, (int) $charge['amount_cents']);
        $this->pdo->prepare(
            "UPDATE pagarme_refund_attempts SET status='requested',last_error=NULL WHERE id=?"
        )->execute([$attemptId]);
        try {
            $response = $this->client->delete(
                '/charges/' . rawurlencode((string) $charge['external_charge_id']),
                ['split' => $split],
                $idempotencyKey
            );
            $status = strtolower(trim((string) ($response['status'] ?? '')));
            $this->pdo->prepare(
                "UPDATE pagarme_refund_attempts
                 SET status=?,external_refund_id=?,last_error=NULL WHERE id=?"
            )->execute([
                in_array($status, ['refunded', 'chargedback'], true) ? 'confirmed' : 'requested',
                isset($response['id']) ? (string) $response['id'] : null,
                $attemptId,
            ]);
            $this->processProviderResponse($charge, $response);
            return (new PagarmePayloadSanitizer())->charge($response);
        } catch (Throwable $exception) {
            $this->pdo->prepare(
                "UPDATE pagarme_refund_attempts
                 SET status='uncertain',last_error=? WHERE id=?"
            )->execute([mb_substr(strip_tags($exception->getMessage()), 0, 500), $attemptId]);
            throw $exception;
        }
    }

    public function refundPartial(int $paymentId, int $amountCents): never
    {
        (new PagarmeRefundRequestBuilder())->partial($amountCents);
    }

    /** @return array<string,mixed> */
    private function refundableCharge(int $paymentId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT pc.id pagarme_charge_id,pc.external_charge_id,pc.amount_cents,
                    pc.paid_amount_cents,pc.refunded_amount_cents,po.external_order_id,
                    pc.status,pc.paid_at,o.code order_code,p.status payment_status,
                    p.amount_cents payment_amount_cents
             FROM pagarme_charges pc
             JOIN pagarme_orders po ON po.id=pc.pagarme_order_id AND po.payment_id=pc.payment_id
             JOIN payments p ON p.id=pc.payment_id
             JOIN orders o ON o.id=p.order_id
             WHERE pc.payment_id=? AND p.status='paid'
             ORDER BY pc.paid_at DESC,pc.id DESC"
        );
        $statement->execute([$paymentId]);
        $charges = $statement->fetchAll();
        $amount = (int) ($charges[0]['payment_amount_cents'] ?? 0);
        return (new PagarmeRefundableChargeSelector())->select($charges, $amount);
    }

    /** @return array<int,array<string,mixed>> */
    private function splitForRefund(int $paymentId, int $chargeAmountCents): array
    {
        $rules = (new FinancialSplitConsolidator($this->pdo))->forPayment($paymentId);
        $rows = array_map(static fn($rule): array => [
            'recipient_id' => $rule->recipientId,
            'split_amount_cents' => $rule->amount,
            ...$rule->options,
        ], $rules);
        return (new PagarmeRefundRequestBuilder())->full($rows, $chargeAmountCents)['split'];
    }

    /** @param array<string,mixed> $charge @param array<string,mixed> $response */
    private function processProviderResponse(array $charge, array $response): void
    {
        if (trim((string) ($response['id'] ?? '')) === '') {
            return;
        }
        $response['order'] = [
            'id' => (string) $charge['external_order_id'],
            'code' => (string) $charge['order_code'],
        ];
        $event = [
            'id' => 'refund-' . substr(hash('sha256', json_encode($response, JSON_THROW_ON_ERROR)), 0, 40),
            'type' => 'charge.updated',
            'created_at' => (string) ($response['updated_at'] ?? gmdate(DATE_ATOM)),
            'data' => $response,
        ];
        (new PagarmeWebhookProcessor($this->pdo))->process(
            json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'trusted_refund_response'
        );
    }
}
