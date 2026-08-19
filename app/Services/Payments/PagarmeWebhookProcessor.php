<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Database;
use App\Core\Logger;
use App\Services\Queue\JobQueue;
use App\Services\Payments\Pagarme\PagarmePlatformAccountService;
use App\Services\Payments\Pagarme\PagarmeRecipientId;
use App\Services\Payments\Pagarme\PagarmeRecipientService;
use App\Services\Payments\Pagarme\PagarmePayloadSanitizer;
use App\Services\Payments\Pagarme\PagarmeWebhookEventClassifier;
use App\Services\Payments\Pagarme\PagarmeWebhookIdempotency;
use App\Services\Payments\Pagarme\PagarmeEventFreshnessGuard;
use App\Services\Finance\MarketplaceFinancialLedgerService;
use App\Services\Mail\QueuedMailService;
use App\Services\Settings\PlatformSettings;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PDOException;
use Throwable;
use App\Services\Orders\OrderCouponService;
use App\Services\Orders\OrderInventoryService;

final class PagarmeWebhookProcessor
{
    private const ROOT_SAVEPOINT = 'pagarme_webhook';
    private const EFFECTS_SAVEPOINT = 'pagarme_webhook_effects';

    public function __construct(private readonly ?PDO $database = null)
    {
    }

    /** @return array{status:string,event_id:string,event_type:string,order_code:?string,webhook_id:int} */
    public function receive(string $rawBody, string $signatureAlgorithm): array
    {
        $event = $this->decodeEvent($rawBody);
        $eventId = trim((string) $event['id']);
        $eventType = trim((string) ($event['type'] ?? $event['event']));
        $storedPayload = $this->payloadForStorage($event, $rawBody);
        $pdo = $this->database ?? Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $payloadHash = hash('sha256', $rawBody);
            $webhook = $this->upsertAndLockWebhook($pdo, $eventId, $eventType, $storedPayload, $payloadHash, $signatureAlgorithm, $this->eventDate($event['created_at'] ?? null), true);
            $idempotency = new PagarmeWebhookIdempotency();
            try {
                $idempotency->assertPayload((string) $webhook['payload_sha256'], $payloadHash);
            } catch (PagarmeWebhookException $exception) {
                $this->markWebhook($pdo, (int) $webhook['id'], 'failed', 'O mesmo event_id foi recebido com payload diferente.');
                if ($ownsTransaction) $pdo->commit();
                throw $exception;
            }
            if ($idempotency->alreadyHandled((string) $webhook['status'])) {
                if ($ownsTransaction) $pdo->commit();
                return ['status' => 'duplicate', 'event_id' => $eventId, 'event_type' => $eventType, 'order_code' => null, 'webhook_id' => (int) $webhook['id']];
            }
            $pdo->prepare("UPDATE payment_webhooks SET status='pending',error_message=NULL,processed_at=NULL WHERE id=?")->execute([$webhook['id']]);
            (new JobQueue($pdo))->dispatch('pagarme.process_webhook', ['webhook_id' => (int) $webhook['id']], 'pagarme-webhook:' . $webhook['id'] . ':' . $payloadHash, 'webhook', 8, 5);
            if ($ownsTransaction) $pdo->commit();
            return ['status' => 'queued', 'event_id' => $eventId, 'event_type' => $eventType, 'order_code' => null, 'webhook_id' => (int) $webhook['id']];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            if ($exception instanceof PagarmeWebhookException) throw $exception;
            throw new PagarmeWebhookException('Falha temporária ao registrar o webhook.', 500, $exception);
        }
    }

    /** @return array{status:string,event_id:string,event_type:string,order_code:?string} */
    public function processStored(int $webhookId): array
    {
        $statement = ($this->database ?? Database::connection())->prepare('SELECT payload,payload_sha256,signature_algorithm FROM payment_webhooks WHERE id=?');
        $statement->execute([$webhookId]);
        $webhook = $statement->fetch();
        if (!is_array($webhook)) throw new PagarmeWebhookException('Webhook persistido não encontrado.', 404);
        return $this->process((string) $webhook['payload'], (string) $webhook['signature_algorithm'], false, (string) $webhook['payload_sha256']);
    }

    /** @return array{status:string,event_id:string,event_type:string,order_code:?string} */
    public function process(string $rawBody, string $signatureAlgorithm, bool $countDelivery = true, ?string $knownPayloadHash = null): array
    {
        $event = $this->decodeEvent($rawBody);
        $eventId = trim((string) $event['id']);
        $eventType = trim((string) ($event['type'] ?? $event['event']));
        $data = $event['data'];
        $storedPayload = $this->payloadForStorage($event, $rawBody);

        $pdo = $this->database ?? Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        $payloadHash = $knownPayloadHash ?? hash('sha256', $rawBody);
        $eventCreatedAt = $this->eventDate($event['created_at'] ?? null);
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::ROOT_SAVEPOINT);
        }

        try {
            $webhook = $this->upsertAndLockWebhook($pdo, $eventId, $eventType, $storedPayload, $payloadHash, $signatureAlgorithm, $eventCreatedAt, $countDelivery);
            $idempotency = new PagarmeWebhookIdempotency();
            try {
                $idempotency->assertPayload((string) $webhook['payload_sha256'], $payloadHash);
            } catch (PagarmeWebhookException $exception) {
                $this->markWebhook($pdo, (int) $webhook['id'], 'failed', 'O mesmo event_id foi recebido com payload diferente.');
                $this->finishTransaction($pdo, $ownsTransaction);
                throw $exception;
            }
            if ($idempotency->alreadyHandled((string) $webhook['status'])) {
                $this->finishTransaction($pdo, $ownsTransaction);
                return ['status' => 'duplicate', 'event_id' => $eventId, 'event_type' => $eventType, 'order_code' => null];
            }

            $pdo->exec('SAVEPOINT ' . self::EFFECTS_SAVEPOINT);
            try {
                $outcome = $this->applyEvent($pdo, $eventType, $data, $eventCreatedAt);
                $status = $outcome['status'] === 'ignored' ? 'ignored' : 'processed';
                $this->markWebhook($pdo, (int) $webhook['id'], $status, null, $outcome['order_id'], $outcome['payment_id']);
                $pdo->exec('RELEASE SAVEPOINT ' . self::EFFECTS_SAVEPOINT);
                $this->finishTransaction($pdo, $ownsTransaction);
                return ['status' => $outcome['status'], 'event_id' => $eventId, 'event_type' => $eventType, 'order_code' => $outcome['order_code']];
            } catch (Throwable $exception) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::EFFECTS_SAVEPOINT);
                $safeMessage = $exception instanceof PagarmeWebhookException ? $exception->getMessage() : 'Falha interna ao processar o webhook.';
                $this->markWebhook($pdo, (int) $webhook['id'], 'failed', $safeMessage);
                $this->finishTransaction($pdo, $ownsTransaction);
                if ($exception instanceof PagarmeWebhookException) {
                    throw $exception;
                }
                throw new PagarmeWebhookException('Falha interna ao processar o webhook.', 500, $exception);
            }
        } catch (PagarmeWebhookException $exception) {
            if ($pdo->inTransaction() && !$ownsTransaction) {
                try { $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::ROOT_SAVEPOINT); } catch (Throwable) {}
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction() && $ownsTransaction) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                try { $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::ROOT_SAVEPOINT); } catch (Throwable) {}
            }
            throw new PagarmeWebhookException('Falha temporária ao registrar o webhook.', 500, $exception);
        }
    }

    /** @return array<string,mixed> */
    private function upsertAndLockWebhook(PDO $pdo, string $eventId, string $eventType, string $payload, string $hash, string $algorithm, ?string $eventCreatedAt, bool $countDelivery = true): array
    {
        $duplicate = $countDelivery ? 'id=LAST_INSERT_ID(id),delivery_count=delivery_count+1,last_received_at=NOW()' : 'id=LAST_INSERT_ID(id)';
        $statement = $pdo->prepare("INSERT INTO payment_webhooks(provider_event_id,event_type,payload,payload_sha256,signature_algorithm,signature_validated_at,event_created_at,status,delivery_count,last_received_at) VALUES(?,?,?,?,?,NOW(),?,'pending',1,NOW()) ON DUPLICATE KEY UPDATE {$duplicate}");
        $statement->execute([$eventId, $eventType, $payload, $hash, $algorithm, $eventCreatedAt]);
        $id = (int) $pdo->lastInsertId();
        $select = $pdo->prepare('SELECT * FROM payment_webhooks WHERE id=? FOR UPDATE');
        $select->execute([$id]);
        $webhook = $select->fetch();
        if (!is_array($webhook)) {
            throw new PagarmeWebhookException('Não foi possível registrar o webhook.', 500);
        }
        return $webhook;
    }

    /** @return array<string,mixed> */
    private function decodeEvent(string $rawBody): array
    {
        if (strlen($rawBody) > 1_048_576) throw new PagarmeWebhookException('Payload do webhook excede o limite permitido.', 413);
        try {
            $event = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PagarmeWebhookException('Payload JSON do webhook é inválido.', 400, $exception);
        }
        if (!is_array($event)) throw new PagarmeWebhookException('Payload do webhook é inválido.', 400);
        $eventId = trim((string) ($event['id'] ?? ''));
        $eventType = trim((string) ($event['type'] ?? $event['event'] ?? ''));
        if ($eventId === '' || strlen($eventId) > 150 || $eventType === '' || strlen($eventType) > 100) throw new PagarmeWebhookException('Webhook sem identificador ou tipo válido.', 400);
        if (!is_array($event['data'] ?? null)) throw new PagarmeWebhookException('Webhook sem objeto data válido.', 400);
        return $event;
    }

    /** @param array<string,mixed> $event */
    private function payloadForStorage(array $event, string $rawBody): string
    {
        try {
            return json_encode(
                (new PagarmePayloadSanitizer())->webhookEvent($event),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            throw new PagarmeWebhookException('Não foi possível sanitizar o webhook da Pagar.me.', 500);
        }
    }

    /** @return array{status:string,order_id:?int,payment_id:?int,order_code:?string} */
    private function applyEvent(PDO $pdo, string $eventType, array $data, ?string $eventCreatedAt): array
    {
        $supported = [
            'recipient.created', 'recipient.updated',
            'order.created', 'order.create', 'order.updated', 'order.closed',
            'checkout.created', 'checkout.closed',
            'charge.created', 'charge.updated', 'charge.pending', 'charge.processing',
            'order.paid', 'charge.paid',
            'order.payment_failed', 'charge.payment_failed',
            'order.canceled', 'checkout.canceled',
            'charge.refunded', 'charge.chargedback', 'chargeback.received',
        ];
        if (!in_array($eventType, $supported, true)) {
            return ['status' => 'ignored', 'order_id' => null, 'payment_id' => null, 'order_code' => null];
        }
        if (in_array($eventType, ['recipient.created', 'recipient.updated'], true)) {
            $recipientId = trim((string) ($data['id'] ?? ''));
            if (!PagarmeRecipientId::isValid($recipientId)) {
                throw new PagarmeWebhookException('Webhook de recebedor sem identificador válido.', 422);
            }
            $recipientService = new PagarmeRecipientService(null, $pdo);
            $platformService = new PagarmePlatformAccountService(null, $pdo);
            $platformAccount = $platformService->account();
            $configuredPlatformRecipient = trim((string) ($_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? ''));
            if ((is_array($platformAccount)
                    && hash_equals((string) ($platformAccount['recipient_id'] ?? ''), $recipientId))
                || (PagarmeRecipientId::isValid($configuredPlatformRecipient)
                    && hash_equals($configuredPlatformRecipient, $recipientId))) {
                $platformService->synchronize();
                return ['status' => 'processed', 'order_id' => null, 'payment_id' => null, 'order_code' => null];
            }
            $account = $pdo->prepare("SELECT seller_id FROM seller_payment_accounts WHERE provider='pagarme' AND environment=? AND recipient_id=? LIMIT 1");
            $account->execute([$recipientService->environment(), $recipientId]);
            if (!(int) $account->fetchColumn()) {
                Logger::warning('Recebedor Pagar.me não localizado.', ['recipient_id' => $recipientId], 'pagarme_webhook');
                return ['status' => 'ignored', 'order_id' => null, 'payment_id' => null, 'order_code' => null];
            }
            $recipientService->synchronizeByRecipientId($recipientId);
            return ['status' => 'processed', 'order_id' => null, 'payment_id' => null, 'order_code' => null];
        }

        $payment = $this->findPayment($pdo, $eventType, $data);
        if ($payment === null) {
            throw new PagarmeWebhookException('Pagamento local não encontrado para o webhook.', 422);
        }
        $orderId = (int) $payment['order_id'];
        $paymentId = (int) $payment['id'];
        $orderCode = (string) $payment['order_code'];

        if (str_starts_with($eventType, 'charge.')
            && $this->staleChargeEvent($pdo, $data, $eventCreatedAt)) {
            return ['status' => 'ignored', 'order_id' => $orderId, 'payment_id' => $paymentId, 'order_code' => $orderCode];
        }
        if (!str_starts_with($eventType, 'charge.')
            && $eventCreatedAt !== null
            && $payment['last_event_at'] !== null
            && strtotime($eventCreatedAt) < strtotime((string) $payment['last_event_at'])) {
            return ['status' => 'ignored', 'order_id' => $orderId, 'payment_id' => $paymentId, 'order_code' => $orderCode];
        }

        [$externalOrderId, $externalChargeId, $externalCheckoutId] = $this->externalIds($eventType, $data);
        $this->bindExternalIds($pdo, $payment, $externalOrderId, $externalChargeId, $externalCheckoutId);
        $this->persistProviderRecords($pdo, $payment, $eventType, $data, $eventCreatedAt);

        $providerStatus = strtolower((string) ($data['status'] ?? ''));
        $classification = (new PagarmeWebhookEventClassifier())->classify($eventType, $providerStatus);
        if ($classification === 'expired') {
            $outcome = $this->applyExpiration($pdo, $payment, $eventCreatedAt);
            (new MarketplaceFinancialLedgerService($pdo))->voidPending($paymentId);
            return $outcome;
        }
        if ($classification === 'paid') {
            $outcome = $this->applyPaid($pdo, $payment, $data, $eventCreatedAt);
            if ($externalChargeId !== null) {
                (new MarketplaceFinancialLedgerService($pdo))->confirm($paymentId, $externalChargeId, $eventCreatedAt);
            }
            return $outcome;
        }
        if ($classification === 'failed') {
            $outcome = $this->applyFailure($pdo, $payment, $eventCreatedAt);
            (new MarketplaceFinancialLedgerService($pdo))->voidPending($paymentId);
            return $outcome;
        }
        if ($classification === 'cancelled') {
            $outcome = $this->applyCancellation($pdo, $payment, $eventCreatedAt);
            (new MarketplaceFinancialLedgerService($pdo))->voidPending($paymentId);
            return $outcome;
        }
        if ($classification === 'refunded') {
            $outcome = $this->applyRefund($pdo, $payment, $data, $eventType, $eventCreatedAt);
            $status = $pdo->prepare('SELECT status FROM payments WHERE id=?');
            $status->execute([$paymentId]);
            if ($status->fetchColumn() === 'refunded' && $externalChargeId !== null) {
                (new MarketplaceFinancialLedgerService($pdo))->reverse(
                    $paymentId,
                    $externalChargeId,
                    str_contains($eventType, 'chargeback') ? 'chargeback' : 'refund',
                    $eventCreatedAt
                );
            }
            return $outcome;
        }
        if (in_array($eventType, ['order.created', 'order.create', 'order.updated', 'order.closed', 'checkout.created', 'checkout.closed'], true)) {
            $this->touchPaymentEvent($pdo, $paymentId, $eventCreatedAt);
            return ['status' => 'processed', 'order_id' => $orderId, 'payment_id' => $paymentId, 'order_code' => $orderCode];
        }
        if ($classification === 'waiting_payment') {
            $pdo->prepare(
                "UPDATE payments SET status='waiting_payment',last_event_at=COALESCE(?,last_event_at)
                 WHERE id=? AND status IN ('pending','waiting_payment','processing','failed')"
            )->execute([$eventCreatedAt, $paymentId]);
        } elseif ($classification === 'processing') {
            $pdo->prepare(
                "UPDATE payments SET status='processing',last_event_at=COALESCE(?,last_event_at)
                 WHERE id=? AND status IN ('pending','waiting_payment','processing','failed')"
            )->execute([$eventCreatedAt, $paymentId]);
        }
        $this->touchPaymentEvent($pdo, $paymentId, $eventCreatedAt);
        return ['status' => 'processed', 'order_id' => $orderId, 'payment_id' => $paymentId, 'order_code' => $orderCode];
    }

    /** @return array<string,mixed>|null */
    private function findPayment(PDO $pdo, string $eventType, array $data): ?array
    {
        $orderCode = null;
        if (str_starts_with($eventType, 'order.')) {
            $orderCode = $this->stringAt($data, ['code']);
        }
        $orderCode ??= $this->firstString($data, [
            ['order', 'code'], ['order_code'], ['checkout', 'order_code'], ['metadata', 'order_code'],
        ]);
        if ($orderCode !== null) {
            $statement = $pdo->prepare('SELECT p.*,o.code order_code,o.status order_status,o.user_id FROM payments p JOIN orders o ON o.id=p.order_id WHERE o.code=? ORDER BY p.id DESC LIMIT 1 FOR UPDATE');
            $statement->execute([$orderCode]);
            $payment = $statement->fetch();
            if (is_array($payment)) return $payment;
        }

        [$orderId, $chargeId, $checkoutId] = $this->externalIds($eventType, $data);
        foreach ([
            [
                'SELECT p.*,o.code order_code,o.status order_status,o.user_id
                 FROM pagarme_orders po
                 JOIN payments p ON p.id=po.payment_id
                 JOIN orders o ON o.id=p.order_id
                 WHERE po.external_order_id=? LIMIT 1 FOR UPDATE',
                $orderId,
            ],
            [
                'SELECT p.*,o.code order_code,o.status order_status,o.user_id
                 FROM pagarme_charges pc
                 JOIN payments p ON p.id=pc.payment_id
                 JOIN orders o ON o.id=p.order_id
                 WHERE pc.external_charge_id=? LIMIT 1 FOR UPDATE',
                $chargeId,
            ],
        ] as [$sql, $value]) {
            if ($value === null) continue;
            $statement = $pdo->prepare($sql);
            $statement->execute([$value]);
            $payment = $statement->fetch();
            if (is_array($payment)) return $payment;
        }
        foreach ([['external_order_id', $orderId], ['external_charge_id', $chargeId], ['external_checkout_id', $checkoutId]] as [$column, $value]) {
            if ($value === null) continue;
            $statement = $pdo->prepare("SELECT p.*,o.code order_code,o.status order_status,o.user_id FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.{$column}=? ORDER BY p.id DESC LIMIT 1 FOR UPDATE");
            $statement->execute([$value]);
            $payment = $statement->fetch();
            if (is_array($payment)) return $payment;
        }
        return null;
    }

    /** @param array<string,mixed> $data */
    private function staleChargeEvent(PDO $pdo, array $data, ?string $eventCreatedAt): bool
    {
        $chargeId = $this->stringAt($data, ['id'])
            ?? $this->stringAt($data, ['charge_id'])
            ?? $this->stringAt($data, ['charge', 'id']);
        if ($chargeId === null) {
            return false;
        }
        $statement = $pdo->prepare(
            'SELECT last_event_at FROM pagarme_charges WHERE external_charge_id=? LIMIT 1'
        );
        $statement->execute([$chargeId]);
        $persistedAt = $statement->fetchColumn();
        return (new PagarmeEventFreshnessGuard())->isStale(
            $eventCreatedAt,
            is_string($persistedAt) ? $persistedAt : null
        );
    }

    /** @return array{?string,?string,?string} */
    private function externalIds(string $eventType, array $data): array
    {
        $orderId = str_starts_with($eventType, 'order.') ? $this->stringAt($data, ['id']) : null;
        $orderId ??= $this->firstString($data, [['order', 'id'], ['invoice', 'order', 'id']]);
        $chargeId = str_starts_with($eventType, 'charge.') ? $this->stringAt($data, ['id']) : null;
        $chargeId ??= $this->firstString($data, [
            ['charges', 0, 'id'], ['charge', 'id'], ['charge_id'], ['chargeback', 'charge_id'],
        ]);
        $checkoutId = str_starts_with($eventType, 'checkout.') ? $this->stringAt($data, ['id']) : null;
        $checkoutId ??= $this->firstString($data, [['payment_link', 'id'], ['checkout', 'id']]);
        return [$orderId, $chargeId, $checkoutId];
    }

    /** @param array<string,mixed> $payment */
    private function bindExternalIds(PDO $pdo, array $payment, ?string $orderId, ?string $chargeId, ?string $checkoutId): void
    {
        if ($orderId !== null && $payment['external_order_id'] !== null && !hash_equals((string) $payment['external_order_id'], $orderId)) {
            throw new PagarmeWebhookException('O identificador externo do pedido diverge do pagamento local.', 409);
        }
        $statement = $pdo->prepare('UPDATE payments SET external_order_id=COALESCE(external_order_id,?),external_charge_id=COALESCE(external_charge_id,?),external_checkout_id=COALESCE(external_checkout_id,?) WHERE id=?');
        $statement->execute([$orderId, $chargeId, $checkoutId, $payment['id']]);
    }

    /** @param array<string,mixed> $payment @param array<string,mixed> $data */
    private function persistProviderRecords(
        PDO $pdo,
        array $payment,
        string $eventType,
        array $data,
        ?string $eventCreatedAt
    ): void {
        [$externalOrderId] = $this->externalIds($eventType, $data);
        $providerOrder = $pdo->prepare(
            'SELECT id,payment_id,external_order_id FROM pagarme_orders WHERE payment_id=? LIMIT 1 FOR UPDATE'
        );
        $providerOrder->execute([$payment['id']]);
        $order = $providerOrder->fetch();

        if (!is_array($order) && $externalOrderId !== null) {
            $pdo->prepare(
                'INSERT INTO pagarme_orders(payment_id,external_order_id,idempotency_key,status,amount_cents)
                 VALUES(?,?,?,?,?)'
            )->execute([
                $payment['id'],
                $externalOrderId,
                (string) ($payment['idempotency_key'] ?? 'webhook-payment-' . $payment['id']),
                $this->stringAt($data, ['status']),
                (int) ($payment['amount_cents'] ?? $this->moneyCents($payment['amount'])),
            ]);
            $order = [
                'id' => (int) $pdo->lastInsertId(),
                'payment_id' => (int) $payment['id'],
                'external_order_id' => $externalOrderId,
            ];
        }
        if (!is_array($order)) {
            return;
        }
        if ((int) $order['payment_id'] !== (int) $payment['id']
            || ($externalOrderId !== null && !hash_equals((string) $order['external_order_id'], $externalOrderId))) {
            throw new PagarmeWebhookException('O order_id da cobrança diverge do pagamento local.', 409);
        }
        if ($externalOrderId !== null) {
            $pdo->prepare('UPDATE pagarme_orders SET status=COALESCE(?,status) WHERE id=?')
                ->execute([$this->stringAt($data, ['status']), $order['id']]);
        }

        $charges = [];
        if (str_starts_with($eventType, 'charge.')) {
            $charges[] = $data;
        } elseif (is_array($data['charge'] ?? null)) {
            $charges[] = $data['charge'];
        } elseif ($eventType === 'chargeback.received') {
            $chargebackId = $this->firstString($data, [['charge_id'], ['chargeback', 'charge_id']]);
            if ($chargebackId !== null) {
                $charges[] = [
                    'id' => $chargebackId,
                    'status' => 'chargedback',
                    'refunded_amount' => (int) ($payment['amount_cents'] ?? $this->moneyCents($payment['amount'])),
                ];
            }
        }
        foreach (is_array($data['charges'] ?? null) ? $data['charges'] : [] as $charge) {
            if (is_array($charge)) {
                $charges[] = $charge;
            }
        }
        foreach ($charges as $charge) {
            $this->upsertCharge($pdo, (int) $order['id'], (int) $payment['id'], $charge, $eventCreatedAt);
        }
    }

    /** @param array<string,mixed> $charge */
    private function upsertCharge(
        PDO $pdo,
        int $providerOrderId,
        int $paymentId,
        array $charge,
        ?string $eventCreatedAt
    ): void {
        $chargeId = trim((string) ($charge['id'] ?? ''));
        if ($chargeId === '') {
            return;
        }
        $existing = $pdo->prepare(
            'SELECT payment_id,pagarme_order_id,last_event_at FROM pagarme_charges WHERE external_charge_id=? FOR UPDATE'
        );
        $existing->execute([$chargeId]);
        $persisted = $existing->fetch();
        if (is_array($persisted)
            && ((int) $persisted['payment_id'] !== $paymentId || (int) $persisted['pagarme_order_id'] !== $providerOrderId)) {
            throw new PagarmeWebhookException('O charge_id já pertence a outro pedido local.', 409);
        }
        if (is_array($persisted)
            && $eventCreatedAt !== null
            && $persisted['last_event_at'] !== null
            && strtotime($eventCreatedAt) < strtotime((string) $persisted['last_event_at'])) {
            return;
        }

        $transaction = is_array($charge['last_transaction'] ?? null) ? $charge['last_transaction'] : [];
        $pdo->prepare(
            'INSERT INTO pagarme_charges(
                pagarme_order_id,payment_id,external_charge_id,external_transaction_id,
                charge_gateway_id,transaction_gateway_id,payment_method,status,
                amount_cents,paid_amount_cents,refunded_amount_cents,paid_at,last_event_at
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                external_transaction_id=COALESCE(VALUES(external_transaction_id),external_transaction_id),
                charge_gateway_id=COALESCE(VALUES(charge_gateway_id),charge_gateway_id),
                transaction_gateway_id=COALESCE(VALUES(transaction_gateway_id),transaction_gateway_id),
                payment_method=COALESCE(VALUES(payment_method),payment_method),
                status=COALESCE(VALUES(status),status),
                amount_cents=GREATEST(amount_cents,VALUES(amount_cents)),
                paid_amount_cents=GREATEST(paid_amount_cents,VALUES(paid_amount_cents)),
                refunded_amount_cents=GREATEST(refunded_amount_cents,VALUES(refunded_amount_cents)),
                paid_at=COALESCE(paid_at,VALUES(paid_at)),
                last_event_at=COALESCE(VALUES(last_event_at),last_event_at)'
        )->execute([
            $providerOrderId,
            $paymentId,
            $chargeId,
            $this->stringAt($transaction, ['id']),
            $this->stringAt($charge, ['gateway_id']),
            $this->stringAt($transaction, ['gateway_id']),
            $this->stringAt($charge, ['payment_method']),
            $this->stringAt($charge, ['status']),
            $this->integerAt($charge, ['amount']) ?? 0,
            $this->integerAt($charge, ['paid_amount']) ?? 0,
            $this->integerAt($charge, ['refunded_amount']) ?? 0,
            $this->eventDate($this->stringAt($charge, ['paid_at'])),
            $eventCreatedAt,
        ]);
    }

    /** @param array<string,mixed> $payment @return array{status:string,order_id:int,payment_id:int,order_code:string} */
    private function applyPaid(PDO $pdo, array $payment, array $data, ?string $eventCreatedAt): array
    {
        $result = $this->resultFor($payment, 'processed');
        if (in_array($payment['status'], ['paid', 'partially_refunded'], true)) {
            $this->touchPaymentEvent($pdo, (int) $payment['id'], $eventCreatedAt);
            return $result;
        }
        if (in_array($payment['status'], ['cancelled', 'refunded'], true) || in_array($payment['order_status'], ['cancelled', 'refunded'], true)) {
            throw new PagarmeWebhookException('Pagamento confirmado para um pedido já encerrado; revisão manual necessária.', 409);
        }
        $this->validatePaidAmount($payment, $data);
        (new OrderInventoryService())->consume($pdo, (int) $payment['order_id']);
        $paidAt = $this->firstString($data, [['paid_at'], ['charges', 0, 'paid_at'], ['last_transaction', 'paid_at']]);
        $paidAt = $this->eventDate($paidAt) ?? $eventCreatedAt ?? date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE payments SET status='paid',paid_at=?,last_event_at=COALESCE(?,last_event_at) WHERE id=?")
            ->execute([$paidAt, $eventCreatedAt, $payment['id']]);
        $pdo->prepare("UPDATE orders SET status='paid' WHERE id=? AND status='pending_payment'")->execute([$payment['order_id']]);
        $pdo->prepare("UPDATE seller_orders SET status='paid' WHERE order_id=? AND status='pending_payment'")->execute([$payment['order_id']]);
        (new OrderCouponService())->redeem($pdo, (int) $payment['order_id']);
        $this->history($pdo, (int) $payment['order_id'], 'paid', 'Pagamento confirmado pela Pagar.me.');
        $this->notification($pdo, $payment, 'payment_paid', 'Pagamento confirmado', 'O pagamento do pedido ' . $payment['order_code'] . ' foi confirmado.');
        $this->enqueueNewSaleAlert($pdo, $payment);
        return $result;
    }

    /** @param array<string,mixed> $payment */
    private function enqueueNewSaleAlert(PDO $pdo, array $payment): void
    {
        $recipientEmail = trim((string) (PlatformSettings::all()['new_sale_alert_email'] ?? ''));
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            $statement = $pdo->prepare(
                "SELECT o.id,o.code,o.grand_total,p.method,u.name customer_name,
                        GROUP_CONCAT(DISTINCT st.name ORDER BY st.name SEPARATOR ', ') store_names
                 FROM orders o
                 JOIN payments p ON p.order_id=o.id
                 JOIN users u ON u.id=o.user_id
                 JOIN seller_orders so ON so.order_id=o.id
                 JOIN stores st ON st.id=so.store_id
                 WHERE o.id=? AND p.id=?
                 GROUP BY o.id,o.code,o.grand_total,p.method,u.name"
            );
            $statement->execute([(int) $payment['order_id'], (int) $payment['id']]);
            $sale = $statement->fetch();
            if (!is_array($sale)) {
                return;
            }

            $method = match ((string) $sale['method']) {
                'pix' => 'PIX',
                'card' => 'Cartão de crédito',
                'boleto' => 'Boleto',
                default => mb_strtoupper((string) $sale['method']),
            };
            $code = (string) $sale['code'];
            $message = implode("\n", [
                'Uma nova venda foi confirmada na Tuffer.',
                '',
                'Pedido: ' . $code,
                'Cliente: ' . (string) $sale['customer_name'],
                'Loja(s): ' . (string) $sale['store_names'],
                'Pagamento: ' . $method,
                'Total: R$ ' . number_format((float) $sale['grand_total'], 2, ',', '.'),
                '',
                'Acompanhe o pedido em:',
                'https://tuffer.com.br/admin/pedidos/' . rawurlencode($code),
            ]);

            (new QueuedMailService())->enqueue(
                'Administrador Tuffer',
                $recipientEmail,
                'Nova venda confirmada: ' . $code,
                $message,
                'admin_new_sale',
                'order',
                (int) $sale['id'],
                'admin-new-sale:' . (int) $sale['id'],
            );
        } catch (Throwable $exception) {
            Logger::warning('Não foi possível enfileirar o alerta administrativo de nova venda.', [
                'order_id' => (int) $payment['order_id'],
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ], 'mail');
        }
    }

    /** @param array<string,mixed> $payment @return array{status:string,order_id:int,payment_id:int,order_code:string} */
    private function applyFailure(PDO $pdo, array $payment, ?string $eventCreatedAt): array
    {
        if (in_array($payment['status'], ['pending', 'waiting_payment', 'processing', 'failed'], true)) {
            $pdo->prepare("UPDATE payments SET status='failed',last_event_at=COALESCE(?,last_event_at) WHERE id=?")->execute([$eventCreatedAt, $payment['id']]);
            $this->history($pdo, (int) $payment['order_id'], 'payment_failed', 'A Pagar.me informou falha na tentativa de pagamento.');
            $this->notification($pdo, $payment, 'payment_failed', 'Pagamento não aprovado', 'O pagamento do pedido ' . $payment['order_code'] . ' não foi aprovado. Você pode tentar novamente enquanto o link estiver disponível.');
        } else {
            $this->touchPaymentEvent($pdo, (int) $payment['id'], $eventCreatedAt);
        }
        return $this->resultFor($payment, 'processed');
    }

    /** @param array<string,mixed> $payment @return array{status:string,order_id:int,payment_id:int,order_code:string} */
    private function applyExpiration(PDO $pdo, array $payment, ?string $eventCreatedAt): array
    {
        if (in_array($payment['status'], ['paid', 'partially_refunded', 'refunded'], true)) {
            return $this->resultFor($payment, 'ignored');
        }
        if ($payment['status'] !== 'expired') {
            (new OrderInventoryService())->release($pdo, (int) $payment['order_id']);
            (new OrderCouponService())->release($pdo, (int) $payment['order_id']);
            $pdo->prepare("UPDATE payments SET status='expired',last_event_at=COALESCE(?,last_event_at) WHERE id=?")
                ->execute([$eventCreatedAt, $payment['id']]);
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND status='pending_payment'")
                ->execute([$payment['order_id']]);
            $pdo->prepare("UPDATE seller_orders SET status='cancelled' WHERE order_id=? AND status='pending_payment'")
                ->execute([$payment['order_id']]);
            $this->history($pdo, (int) $payment['order_id'], 'payment_expired', 'Cobrança Pix expirada; estoque e cupom liberados.');
            $this->notification($pdo, $payment, 'payment_expired', 'Pix expirado', 'O Pix do pedido ' . $payment['order_code'] . ' expirou.');
        }
        return $this->resultFor($payment, 'processed');
    }

    /** @param array<string,mixed> $payment @return array{status:string,order_id:int,payment_id:int,order_code:string} */
    private function applyCancellation(PDO $pdo, array $payment, ?string $eventCreatedAt): array
    {
        if (in_array($payment['status'], ['paid', 'partially_refunded', 'refunded'], true)) {
            return $this->resultFor($payment, 'ignored');
        }
        if ($payment['status'] !== 'cancelled') {
            (new OrderInventoryService())->release($pdo, (int) $payment['order_id']);
            (new OrderCouponService())->release($pdo, (int) $payment['order_id']);
            $pdo->prepare("UPDATE payments SET status='cancelled',last_event_at=COALESCE(?,last_event_at) WHERE id=?")->execute([$eventCreatedAt, $payment['id']]);
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND status='pending_payment'")->execute([$payment['order_id']]);
            $pdo->prepare("UPDATE seller_orders SET status='cancelled' WHERE order_id=? AND status='pending_payment'")->execute([$payment['order_id']]);
            $this->history($pdo, (int) $payment['order_id'], 'cancelled', 'Pagamento ou checkout cancelado pela Pagar.me; estoque liberado.');
            $this->notification($pdo, $payment, 'payment_cancelled', 'Pedido cancelado', 'O checkout do pedido ' . $payment['order_code'] . ' foi cancelado e o estoque reservado foi liberado.');
        }
        return $this->resultFor($payment, 'processed');
    }

    /** @param array<string,mixed> $payment @return array{status:string,order_id:int,payment_id:int,order_code:string} */
    private function applyRefund(PDO $pdo, array $payment, array $data, string $eventType, ?string $eventCreatedAt): array
    {
        if (!in_array($payment['status'], ['paid', 'partially_refunded', 'refunded'], true)) {
            throw new PagarmeWebhookException('Estorno recebido para um pagamento que ainda não foi confirmado.', 409);
        }
        $totalCents = (int) ($payment['amount_cents'] ?? $this->moneyCents($payment['amount']));
        $refundedCents = $this->integerAt($data, ['refunded_amount'])
            ?? $this->integerAt($data, ['last_transaction', 'refunded_amount'])
            ?? 0;
        if (in_array($eventType, ['charge.chargedback', 'chargeback.received'], true)
            || $refundedCents < 1 && in_array((string) ($data['status'] ?? ''), ['refunded', 'chargedback'], true)) {
            $refundedCents = $totalCents;
        }
        if ($refundedCents < 1) {
            throw new PagarmeWebhookException('Webhook de estorno sem valor reembolsado.', 422);
        }
        $refundedCents = min($totalCents, $refundedCents);
        $fullRefund = $refundedCents >= $totalCents;
        $pdo->prepare('UPDATE payments SET refunded_amount=?,refunded_amount_cents=?,status=?,last_event_at=COALESCE(?,last_event_at) WHERE id=?')
            ->execute([$this->money($refundedCents), $refundedCents, $fullRefund ? 'refunded' : 'partially_refunded', $eventCreatedAt, $payment['id']]);
        if ($fullRefund) {
            $pdo->prepare("UPDATE orders SET status='refunded' WHERE id=? AND status IN ('paid','processing','completed')")->execute([$payment['order_id']]);
            $pdo->prepare("UPDATE seller_orders SET status='refunded' WHERE order_id=? AND status IN ('paid','processing','shipped','delivered')")->execute([$payment['order_id']]);
            $this->history($pdo, (int) $payment['order_id'], 'refunded', 'Estorno integral confirmado pela Pagar.me.');
            $this->notification($pdo, $payment, 'payment_refunded', 'Pagamento estornado', 'O pagamento do pedido ' . $payment['order_code'] . ' foi estornado.');
        } else {
            $this->history($pdo, (int) $payment['order_id'], 'partially_refunded', 'Estorno parcial confirmado pela Pagar.me: R$ ' . number_format($refundedCents / 100, 2, ',', '.'));
            $this->notification($pdo, $payment, 'payment_partially_refunded', 'Estorno parcial', 'Foi confirmado um estorno parcial no pedido ' . $payment['order_code'] . '.');
        }
        return $this->resultFor($payment, 'processed');
    }

    /** @param array<string,mixed> $payment */
    private function validatePaidAmount(array $payment, array $data): void
    {
        $received = $this->integerAt($data, ['amount']) ?? $this->integerAt($data, ['charges', 0, 'amount']);
        $expected = (int) ($payment['amount_cents'] ?? $this->moneyCents($payment['amount']));
        if ($received === null || $received !== $expected) {
            throw new PagarmeWebhookException('O valor pago informado pela Pagar.me diverge do pedido local.', 409);
        }
        $currency = $this->stringAt($data, ['currency']);
        if ($currency !== null && mb_strtoupper($currency) !== 'BRL') {
            throw new PagarmeWebhookException('A moeda do pagamento diverge do pedido local.', 409);
        }
    }

    private function consumeReservation(PDO $pdo, int $orderId): void
    {
        $allocations = $this->remainingReservations($pdo, $orderId);
        if ($allocations === []) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE reference_type='order' AND reference_id=? AND type='out'");
            $check->execute([$orderId]);
            if ((int) $check->fetchColumn() === 0) {
                throw new PagarmeWebhookException('Reserva de estoque do pedido não encontrada.', 409);
            }
            return;
        }
        foreach ($allocations as $allocation) {
            $quantity = (int) $allocation['remaining'];
            $stock = $pdo->prepare('SELECT quantity,reserved_quantity FROM stocks WHERE id=? FOR UPDATE');
            $stock->execute([$allocation['stock_id']]);
            $current = $stock->fetch();
            if (!is_array($current) || (int) $current['reserved_quantity'] < $quantity || (int) $current['quantity'] < $quantity) {
                throw new PagarmeWebhookException('A reserva de estoque do pedido está inconsistente.', 409);
            }
            $pdo->prepare('UPDATE stocks SET quantity=quantity-?,reserved_quantity=reserved_quantity-? WHERE id=?')->execute([$quantity, $quantity, $allocation['stock_id']]);
            $pdo->prepare("INSERT INTO stock_movements(stock_id,type,quantity,reference_type,reference_id,notes) VALUES(?,'out',?,'order',?,'Baixa após confirmação do pagamento')")
                ->execute([$allocation['stock_id'], $quantity, $orderId]);
        }
    }

    private function releaseReservation(PDO $pdo, int $orderId): void
    {
        foreach ($this->remainingReservations($pdo, $orderId) as $allocation) {
            $quantity = (int) $allocation['remaining'];
            $stock = $pdo->prepare('SELECT reserved_quantity FROM stocks WHERE id=? FOR UPDATE');
            $stock->execute([$allocation['stock_id']]);
            if ((int) $stock->fetchColumn() < $quantity) {
                throw new PagarmeWebhookException('A reserva de estoque do pedido está inconsistente.', 409);
            }
            $pdo->prepare('UPDATE stocks SET reserved_quantity=reserved_quantity-? WHERE id=?')->execute([$quantity, $allocation['stock_id']]);
            $pdo->prepare("INSERT INTO stock_movements(stock_id,type,quantity,reference_type,reference_id,notes) VALUES(?,'release',?,'order',?,'Liberação após cancelamento do checkout')")
                ->execute([$allocation['stock_id'], $quantity, $orderId]);
        }
    }

    /** @return array<int,array{stock_id:int,remaining:int}> */
    private function remainingReservations(PDO $pdo, int $orderId): array
    {
        $statement = $pdo->prepare("SELECT stock_id,type,quantity FROM stock_movements WHERE reference_type='order' AND reference_id=? ORDER BY stock_id,id FOR UPDATE");
        $statement->execute([$orderId]);
        $remaining = [];
        foreach ($statement->fetchAll() as $row) {
            $stockId = (int) $row['stock_id'];
            $remaining[$stockId] ??= 0;
            $remaining[$stockId] += $row['type'] === 'reserve' ? (int) $row['quantity'] : (in_array($row['type'], ['out', 'release'], true) ? -(int) $row['quantity'] : 0);
        }
        $result = [];
        foreach ($remaining as $stockId => $quantity) {
            if ($quantity > 0) $result[] = ['stock_id' => $stockId, 'remaining' => $quantity];
        }
        return $result;
    }

    private function history(PDO $pdo, int $orderId, string $status, string $notes): void
    {
        $pdo->prepare('INSERT INTO order_status_history(order_id,status,notes) VALUES(?,?,?)')->execute([$orderId, $status, $notes]);
    }

    /** @param array<string,mixed> $payment */
    private function notification(PDO $pdo, array $payment, string $type, string $title, string $message): void
    {
        $pdo->prepare('INSERT INTO user_notifications(user_id,type,title,message,action_url) VALUES(?,?,?,?,?)')
            ->execute([$payment['user_id'], $type, $title, $message, '/minha-conta/pedidos/' . $payment['order_code']]);
    }

    private function touchPaymentEvent(PDO $pdo, int $paymentId, ?string $eventCreatedAt): void
    {
        if ($eventCreatedAt !== null) {
            $pdo->prepare('UPDATE payments SET last_event_at=? WHERE id=?')->execute([$eventCreatedAt, $paymentId]);
        }
    }

    private function markWebhook(PDO $pdo, int $id, string $status, ?string $error, ?int $orderId = null, ?int $paymentId = null): void
    {
        $statement = $pdo->prepare('UPDATE payment_webhooks SET status=?,error_message=?,order_id=COALESCE(?,order_id),payment_id=COALESCE(?,payment_id),processed_at=NOW() WHERE id=?');
        $statement->execute([$status, $error, $orderId, $paymentId, $id]);
    }

    private function finishTransaction(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT ' . self::ROOT_SAVEPOINT);
        }
    }

    private function eventDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $payment @return array{status:string,order_id:int,payment_id:int,order_code:string} */
    private function resultFor(array $payment, string $status): array
    {
        return ['status' => $status, 'order_id' => (int) $payment['order_id'], 'payment_id' => (int) $payment['id'], 'order_code' => (string) $payment['order_code']];
    }

    /** @param array<string,mixed> $data @param array<int,string|int> $path */
    private function stringAt(array $data, array $path): ?string
    {
        $value = $this->valueAt($data, $path);
        if (!is_string($value) && !is_int($value)) return null;
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    /** @param array<string,mixed> $data @param array<int,array<int,string|int>> $paths */
    private function firstString(array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->stringAt($data, $path);
            if ($value !== null) return $value;
        }
        return null;
    }

    /** @param array<string,mixed> $data @param array<int,string|int> $path */
    private function integerAt(array $data, array $path): ?int
    {
        $value = $this->valueAt($data, $path);
        return is_int($value) || is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string,mixed> $data @param array<int,string|int> $path */
    private function valueAt(array $data, array $path): mixed
    {
        $value = $data;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) return null;
            $value = $value[$segment];
        }
        return $value;
    }

    private function moneyCents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
