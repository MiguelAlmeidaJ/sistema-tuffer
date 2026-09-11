<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Database;
use App\Core\Logger;
use App\Services\Payments\Pagarme\PagarmeOrderService;
use App\Services\Queue\JobProcessor;
use App\Services\Queue\JobQueue;
use PDO;
use Throwable;

final class PendingPaymentRecoveryService
{
    private readonly PDO $pdo;

    public function __construct(?PDO $database = null)
    {
        $this->pdo = $database ?? Database::connection();
    }

    /** @return array{state:string,payment_status:?string,job_status:?string} */
    public function recover(int $orderId, int $userId): array
    {
        $payment = $this->payment($orderId, $userId);
        if ($payment === null) {
            return ['state' => 'missing', 'payment_status' => null, 'job_status' => null];
        }

        if ((string) $payment['order_status'] !== 'pending_payment') {
            return [
                'state' => 'order_updated',
                'payment_status' => (string) $payment['payment_status'],
                'job_status' => $payment['job_status'] !== null ? (string) $payment['job_status'] : null,
            ];
        }

        if ($this->isReady($payment)) {
            return $this->result('ready', $payment);
        }

        $uniqueKey = $this->uniqueKey($payment);
        $queue = new JobQueue($this->pdo);

        // Um request PHP interrompido não pode deixar o cliente preso para sempre em "processing".
        // Os jobs de criação são idempotentes e as chamadas HTTP possuem timeout curto; 120s é
        // uma margem conservadora para liberar apenas reservas claramente abandonadas.
        $queue->releaseStaleByUniqueKey($uniqueKey, 120);
        $payment = $this->payment($orderId, $userId) ?? $payment;

        if ($this->isReady($payment)) {
            return $this->result('ready', $payment);
        }

        // Se a criação remota já terminou, mas a resposta local ficou incompleta, consulta o
        // pedido Pix da própria Pagar.me em vez de criar outro.
        if ((string) $payment['integration_type'] === 'orders'
            && ((string) ($payment['job_status'] ?? '') === 'completed'
                || trim((string) ($payment['external_order_id'] ?? '')) !== '')) {
            $this->recoverRemotePix($payment);
            $payment = $this->payment($orderId, $userId) ?? $payment;
            if ($this->isReady($payment)) {
                return $this->result('ready', $payment);
            }
        }

        $jobStatus = (string) ($payment['job_status'] ?? '');
        if ($jobStatus === 'failed') {
            return $this->result('failed', $payment);
        }
        if ($jobStatus === 'completed') {
            return $this->result('unavailable', $payment);
        }
        if ($jobStatus === '') {
            return $this->result('missing_job', $payment);
        }

        $job = $queue->reserveByUniqueKey(
            $uniqueKey,
            'customer-payment:' . $userId . ':' . substr(hash('sha256', session_id()), 0, 12)
        );
        if ($job === null) {
            $fresh = $this->payment($orderId, $userId) ?? $payment;
            return $this->result(
                (string) ($fresh['job_status'] ?? '') === 'failed' ? 'failed' : 'processing',
                $fresh
            );
        }

        try {
            (new JobProcessor())->process($job);
            $queue->complete((int) $job['id']);
        } catch (Throwable $exception) {
            $queue->fail($job, $exception);
            Logger::exception($exception, [
                'order_id' => $orderId,
                'payment_id' => (int) $payment['payment_id'],
                'job_id' => (int) $job['id'],
                'source' => 'customer_payment_recovery',
            ], 'payment');
        }

        $fresh = $this->payment($orderId, $userId) ?? $payment;
        if ($this->isReady($fresh)) {
            return $this->result('ready', $fresh);
        }
        if ((string) ($fresh['job_status'] ?? '') === 'failed') {
            return $this->result('failed', $fresh);
        }
        return $this->result('processing', $fresh);
    }

    /** @return array<string,mixed>|null */
    private function payment(int $orderId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id payment_id,p.method,p.status payment_status,p.integration_type,
                    p.checkout_url,p.expires_at,p.pix_qr_code,p.pix_qr_code_url,p.pix_expires_at,
                    p.external_order_id,p.amount_cents,
                    o.code order_code,o.status order_status,
                    aj.id job_id,aj.status job_status,aj.attempts job_attempts,
                    aj.max_attempts job_max_attempts,aj.available_at job_available_at,
                    aj.reserved_at job_reserved_at
             FROM orders o
             JOIN payments p ON p.order_id=o.id
             LEFT JOIN async_jobs aj ON aj.unique_key=CASE
                WHEN p.integration_type='orders' THEN CONCAT('pagarme-order:',p.id)
                ELSE CONCAT('pagarme-payment-link:',p.id)
             END
             WHERE o.id=? AND o.user_id=?
             ORDER BY p.id DESC LIMIT 1"
        );
        $statement->execute([$orderId, $userId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $payment */
    private function isReady(array $payment): bool
    {
        if (in_array((string) $payment['payment_status'], ['paid', 'partially_refunded', 'refunded'], true)) {
            return true;
        }
        $now = time();
        $pixExpires = trim((string) ($payment['pix_expires_at'] ?? ''));
        if (trim((string) ($payment['pix_qr_code'] ?? '')) !== ''
            && ($pixExpires === '' || strtotime($pixExpires) > $now)) {
            return true;
        }
        $checkoutExpires = trim((string) ($payment['expires_at'] ?? ''));
        return trim((string) ($payment['checkout_url'] ?? '')) !== ''
            && ($checkoutExpires === '' || strtotime($checkoutExpires) > $now);
    }

    /** @param array<string,mixed> $payment */
    private function uniqueKey(array $payment): string
    {
        return (string) $payment['integration_type'] === 'orders'
            ? 'pagarme-order:' . (int) $payment['payment_id']
            : 'pagarme-payment-link:' . (int) $payment['payment_id'];
    }

    /** @param array<string,mixed> $payment */
    private function recoverRemotePix(array $payment): void
    {
        try {
            $client = new PagarmeClient();
            $service = new PagarmeOrderService($client, $this->pdo);
            $externalId = trim((string) ($payment['external_order_id'] ?? ''));
            $remote = $externalId !== ''
                ? $client->get('/orders/' . rawurlencode($externalId))
                : $service->findRemoteOrderByCode(
                    (string) $payment['order_code'],
                    (int) $payment['amount_cents']
                );
            if (is_array($remote)) {
                $service->recoverRemoteOrder((int) $payment['payment_id'], $remote);
            }
        } catch (Throwable $exception) {
            Logger::exception($exception, [
                'payment_id' => (int) $payment['payment_id'],
                'source' => 'customer_payment_remote_recovery',
            ], 'payment');
        }
    }

    /** @param array<string,mixed> $payment @return array{state:string,payment_status:?string,job_status:?string} */
    private function result(string $state, array $payment): array
    {
        return [
            'state' => $state,
            'payment_status' => isset($payment['payment_status']) ? (string) $payment['payment_status'] : null,
            'job_status' => isset($payment['job_status']) ? (string) $payment['job_status'] : null,
        ];
    }
}
