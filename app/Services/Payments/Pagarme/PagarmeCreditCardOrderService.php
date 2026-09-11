<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

use App\Core\Database;
use App\Core\Logger;
use App\Services\Payments\PagarmeApiClient;
use App\Services\Payments\PagarmeClient;
use App\Services\Payments\PagarmeException;
use PDO;
use RuntimeException;
use Throwable;

final class PagarmeCreditCardOrderService
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

    /** @return array<string,mixed> */
    public function create(int $paymentId, string $cardToken, int $installments): array
    {
        $cardToken = trim($cardToken);
        if ($paymentId < 1 || preg_match('/^token_[A-Za-z0-9_-]+$/', $cardToken) !== 1) {
            throw new RuntimeException('O token do cartão é inválido ou expirou.');
        }
        if ($installments < 1 || $installments > 6) {
            throw new RuntimeException('Escolha entre 1 e 6 parcelas.');
        }

        $context = $this->context($paymentId);
        if ((string) $context['method'] !== 'card') {
            throw new RuntimeException('Este pagamento não foi criado para cartão.');
        }
        if (!in_array((string) $context['payment_status'], ['pending', 'processing'], true)) {
            throw new RuntimeException('O estado atual do pagamento não permite uma nova tentativa com cartão.');
        }

        $splitService = new PagarmeSplitService($this->pdo);
        $splitService->revalidateRecipients($paymentId);
        $rules = $splitService->rulesForPayment($paymentId);
        $address = $this->address($context);
        $customerId = $this->upsertCustomer($context, $address);
        $cardId = $this->createCard($customerId, $cardToken, $address, $paymentId);
        $payload = $this->payload($context, $customerId, $cardId, $installments, $rules, $address);

        $this->pdo->prepare("UPDATE payments SET integration_type='orders',status='processing' WHERE id=? AND status='pending'")
            ->execute([$paymentId]);

        try {
            $response = $this->client->post('/orders', $payload, (string) $context['idempotency_key']);
            $safe = $this->persist($context, $response);
        } catch (Throwable $exception) {
            Logger::exception($exception, ['payment_id' => $paymentId], 'pagarme_card');
            throw $exception;
        }

        $charge = is_array($safe['charges'][0] ?? null) ? $safe['charges'][0] : [];
        $transaction = is_array($charge['last_transaction'] ?? null) ? $charge['last_transaction'] : [];
        $failed = in_array((string) ($charge['status'] ?? ''), ['failed', 'canceled', 'cancelled'], true)
            || in_array((string) ($transaction['status'] ?? ''), ['not_authorized', 'failed', 'with_error', 'voided'], true)
            || (string) ($safe['status'] ?? '') === 'failed';
        if ($failed) {
            $this->pdo->prepare("UPDATE payments SET status='failed' WHERE id=? AND status NOT IN ('paid','refunded','partially_refunded')")
                ->execute([$paymentId]);
            throw new PagarmeException('O cartão não foi autorizado. Você poderá tentar outra forma de pagamento sem criar outro pedido.');
        }

        Logger::info('Pedido com cartão criado na Pagar.me.', [
            'payment_id' => $paymentId,
            'order_id' => $safe['id'] ?? null,
            'installments' => $installments,
        ], 'pagarme_card');
        return $safe;
    }

    /** @param array<string,mixed> $context @param array<string,string> $address */
    private function upsertCustomer(array $context, array $address): string
    {
        $document = preg_replace('/\D+/', '', (string) ($context['customer_document'] ?? '')) ?? '';
        $phone = preg_replace('/\D+/', '', (string) ($context['customer_phone'] ?? '')) ?? '';
        if (!in_array(strlen($document), [11, 14], true) || !in_array(strlen($phone), [10, 11], true)) {
            throw new RuntimeException('Complete documento e telefone antes de pagar com cartão.');
        }
        $response = $this->client->post('/customers', [
            'name' => mb_substr(trim((string) $context['customer_name']), 0, 64),
            'email' => mb_substr(trim((string) $context['customer_email']), 0, 64),
            'code' => mb_substr('customer-' . (int) $context['user_id'], 0, 52),
            'document' => $document,
            'document_type' => strlen($document) === 14 ? 'CNPJ' : 'CPF',
            'type' => strlen($document) === 14 ? 'company' : 'individual',
            'address' => $address,
            'phones' => [
                'mobile_phone' => [
                    'country_code' => '55',
                    'area_code' => substr($phone, 0, 2),
                    'number' => substr($phone, 2),
                ],
            ],
        ]);
        $id = trim((string) ($response['id'] ?? ''));
        if (preg_match('/^cus_[A-Za-z0-9_-]+$/', $id) !== 1) {
            throw new PagarmeException('Não foi possível preparar o cadastro seguro do pagador.');
        }
        return $id;
    }

    /** @param array<string,string> $address */
    private function createCard(string $customerId, string $token, array $address, int $paymentId): string
    {
        $response = $this->client->post(
            '/customers/' . rawurlencode($customerId) . '/cards',
            ['token' => $token, 'billing_address' => $address],
            'card-' . $paymentId . '-' . substr(hash('sha256', $token), 0, 24)
        );
        $id = trim((string) ($response['id'] ?? ''));
        if (preg_match('/^card_[A-Za-z0-9_-]+$/', $id) !== 1) {
            throw new PagarmeException('Não foi possível validar o cartão com segurança.');
        }
        return $id;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<int,\App\Services\Payments\Pagarme\DTO\SplitRuleData> $rules
     * @param array<string,string> $address
     * @return array<string,mixed>
     */
    private function payload(array $context, string $customerId, string $cardId, int $installments, array $rules, array $address): array
    {
        $sellerOrders = is_array($context['seller_orders'] ?? null) ? $context['seller_orders'] : [];
        $items = [];
        $itemsTotal = 0;
        foreach ($sellerOrders as $sellerOrder) {
            $amount = (int) $sellerOrder['products_amount_cents'] - (int) $sellerOrder['discount_amount_cents'];
            if ($amount < 1) continue;
            $items[] = [
                'amount' => $amount,
                'description' => mb_substr('Produtos - ' . (string) $sellerOrder['store_name'], 0, 255),
                'quantity' => 1,
                'code' => mb_substr((string) $sellerOrder['code'], 0, 52),
            ];
            $itemsTotal += $amount;
        }
        $shippingAmount = (int) $context['shipping_amount_cents'];
        if ($items === [] || $itemsTotal + $shippingAmount !== (int) $context['amount_cents']) {
            throw new RuntimeException('Os itens e o frete não fecham com o total do pagamento.');
        }
        if (array_sum(array_map(static fn($rule): int => $rule->amount, $rules)) !== (int) $context['amount_cents']) {
            throw new RuntimeException('A soma do split não corresponde ao valor da cobrança.');
        }

        return [
            'code' => mb_substr((string) $context['order_code'], 0, 52),
            'closed' => true,
            'items' => $items,
            'customer_id' => $customerId,
            'shipping' => [
                'amount' => $shippingAmount,
                'description' => 'Entrega do pedido ' . (string) $context['order_code'],
                'recipient_name' => mb_substr((string) $context['recipient_name'], 0, 64),
                'recipient_phone' => preg_replace('/\D+/', '', (string) $context['customer_phone']) ?? '',
                'address' => $address,
            ],
            'payments' => [[
                'payment_method' => 'credit_card',
                'credit_card' => [
                    'installments' => $installments,
                    'statement_descriptor' => $this->statementDescriptor(),
                    'operation_type' => 'auth_and_capture',
                    'card_id' => $cardId,
                ],
                'split' => array_map(static fn($rule): array => $rule->toArray(), $rules),
            ]],
            'metadata' => [
                'integration' => 'tuffer-marketplace-card-v1',
                'order_code' => (string) $context['order_code'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function context(int $paymentId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT p.id payment_id,p.method,p.status payment_status,p.amount_cents,p.idempotency_key,
                    o.id order_id,o.code order_code,o.user_id,ROUND(o.shipping_total*100) shipping_amount_cents,
                    u.name customer_name,u.email customer_email,u.phone customer_phone,u.document customer_document,
                    oa.recipient_name,oa.postal_code,oa.street,oa.number,oa.complement,oa.neighborhood,oa.city,oa.state
             FROM payments p
             JOIN orders o ON o.id=p.order_id
             JOIN users u ON u.id=o.user_id
             JOIN order_addresses oa ON oa.order_id=o.id
             WHERE p.id=? LIMIT 1"
        );
        $statement->execute([$paymentId]);
        $context = $statement->fetch();
        if (!is_array($context)) throw new RuntimeException('Pagamento não encontrado.');
        $sellerOrders = $this->pdo->prepare(
            "SELECT so.code,st.name store_name,ROUND(so.products_total*100) products_amount_cents,
                    ROUND(so.discount_total*100) discount_amount_cents
             FROM seller_orders so JOIN stores st ON st.id=so.store_id
             WHERE so.order_id=? ORDER BY so.id"
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
    private function persist(array $context, array $response): array
    {
        $safe = $this->sanitizer->orderResponse($response);
        $externalOrderId = trim((string) ($safe['id'] ?? ''));
        if (preg_match('/^or_[A-Za-z0-9_-]+$/', $externalOrderId) !== 1
            || (int) ($safe['amount'] ?? -1) !== (int) $context['amount_cents']
            || !is_array($safe['charges'] ?? null)
            || $safe['charges'] === []) {
            throw new PagarmeException('A Pagar.me respondeu com um pedido de cartão inválido.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO pagarme_orders(payment_id,external_order_id,idempotency_key,status,amount_cents)
                 VALUES(?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE external_order_id=VALUES(external_order_id),status=VALUES(status),amount_cents=VALUES(amount_cents)'
            )->execute([
                $context['payment_id'], $externalOrderId, $context['idempotency_key'],
                (string) ($safe['status'] ?? 'pending'), $context['amount_cents'],
            ]);
            $orderStatement = $this->pdo->prepare('SELECT id FROM pagarme_orders WHERE payment_id=? LIMIT 1');
            $orderStatement->execute([$context['payment_id']]);
            $providerOrderId = (int) $orderStatement->fetchColumn();
            if ($providerOrderId < 1) throw new RuntimeException('Não foi possível persistir o pedido do cartão.');

            $firstChargeId = null;
            foreach ($safe['charges'] as $charge) {
                if (!is_array($charge)) continue;
                $chargeId = trim((string) ($charge['id'] ?? ''));
                if ($chargeId === '') continue;
                $firstChargeId ??= $chargeId;
                $transaction = is_array($charge['last_transaction'] ?? null) ? $charge['last_transaction'] : [];
                $this->pdo->prepare(
                    'INSERT INTO pagarme_charges(
                        pagarme_order_id,payment_id,external_charge_id,external_transaction_id,
                        charge_gateway_id,transaction_gateway_id,payment_method,status,
                        amount_cents,paid_amount_cents,refunded_amount_cents,paid_at
                     ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        status=VALUES(status),paid_amount_cents=VALUES(paid_amount_cents),
                        refunded_amount_cents=VALUES(refunded_amount_cents),external_transaction_id=VALUES(external_transaction_id),
                        transaction_gateway_id=VALUES(transaction_gateway_id),paid_at=COALESCE(VALUES(paid_at),paid_at)'
                )->execute([
                    $providerOrderId,
                    $context['payment_id'],
                    $chargeId,
                    $transaction['id'] ?? null,
                    $charge['gateway_id'] ?? null,
                    $transaction['gateway_id'] ?? null,
                    $charge['payment_method'] ?? 'credit_card',
                    $charge['status'] ?? null,
                    (int) ($charge['amount'] ?? 0),
                    (int) ($charge['paid_amount'] ?? 0),
                    (int) ($charge['refunded_amount'] ?? 0),
                    $this->date($charge['paid_at'] ?? null),
                ]);
            }
            $encoded = json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->pdo->prepare(
                "UPDATE payments SET integration_type='orders',external_order_id=?,external_charge_id=COALESCE(external_charge_id,?),
                    status=IF(status IN ('paid','partially_refunded','refunded','cancelled','expired'),status,'processing'),provider_payload=?
                 WHERE id=?"
            )->execute([$externalOrderId, $firstChargeId, $encoded, $context['payment_id']]);
            if ($ownsTransaction) $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
        return $safe;
    }

    private function statementDescriptor(): string
    {
        $name = (string) ($_ENV['PAGARME_STATEMENT_DESCRIPTOR'] ?? 'TUFFER');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $normalized = preg_replace('/[^A-Za-z0-9 ]+/', '', is_string($ascii) ? $ascii : $name) ?? 'TUFFER';
        return mb_substr(mb_strtoupper(trim($normalized) ?: 'TUFFER'), 0, 13);
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
