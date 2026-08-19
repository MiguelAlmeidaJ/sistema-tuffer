<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use PDO;
use RuntimeException;
use Throwable;

final class PagarmeOrderAttemptCoordinator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function claim(
        int $paymentId,
        string $attemptKey,
        string $requestFingerprint
    ): string {
        $token = bin2hex(random_bytes(32));
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare(
                "INSERT INTO pagarme_order_attempts(payment_id,attempt_key,request_fingerprint,status)
                 VALUES(?,?,?,'pending')
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
            )->execute([$paymentId, $attemptKey, $requestFingerprint]);
            $statement = $this->pdo->prepare(
                'SELECT * FROM pagarme_order_attempts WHERE payment_id=? FOR UPDATE'
            );
            $statement->execute([$paymentId]);
            $attempt = $statement->fetch();
            if (!is_array($attempt)
                || !hash_equals((string) $attempt['attempt_key'], $attemptKey)
                || !hash_equals((string) $attempt['request_fingerprint'], $requestFingerprint)) {
                throw new RuntimeException('Conflito na tentativa idempotente do pagamento.');
            }
            $locked = ($attempt['status'] ?? null) === 'creating'
                && is_string($attempt['lock_expires_at'] ?? null)
                && strtotime($attempt['lock_expires_at']) > time();
            if ($locked) {
                throw new RuntimeException('Este pagamento já está sendo criado. Aguarde a conclusão.');
            }
            $this->pdo->prepare(
                "UPDATE pagarme_order_attempts
                 SET status='creating',lock_token=?,lock_expires_at=DATE_ADD(NOW(),INTERVAL 2 MINUTE),
                     attempt_count=attempt_count+1,last_attempt_at=NOW(),last_error=NULL
                 WHERE payment_id=?"
            )->execute([$token, $paymentId]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $token;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function markCreated(int $paymentId, string $token, string $externalOrderId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE pagarme_order_attempts
             SET status='created',external_order_id=?,lock_token=NULL,lock_expires_at=NULL,last_error=NULL
             WHERE payment_id=? AND lock_token=?"
        );
        $statement->execute([$externalOrderId, $paymentId, $token]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('A trava da tentativa Pagar.me expirou antes da persistência.');
        }
    }

    public function markUncertain(
        int $paymentId,
        string $token,
        Throwable $exception,
        ?string $externalOrderId = null
    ): void {
        $this->finish($paymentId, $token, 'uncertain', $exception, $externalOrderId);
    }

    public function markFailed(int $paymentId, string $token, Throwable $exception): void
    {
        $this->finish($paymentId, $token, 'failed', $exception);
    }

    public function markRecovered(int $paymentId, string $externalOrderId): void
    {
        $this->pdo->prepare(
            "UPDATE pagarme_order_attempts
             SET status='created',external_order_id=?,lock_token=NULL,lock_expires_at=NULL,last_error=NULL
             WHERE payment_id=?"
        )->execute([$externalOrderId, $paymentId]);
    }

    private function finish(
        int $paymentId,
        string $token,
        string $status,
        Throwable $exception,
        ?string $externalOrderId = null
    ): void {
        $safeMessage = mb_substr(strip_tags($exception->getMessage()), 0, 500);
        $this->pdo->prepare(
            "UPDATE pagarme_order_attempts
             SET status=?,external_order_id=COALESCE(?,external_order_id),
                 lock_token=NULL,lock_expires_at=NULL,last_error=?
             WHERE payment_id=? AND lock_token=?"
        )->execute([$status, $externalOrderId, $safeMessage, $paymentId, $token]);
    }
}
