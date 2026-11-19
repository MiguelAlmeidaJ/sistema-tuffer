<?php
declare(strict_types=1);
namespace App\Services\Payments\Asaas;

use App\Core\Database;
use App\Services\Orders\OrderInventoryService;
use App\Services\Orders\OrderCouponService;
use App\Services\Queue\JobQueue;
use PDO;
use RuntimeException;
use Throwable;

/**
 * A confirmação ocorre após consulta autenticada GET /payments/{id}.
 * Nunca confiar no status contido apenas no corpo recebido pelo webhook.
 */
final class AsaasWebhookService
{
    public function __construct(
        private readonly ?PDO $database = null,
        private readonly ?AsaasClient $api = null
    ) {}

    /** @param array<string,mixed> $event */
    public function handle(array $event, string $receivedToken): void
    {
        $expected = trim((string) ($_ENV['ASAAS_WEBHOOK_TOKEN'] ?? ''));
        if (strlen($expected) < 16 || !hash_equals($expected, $receivedToken)) {
            throw new RuntimeException('Webhook Asaas não autorizado.', 401);
        }
        $eventId = trim((string) ($event['id'] ?? ''));
        $eventType = trim((string) ($event['event'] ?? ''));
        $paymentId = trim((string) ($event['payment']['id'] ?? ''));
        if (strlen($eventId) < 4 || strlen($eventId) > 120 || preg_match('/[\\x00-\\x1F\\x7F]/', $eventId) === 1
            || !preg_match('/^PAYMENT_[A-Z_]{3,90}$/', $eventType)
            || !preg_match('/^pay_[A-Za-z0-9_-]+$/', $paymentId)) {
            throw new RuntimeException('Evento Asaas inválido.', 422);
        }
        $pdo = $this->database ?? Database::connection();
        $existing = $pdo->prepare('SELECT id FROM asaas_webhook_events WHERE event_id=? LIMIT 1');
        $existing->execute([$eventId]);
        if ($existing->fetchColumn()) return;

        // Sempre consultar o Asaas; status e valor do payload público não
        // conferem autoridade a esta aplicação.
        $remote = ($this->api ?? new AsaasClient())->get('/payments/' . rawurlencode($paymentId));
        $code = (string) ($remote['externalReference'] ?? '');
        if ($code === '' || ($remote['id'] ?? null) !== $paymentId) {
            throw new RuntimeException('Cobrança Asaas sem referência válida.', 422);
        }
        $pdo->beginTransaction();
        try {
            $orderStmt = $pdo->prepare('SELECT id,code,user_id,status,grand_total FROM orders WHERE code=? FOR UPDATE');
            $orderStmt->execute([$code]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) throw new RuntimeException('Pedido Asaas não localizado.', 422);
            $paymentStmt = $pdo->prepare('SELECT * FROM payments WHERE order_id=? AND provider=? AND external_order_id=? FOR UPDATE');
            $paymentStmt->execute([(int) $order['id'],'asaas',$paymentId]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($payment)) throw new RuntimeException('Cobrança não vinculada ao pedido.', 422);
            $expectedType = ['pix'=>'PIX','boleto'=>'BOLETO','card'=>'CREDIT_CARD'][(string) $payment['method']] ?? '';
            if ($expectedType !== ($remote['billingType'] ?? null)
                || (int) round((float) ($remote['value'] ?? -1) * 100) !== (int) $payment['amount_cents']
                || (int) round((float) $order['grand_total'] * 100) !== (int) $payment['amount_cents']) {
                throw new RuntimeException('Divergência financeira no webhook Asaas.', 409);
            }
            $remoteStatus = (string) ($remote['status'] ?? '');
            $paid = in_array($remoteStatus, ['RECEIVED','CONFIRMED'], true);
            $storedStatus = 'ignored';
            if ($paid && in_array((string) $order['status'], ['pending_payment','paid','processing','completed'], true)) {
                if ((string) $payment['status'] !== 'paid') {
                    if ($order['status'] !== 'pending_payment') {
                        throw new RuntimeException('Pedido liberado sem conciliação financeira; revisão necessária.', 409);
                    }
                    (new OrderInventoryService())->consume($pdo, (int) $order['id']);
                    (new OrderCouponService())->redeem($pdo, (int) $order['id']);
                    $pdo->prepare("UPDATE payments SET status='paid',paid_at=NOW() WHERE id=? AND status IN ('pending','waiting_payment','processing')")
                        ->execute([(int) $payment['id']]);
                    $pdo->prepare("UPDATE orders SET status='paid' WHERE id=? AND status='pending_payment'")
                        ->execute([(int) $order['id']]);
                    $pdo->prepare("UPDATE seller_orders SET status='paid' WHERE order_id=? AND status='pending_payment'")
                        ->execute([(int) $order['id']]);
                    $pdo->prepare("INSERT INTO order_status_history(order_id,status,notes) VALUES(?,'paid',?)")
                        ->execute([(int) $order['id'], 'Pagamento confirmado por consulta autenticada ao Asaas.']);
                    $pdo->prepare("INSERT INTO user_notifications(user_id,type,title,message,action_url) VALUES(?,'payment_paid',?,?,?)")
                        ->execute([(int) $order['user_id'], 'Pagamento confirmado', 'Pagamento do pedido ' . $code . ' confirmado.', '/minha-conta/pedidos/' . $code]);
                    (new JobQueue($pdo))->dispatch('fiscal.sync_paid_order', ['order_id'=>(int) $order['id']],
                        'fiscal-sync:asaas:' . (int) $payment['id'], 'fiscal', 8, 40);
                }
                $storedStatus = 'processed';
            } elseif (in_array($eventType, ['PAYMENT_REFUNDED','PAYMENT_CHARGEBACK_REQUESTED','PAYMENT_CHARGEBACK_DISPUTE','PAYMENT_DELETED'], true)) {
                // Não inferir cancelamento, reversão de estoque ou estorno somente
                // pelo evento: registrar divergência para revisão administrativa.
                $storedStatus = 'review_required';
            }
            $save = $pdo->prepare('INSERT INTO asaas_webhook_events(event_id,external_payment_id,event_type,status) VALUES(?,?,?,?)');
            $save->execute([$eventId,$paymentId,$eventType,$storedStatus]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}
