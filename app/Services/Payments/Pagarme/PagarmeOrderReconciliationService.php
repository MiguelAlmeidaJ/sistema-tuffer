<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use App\Core\Database;
use App\Core\Logger;
use App\Services\Payments\PagarmeApiClient;
use App\Services\Payments\PagarmeClient;
use App\Services\Payments\PagarmeWebhookProcessor;
use PDO;
use Throwable;

final class PagarmeOrderReconciliationService
{
    private readonly PDO $pdo;
    private readonly PagarmeApiClient $client;
    private readonly PagarmePayloadSanitizer $sanitizer;

    public function __construct(?PagarmeApiClient $client = null, ?PDO $database = null)
    {
        $this->pdo = $database ?? Database::connection();
        $this->client = $client ?? new PagarmeClient();
        $this->sanitizer = new PagarmePayloadSanitizer();
    }

    /** @return array{run_id:int,checked:int,recovered:int,updated:int,divergences:int,errors:int} */
    public function reconcilePending(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $this->pdo->prepare(
            "INSERT INTO pagarme_reconciliation_runs(environment,status) VALUES(?,'running')"
        )->execute([$this->client->environment()]);
        $runId = (int) $this->pdo->lastInsertId();
        $result = ['run_id' => $runId, 'checked' => 0, 'recovered' => 0, 'updated' => 0, 'divergences' => 0, 'errors' => 0];

        try {
            $statement = $this->pdo->query(
                "SELECT p.id payment_id,p.status local_status,p.amount_cents,o.code order_code,
                        po.external_order_id,pa.status attempt_status
                 FROM payments p
                 JOIN orders o ON o.id=p.order_id
                 LEFT JOIN pagarme_orders po ON po.payment_id=p.id
                 LEFT JOIN pagarme_order_attempts pa ON pa.payment_id=p.id
                 WHERE p.provider='pagarme' AND p.integration_type='orders' AND p.method='pix'
                   AND (
                       p.status IN ('pending','waiting_payment','processing','failed','paid','partially_refunded')
                       OR pa.status IN ('creating','uncertain')
                   )
                 ORDER BY COALESCE(pa.last_attempt_at,p.updated_at) ASC,p.id ASC
                 LIMIT {$limit}"
            );
            foreach ($statement->fetchAll() as $payment) {
                $result['checked']++;
                try {
                    $remote = $this->remoteOrder($payment);
                    if ($remote === null) {
                        $this->divergence($runId, $payment, 'remote_order_not_found', null, [
                            'attempt_status' => $payment['attempt_status'] ?? null,
                        ]);
                        $result['divergences']++;
                        continue;
                    }
                    $remoteId = trim((string) ($remote['id'] ?? ''));
                    if (!hash_equals((string) $payment['order_code'], (string) ($remote['code'] ?? ''))
                        || (int) ($remote['amount'] ?? -1) !== (int) $payment['amount_cents']) {
                        $this->divergence($runId, $payment, 'remote_order_mismatch', $remote, [
                            'local_amount_cents' => (int) $payment['amount_cents'],
                            'remote_amount_cents' => (int) ($remote['amount'] ?? -1),
                        ]);
                        $result['divergences']++;
                        continue;
                    }
                    $remoteSplit = $this->remoteSplit($remote);
                    if ($remoteSplit !== null) {
                        $localSplit = $this->localSplit((int) $payment['payment_id']);
                        if ($localSplit !== $remoteSplit) {
                            $this->divergence($runId, $payment, 'remote_split_mismatch', $remote, [
                                'local_split' => $localSplit,
                                'remote_split' => $remoteSplit,
                            ]);
                            $result['divergences']++;
                        }
                    }

                    $orderService = new PagarmeOrderService($this->client, $this->pdo);
                    $wasMissing = trim((string) ($payment['external_order_id'] ?? '')) === '';
                    $orderService->recoverRemoteOrder((int) $payment['payment_id'], $remote);
                    (new PagarmeOrderAttemptCoordinator($this->pdo))
                        ->markRecovered((int) $payment['payment_id'], $remoteId);
                    if ($wasMissing) {
                        $result['recovered']++;
                    }
                    $this->applyRemoteCharges($remote);
                    $result['updated']++;

                    $localStatus = $this->paymentStatus((int) $payment['payment_id']);
                    $expected = $this->expectedPaymentStatus($remote);
                    if ($expected !== null && !$this->statusCompatible($localStatus, $expected)) {
                        $this->divergence($runId, $payment, 'status_mismatch', $remote, [
                            'status_after_sync' => $localStatus,
                            'expected_status' => $expected,
                        ]);
                        $result['divergences']++;
                    }
                } catch (Throwable $exception) {
                    $result['errors']++;
                    $this->divergence($runId, $payment, 'reconciliation_error', null, [
                        'error' => mb_substr(strip_tags($exception->getMessage()), 0, 240),
                    ]);
                    $result['divergences']++;
                    Logger::exception($exception, [
                        'payment_id' => (int) $payment['payment_id'],
                        'run_id' => $runId,
                    ], 'pagarme_reconciliation');
                }
            }
            $status = $result['errors'] > 0 ? 'completed_with_errors' : 'completed';
            $this->finishRun($result, $status);
        } catch (Throwable $exception) {
            $result['errors']++;
            $this->finishRun($result, 'failed');
            throw $exception;
        }

        Logger::info('Reconciliação Pagar.me concluída.', $result, 'pagarme_reconciliation');
        return $result;
    }

    /** @param array<string,mixed> $payment @return array<string,mixed>|null */
    private function remoteOrder(array $payment): ?array
    {
        $externalOrderId = trim((string) ($payment['external_order_id'] ?? ''));
        if ($externalOrderId !== '') {
            return $this->client->get('/orders/' . rawurlencode($externalOrderId));
        }
        return (new PagarmeRemoteOrderLocator($this->client))->byCode(
            (string) $payment['order_code'],
            (int) $payment['amount_cents']
        );
    }

    /** @return array<string,int>|null */
    private function remoteSplit(array $remote): ?array
    {
        foreach (is_array($remote['charges'] ?? null) ? $remote['charges'] : [] as $charge) {
            if (!is_array($charge)) continue;
            $candidates = [
                $charge['split'] ?? null,
                is_array($charge['last_transaction'] ?? null) ? ($charge['last_transaction']['split'] ?? null) : null,
            ];
            foreach ($candidates as $candidate) {
                if (!is_array($candidate) || $candidate === []) continue;
                $totals = [];
                foreach ($candidate as $rule) {
                    if (!is_array($rule)) continue;
                    $recipientId = trim((string) ($rule['recipient_id'] ?? ''));
                    $amount = (int) ($rule['amount'] ?? 0);
                    if (!PagarmeRecipientId::isValid($recipientId) || $amount < 1) {
                        return [];
                    }
                    $totals[$recipientId] = ($totals[$recipientId] ?? 0) + $amount;
                }
                ksort($totals);
                return $totals;
            }
        }
        return null;
    }

    /** @return array<string,int> */
    private function localSplit(int $paymentId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT recipient_id,SUM(CASE WHEN direction='credit' THEN amount_cents ELSE -amount_cents END) amount
             FROM financial_entries
             WHERE payment_id=? AND is_split_component=1 AND status IN ('pending','confirmed')
             GROUP BY recipient_id"
        );
        $statement->execute([$paymentId]);
        $totals = [];
        foreach ($statement->fetchAll() as $row) {
            $totals[(string) $row['recipient_id']] = (int) $row['amount'];
        }
        ksort($totals);
        return $totals;
    }

    /** @param array<string,mixed> $remote */
    private function applyRemoteCharges(array $remote): void
    {
        $orderId = trim((string) ($remote['id'] ?? ''));
        $orderCode = trim((string) ($remote['code'] ?? ''));
        $updatedAt = (string) ($remote['updated_at'] ?? $remote['created_at'] ?? gmdate(DATE_ATOM));
        foreach (is_array($remote['charges'] ?? null) ? $remote['charges'] : [] as $charge) {
            if (!is_array($charge) || trim((string) ($charge['id'] ?? '')) === '') {
                continue;
            }
            $charge['order'] = ['id' => $orderId, 'code' => $orderCode];
            $status = strtolower(trim((string) ($charge['status'] ?? '')));
            if ($status === 'refunded' && (int) ($charge['paid_amount'] ?? 0) > 0) {
                $paid = $charge;
                $paid['status'] = 'paid';
                $this->processSnapshot($paid, $updatedAt, 'paid-before-refund');
            }
            $this->processSnapshot($charge, $updatedAt, $status ?: 'updated');
        }
    }

    /** @param array<string,mixed> $charge */
    private function processSnapshot(array $charge, string $updatedAt, string $suffix): void
    {
        $chargeId = (string) ($charge['id'] ?? '');
        $event = [
            'id' => 'reconcile-' . substr(hash('sha256', $chargeId . '|' . $suffix . '|' . $updatedAt), 0, 40),
            'type' => 'charge.updated',
            'created_at' => $updatedAt,
            'data' => $charge,
        ];
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        (new PagarmeWebhookProcessor($this->pdo))->process($body, 'trusted_reconciliation_get');
    }

    /** @param array<string,mixed> $remote */
    private function expectedPaymentStatus(array $remote): ?string
    {
        $statuses = [];
        foreach (is_array($remote['charges'] ?? null) ? $remote['charges'] : [] as $charge) {
            if (is_array($charge)) {
                $statuses[] = strtolower((string) ($charge['status'] ?? ''));
            }
        }
        foreach (['refunded', 'paid', 'expired', 'failed', 'payment_failed', 'processing', 'pending'] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status === 'payment_failed' ? 'failed' : $status;
            }
        }
        return null;
    }

    private function statusCompatible(string $local, string $expected): bool
    {
        return $local === $expected
            || ($expected === 'pending' && $local === 'waiting_payment')
            || ($expected === 'refunded' && $local === 'partially_refunded');
    }

    private function paymentStatus(int $paymentId): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM payments WHERE id=?');
        $statement->execute([$paymentId]);
        return (string) ($statement->fetchColumn() ?: '');
    }

    /** @param array<string,mixed> $payment @param array<string,mixed>|null $remote @param array<string,mixed> $details */
    private function divergence(int $runId, array $payment, string $type, ?array $remote, array $details): void
    {
        $safe = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $charges = is_array($remote['charges'] ?? null) ? $remote['charges'] : [];
        $firstCharge = is_array($charges[0] ?? null) ? $charges[0] : [];
        $this->pdo->prepare(
            'INSERT INTO pagarme_reconciliation_divergences(
                reconciliation_run_id,payment_id,external_order_id,external_charge_id,
                divergence_type,local_status,remote_status,safe_details
             ) VALUES(?,?,?,?,?,?,?,?)'
        )->execute([
            $runId,
            (int) $payment['payment_id'],
            $remote['id'] ?? $payment['external_order_id'] ?? null,
            $firstCharge['id'] ?? null,
            $type,
            $payment['local_status'] ?? null,
            $firstCharge['status'] ?? $remote['status'] ?? null,
            $safe,
        ]);
    }

    /** @param array{run_id:int,checked:int,recovered:int,updated:int,divergences:int,errors:int} $result */
    private function finishRun(array $result, string $status): void
    {
        $this->pdo->prepare(
            'UPDATE pagarme_reconciliation_runs
             SET status=?,checked_count=?,recovered_count=?,updated_count=?,
                 divergence_count=?,error_count=?,finished_at=NOW()
             WHERE id=?'
        )->execute([
            $status,
            $result['checked'],
            $result['recovered'],
            $result['updated'],
            $result['divergences'],
            $result['errors'],
            $result['run_id'],
        ]);
    }
}
