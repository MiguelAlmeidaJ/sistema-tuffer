<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use App\Core\Database;
use App\Core\Logger;
use App\Services\Payments\PagarmeApiClient;
use App\Services\Payments\PagarmeClient;
use App\Services\Payments\PagarmeException;
use App\Services\Payments\Pagarme\DTO\CreateOrderData;
use App\Services\Payments\Pagarme\DTO\OrderItemData;
use App\Services\Payments\Pagarme\DTO\PixPaymentData;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class PagarmeOrderService
{
    private readonly PDO $pdo;
    private readonly PagarmeApiClient $client;
    private readonly PagarmeSplitService $splitService;
    private readonly PagarmeCheckoutConfiguration $configuration;
    private readonly PagarmePayloadSanitizer $sanitizer;

    public function __construct(
        ?PagarmeApiClient $client = null,
        ?PDO $database = null,
        ?PagarmeSplitService $splitService = null,
        ?PagarmeCheckoutConfiguration $configuration = null,
        ?PagarmePayloadSanitizer $sanitizer = null
    ) {
        $this->pdo = $database ?? Database::connection();
        $this->client = $client ?? new PagarmeClient();
        $this->splitService = $splitService ?? new PagarmeSplitService($this->pdo);
        $this->configuration = $configuration ?? new PagarmeCheckoutConfiguration();
        $this->sanitizer = $sanitizer ?? new PagarmePayloadSanitizer();
    }

    /** @return array<string,mixed> */
    public function createPixOrder(int $paymentId): array
    {
        $existing = $this->existingOrder($paymentId);
        if ($existing !== null) {
            return $existing;
        }
        $context = $this->context($paymentId);
        if ($context['integration_type'] !== 'orders' || $context['method'] !== 'pix') {
            throw new RuntimeException('Este pagamento não está configurado para o fluxo Pix com split.');
        }
        if (!in_array($context['payment_status'], ['pending', 'waiting_payment'], true)) {
            throw new RuntimeException('O estado atual do pagamento não permite criar outro pedido Pagar.me.');
        }

        $payload = $this->buildPayload($context, $this->splitService->rulesForPayment($paymentId));
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $attempts = new PagarmeOrderAttemptCoordinator($this->pdo);
        $token = $attempts->claim(
            $paymentId,
            (string) $context['idempotency_key'],
            $fingerprint
        );
        $remoteResponse = null;
        $postStarted = false;
        try {
            // Recupera uma criação remota anterior antes de considerar um novo POST.
            $remoteResponse = $this->findRemoteOrderByCode(
                (string) $context['order_code'],
                (int) $context['amount_cents']
            );
            if ($remoteResponse === null) {
                // Deliberadamente a última validação antes do request transacional.
                $this->splitService->revalidateRecipients($paymentId);
                $postStarted = true;
                $remoteResponse = $this->client->post(
                    '/orders',
                    $payload,
                    (string) $context['idempotency_key']
                );
            }
            $result = $this->persistResponse($context, $remoteResponse);
            $attempts->markCreated($paymentId, $token, (string) ($result['id'] ?? ''));
        } catch (Throwable $exception) {
            $externalOrderId = is_array($remoteResponse)
                ? trim((string) ($remoteResponse['id'] ?? '')) ?: null
                : null;
            if ($postStarted || $externalOrderId !== null) {
                $attempts->markUncertain($paymentId, $token, $exception, $externalOrderId);
            } else {
                $attempts->markFailed($paymentId, $token, $exception);
            }
            throw $exception;
        }
        Logger::info('Pedido Pix com split criado na Pagar.me.', [
            'payment_id' => $paymentId,
            'order_id' => $result['id'] ?? null,
            'charge_count' => count($result['charges'] ?? []),
        ], 'pagarme_orders');
        return $result;
    }

    /** @return array<string,mixed>|null */
    public function findRemoteOrderByCode(string $orderCode, int $amountCents): ?array
    {
        return (new PagarmeRemoteOrderLocator($this->client))->byCode($orderCode, $amountCents);
    }

    /** @return array<string,mixed> */
    public function recoverRemoteOrder(int $paymentId, array $remoteOrder): array
    {
        return $this->persistResponse($this->context($paymentId), $remoteOrder);
    }

    public function createCreditCardOrder(int $paymentId, ?string $cardToken = null): never
    {
        throw new RuntimeException('Cartão via API de Pedidos depende de tokenização segura e definição formal do escopo PCI.');
    }

    public function createBoletoOrder(int $paymentId): never
    {
        throw new RuntimeException('Boleto via API de Pedidos ainda não foi habilitado; use o fallback configurado.');
    }

    /** @param array<string,mixed> $context @param array<int,\App\Services\Payments\Pagarme\DTO\SplitRuleData> $split @return array<string,mixed> */
    public function buildPayload(array $context, array $split): array
    {
        $sellerOrders = is_array($context['seller_orders'] ?? null) ? $context['seller_orders'] : [];
        $items = [];
        $itemsTotal = 0;
        foreach ($sellerOrders as $sellerOrder) {
            $amount = (int) $sellerOrder['products_amount_cents'] - (int) $sellerOrder['discount_amount_cents'];
            if ($amount < 1) {
                continue;
            }
            $items[] = new OrderItemData(
                $amount,
                'Produtos - ' . (string) $sellerOrder['store_name'],
                1,
                (string) $sellerOrder['code']
            );
            $itemsTotal += $amount;
        }
        $shippingAmount = (int) ($context['shipping_amount_cents'] ?? 0);
        if ($items === [] || $itemsTotal + $shippingAmount !== (int) $context['amount_cents']) {
            throw new RuntimeException('Os itens e o frete não fecham com o total persistido do pagamento.');
        }
        if (array_sum(array_map(static fn($rule): int => $rule->amount, $split)) !== (int) $context['amount_cents']) {
            throw new RuntimeException('A soma do split não corresponde ao valor da cobrança.');
        }

        $document = preg_replace('/\D+/', '', (string) ($context['customer_document'] ?? '')) ?? '';
        $phone = preg_replace('/\D+/', '', (string) ($context['customer_phone'] ?? '')) ?? '';
        if (!in_array(strlen($document), [11, 14], true)
            || !in_array(strlen($phone), [10, 11], true)
            || !filter_var((string) ($context['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL)
            || trim((string) ($context['customer_name'] ?? '')) === '') {
            throw new RuntimeException('O cliente precisa ter nome, e-mail, documento e telefone válidos para pagar com Pix.');
        }
        $address = $this->address($context);
        $customer = [
            'name' => mb_substr(trim((string) $context['customer_name']), 0, 64),
            'email' => mb_substr(trim((string) $context['customer_email']), 0, 64),
            'code' => mb_substr('customer-' . (int) $context['user_id'], 0, 52),
            'type' => strlen($document) === 14 ? 'company' : 'individual',
            'document' => $document,
            'document_type' => strlen($document) === 14 ? 'CNPJ' : 'CPF',
            'phones' => [
                'mobile_phone' => [
                    'country_code' => '55',
                    'area_code' => substr($phone, 0, 2),
                    'number' => substr($phone, 2),
                ],
            ],
            'address' => $address,
        ];
        $shipping = [
            'amount' => $shippingAmount,
            'description' => 'Entrega do pedido ' . (string) $context['order_code'],
            'recipient_name' => mb_substr((string) $context['recipient_name'], 0, 64),
            'recipient_phone' => $phone,
            'address' => $address,
        ];
        return (new CreateOrderData(
            (string) $context['order_code'],
            $items,
            $customer,
            $shipping,
            new PixPaymentData($this->configuration->pixExpiresIn(), $split)
        ))->toArray();
    }

    /** @return array<string,mixed> */
    private function context(int $paymentId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id payment_id,p.integration_type,p.method,p.status payment_status,
                    p.amount_cents,p.idempotency_key,o.id order_id,o.code order_code,o.user_id,
                    ROUND(o.shipping_total*100) shipping_amount_cents,
                    u.name customer_name,u.email customer_email,u.phone customer_phone,u.document customer_document,
                    oa.recipient_name,oa.postal_code,oa.street,oa.number,oa.complement,
                    oa.neighborhood,oa.city,oa.state
             FROM payments p
             JOIN orders o ON o.id=p.order_id
             JOIN users u ON u.id=o.user_id
             JOIN order_addresses oa ON oa.order_id=o.id
             WHERE p.id=?"
        );
        $statement->execute([$paymentId]);
        $context = $statement->fetch();
        if (!is_array($context)) {
            throw new RuntimeException('Pagamento não encontrado para criar o pedido Pagar.me.');
        }
        $sellerOrders = $this->pdo->prepare(
            "SELECT so.code,st.name store_name,
                    ROUND(so.products_total*100) products_amount_cents,
                    ROUND(so.discount_total*100) discount_amount_cents
             FROM seller_orders so
             JOIN stores st ON st.id=so.store_id
             WHERE so.order_id=?
             ORDER BY so.id"
        );
        $sellerOrders->execute([$context['order_id']]);
        $context['seller_orders'] = $sellerOrders->fetchAll();
        return $context;
    }

    /** @param array<string,mixed> $context @return array<string,string> */
    private function address(array $context): array
    {
        return [
            'line_1' => mb_substr(implode(', ', array_filter([
                trim((string) $context['number']),
                trim((string) $context['street']),
                trim((string) $context['neighborhood']),
            ])), 0, 256),
            'line_2' => mb_substr(trim((string) ($context['complement'] ?? '')), 0, 128),
            'zip_code' => preg_replace('/\D+/', '', (string) $context['postal_code']) ?? '',
            'city' => mb_substr(trim((string) $context['city']), 0, 64),
            'state' => mb_strtoupper(mb_substr((string) $context['state'], 0, 2)),
            'country' => 'BR',
        ];
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $response @return array<string,mixed> */
    private function persistResponse(array $context, array $response): array
    {
        $safe = $this->sanitizer->orderResponse($response);
        $externalOrderId = trim((string) ($safe['id'] ?? ''));
        if (!preg_match('/^or_[A-Za-z0-9_-]+$/', $externalOrderId)
            || (int) ($safe['amount'] ?? -1) !== (int) $context['amount_cents']
            || !is_array($safe['charges'] ?? null)
            || $safe['charges'] === []) {
            throw new PagarmeException('A Pagar.me respondeu com um pedido ou valor inválido.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO pagarme_orders(payment_id,external_order_id,idempotency_key,status,amount_cents)
                 VALUES(?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
            )->execute([
                $context['payment_id'],
                $externalOrderId,
                $context['idempotency_key'],
                $safe['status'],
                $context['amount_cents'],
            ]);
            $providerOrderId = (int) $this->pdo->lastInsertId();
            $check = $this->pdo->prepare('SELECT payment_id,external_order_id FROM pagarme_orders WHERE id=? FOR UPDATE');
            $check->execute([$providerOrderId]);
            $persisted = $check->fetch();
            if (!is_array($persisted)
                || (int) $persisted['payment_id'] !== (int) $context['payment_id']
                || !hash_equals((string) $persisted['external_order_id'], $externalOrderId)) {
                throw new RuntimeException('Conflito de idempotência ao persistir o pedido Pagar.me.');
            }

            foreach ($safe['charges'] as $charge) {
                if (is_array($charge)) {
                    $this->persistCharge($providerOrderId, (int) $context['payment_id'], $charge);
                }
            }
            $transaction = is_array($response['charges'][0]['last_transaction'] ?? null)
                ? $response['charges'][0]['last_transaction']
                : [];
            $qrCode = is_string($transaction['qr_code'] ?? null) ? trim($transaction['qr_code']) : null;
            $qrCodeUrl = is_string($transaction['qr_code_url'] ?? null) ? trim($transaction['qr_code_url']) : null;
            $expiresAt = $this->date($transaction['expires_at'] ?? null);
            $encoded = json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->pdo->prepare(
                "UPDATE payments
                 SET external_order_id=COALESCE(external_order_id,?),
                     status=IF(status IN ('paid','partially_refunded','refunded','cancelled','expired'),status,'waiting_payment'),
                     pix_qr_code=?,pix_qr_code_url=?,pix_expires_at=?,
                     expires_at=COALESCE(?,expires_at),provider_payload=?
                 WHERE id=?"
            )->execute([
                $externalOrderId,
                $qrCode,
                $qrCodeUrl,
                $expiresAt,
                $expiresAt,
                $encoded,
                $context['payment_id'],
            ]);
            if (($context['payment_status'] ?? null) === 'pending') {
                $this->pdo->prepare(
                    "INSERT INTO order_status_history(order_id,status,notes)
                     VALUES(?,'pending_payment','Cobrança Pix com split criada pela fila.')"
                )->execute([$context['order_id']]);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $safe;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $charge */
    private function persistCharge(int $providerOrderId, int $paymentId, array $charge): void
    {
        $chargeId = trim((string) ($charge['id'] ?? ''));
        if ($chargeId === '') {
            return;
        }
        $check = $this->pdo->prepare(
            'SELECT pagarme_order_id,payment_id FROM pagarme_charges WHERE external_charge_id=? FOR UPDATE'
        );
        $check->execute([$chargeId]);
        $existing = $check->fetch();
        if (is_array($existing)
            && ((int) $existing['pagarme_order_id'] !== $providerOrderId
                || (int) $existing['payment_id'] !== $paymentId)) {
            throw new RuntimeException('O charge_id da resposta já pertence a outro pedido local.');
        }
        $transaction = is_array($charge['last_transaction'] ?? null) ? $charge['last_transaction'] : [];
        $this->pdo->prepare(
            'INSERT INTO pagarme_charges(
                pagarme_order_id,payment_id,external_charge_id,external_transaction_id,
                charge_gateway_id,transaction_gateway_id,payment_method,status,
                amount_cents,paid_amount_cents,refunded_amount_cents,paid_at
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                external_transaction_id=COALESCE(VALUES(external_transaction_id),external_transaction_id),
                charge_gateway_id=COALESCE(VALUES(charge_gateway_id),charge_gateway_id),
                transaction_gateway_id=COALESCE(VALUES(transaction_gateway_id),transaction_gateway_id),
                payment_method=COALESCE(VALUES(payment_method),payment_method),
                status=COALESCE(VALUES(status),status),
                amount_cents=GREATEST(amount_cents,VALUES(amount_cents)),
                paid_amount_cents=GREATEST(paid_amount_cents,VALUES(paid_amount_cents)),
                refunded_amount_cents=GREATEST(refunded_amount_cents,VALUES(refunded_amount_cents)),
                paid_at=COALESCE(paid_at,VALUES(paid_at))'
        )->execute([
            $providerOrderId,
            $paymentId,
            $chargeId,
            $transaction['id'] ?? null,
            $charge['gateway_id'] ?? null,
            $transaction['gateway_id'] ?? null,
            $charge['payment_method'] ?? null,
            $charge['status'] ?? null,
            (int) ($charge['amount'] ?? 0),
            (int) ($charge['paid_amount'] ?? 0),
            (int) ($charge['refunded_amount'] ?? 0),
            $this->date($charge['paid_at'] ?? null),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function existingOrder(int $paymentId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT external_order_id id,status,amount_cents amount FROM pagarme_orders WHERE payment_id=?'
        );
        $statement->execute([$paymentId]);
        $order = $statement->fetch();
        if (!is_array($order)) {
            return null;
        }
        $charges = $this->pdo->prepare(
            'SELECT external_charge_id id,status,amount_cents amount,external_transaction_id transaction_id
             FROM pagarme_charges WHERE payment_id=? ORDER BY id'
        );
        $charges->execute([$paymentId]);
        $order['charges'] = $charges->fetchAll();
        return $order;
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
