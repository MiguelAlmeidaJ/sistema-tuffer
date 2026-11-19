<?php
declare(strict_types=1);

namespace App\Services\Orders;

use App\Core\Database;
use App\Services\Payments\Asaas\AsaasClient;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Exclusivo para testes Sandbox. Não é fluxo de estorno nem de pedidos pagos.
 * O lock nomeado é compartilhado com AsaasChargeService::create().
 */
final class SandboxOrderCancellationService
{
    public function __construct(
        private readonly ?PDO $database = null,
        private readonly ?AsaasClient $api = null
    ) {}

    public function cancel(string $sellerOrderCode, int $selectedStoreId): void
    {
        if (strtolower(trim((string) ($_ENV['ASAAS_ENV'] ?? ''))) !== 'sandbox') {
            throw new RuntimeException('Cancelamento de teste disponível somente no Sandbox.');
        }
        if ($selectedStoreId < 1) throw new RuntimeException('Loja inválida.');

        $pdo = $this->database ?? Database::connection();
        $api = $this->api ?? new AsaasClient();
        if (!$api->configured()) throw new RuntimeException('Asaas Sandbox não configurado.');

        // Resolva o pagamento e o pedido ANTES do lock, mas valide tudo novamente
        // dentro da transação ao concluir.
        $lookup = $pdo->prepare(
            "SELECT so.order_id,p.id payment_id
             FROM seller_orders so
             JOIN orders o ON o.id=so.order_id
             JOIN payments p ON p.order_id=o.id AND p.provider='asaas'
             WHERE so.code=?
               AND COALESCE((SELECT sc.target_store_id FROM store_consolidations sc
                             WHERE sc.source_store_id=so.store_id),so.store_id)=?
             ORDER BY p.id DESC LIMIT 1"
        );
        $lookup->execute([$sellerOrderCode,$selectedStoreId]);
        $reference = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($reference)) throw new RuntimeException('Pedido Asaas não encontrado nesta loja.');

        $paymentId = (int) $reference['payment_id'];
        $lockName = 'asaas-payment-' . $paymentId;
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 15)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Cobrança sendo processada. Tente cancelar novamente em instantes.');
        }

        try {
            $pdo->beginTransaction();
            $orderStmt = $pdo->prepare('SELECT id,code,status,grand_total FROM orders WHERE id=? FOR UPDATE');
            $orderStmt->execute([(int) $reference['order_id']]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order) || $order['status'] !== 'pending_payment') {
                throw new RuntimeException('Apenas pedidos aguardando pagamento podem ser cancelados por aqui.');
            }

            $allStores = $pdo->prepare('SELECT id,status FROM seller_orders WHERE order_id=? FOR UPDATE');
            $allStores->execute([(int) $order['id']]);
            $sellerOrders = $allStores->fetchAll(PDO::FETCH_ASSOC);
            if (count($sellerOrders) !== 1 || $sellerOrders[0]['status'] !== 'pending_payment') {
                throw new RuntimeException('Pedido com múltiplas operações ou processamento iniciado requer revisão administrativa.');
            }

            $payStmt = $pdo->prepare('SELECT * FROM payments WHERE id=? AND order_id=? FOR UPDATE');
            $payStmt->execute([$paymentId,(int) $order['id']]);
            $payment = $payStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($payment) || $payment['provider'] !== 'asaas'
                || !in_array((string)$payment['status'], ['pending','waiting_payment','processing'], true)) {
                throw new RuntimeException('Cobrança já concluída ou em situação que exige revisão.');
            }

            $check = $pdo->prepare("SELECT COUNT(*) FROM fiscal_documents WHERE seller_order_id=? AND status='authorized'");
            $check->execute([(int) $sellerOrders[0]['id']]);
            if ((int)$check->fetchColumn() !== 0) {
                throw new RuntimeException('Existe documento fiscal autorizado. Cancele por fluxo administrativo.');
            }
            $shipment = $pdo->prepare('SELECT status,label_purchase_status FROM shipments WHERE seller_order_id=?');
            $shipment->execute([(int) $sellerOrders[0]['id']]);
            $shipmentRow = $shipment->fetch(PDO::FETCH_ASSOC);
            if (is_array($shipmentRow)
                && (in_array((string)$shipmentRow['status'], ['purchased','posted','in_transit','delivered'],true)
                    || in_array((string)$shipmentRow['label_purchase_status'], ['ready','processing','purchased'],true))) {
                throw new RuntimeException('Este envio possui etiqueta comprada ou postagem em andamento.');
            }

            $externalId = trim((string) ($payment['external_order_id'] ?? ''));
            if ($externalId === '') {
                $result = $api->get('/payments?externalReference='.rawurlencode((string)$order['code']).'&limit=10');
                $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
                $matches = array_values(array_filter($rows,static fn($p):bool =>
                    is_array($p) && ($p['externalReference'] ?? null) === $order['code']));
                if (count($matches) > 1) throw new RuntimeException('Cobranças duplicadas no Asaas. Revisão manual necessária.');
                $externalId = (string) ($matches[0]['id'] ?? '');
            }

            if ($externalId !== '') {
                if (!preg_match('/^pay_[A-Za-z0-9_-]+$/', $externalId)) {
                    throw new RuntimeException('Identificador da cobrança inválido.');
                }
                $remote = $api->get('/payments/'.rawurlencode($externalId));
                if (($remote['id'] ?? null) !== $externalId
                    || ($remote['externalReference'] ?? null) !== $order['code']
                    || (int)round((float)($remote['value'] ?? -1)*100) !== (int)$payment['amount_cents']) {
                    throw new RuntimeException('Dados da cobrança não correspondem ao pedido. Cancelamento bloqueado.');
                }
                $remoteStatus = strtoupper((string)($remote['status'] ?? ''));
                if (!in_array($remoteStatus, ['PENDING','OVERDUE','DELETED'], true)) {
                    throw new RuntimeException('Cobrança não está pendente no Asaas. Verifique pagamentos ou estornos antes de cancelar.');
                }
                if ($remoteStatus !== 'DELETED' && empty($remote['deleted'])) {
                    $api->deletePayment($externalId);
                }
            } else {
                $marker = json_decode((string)($payment['provider_payload'] ?? ''),true);
                if (is_array($marker) && !empty($marker['asaas_create_attempted'])) {
                    throw new RuntimeException('Criação remota incerta: confira a cobrança no Asaas antes de cancelar.');
                }
            }

            // Só muda estado local depois de confirmar que a cobrança remota
            // inexiste ou foi removida. A transação conserva as reservas.
            (new OrderInventoryService())->release($pdo,(int)$order['id']);
            $pdo->prepare("UPDATE payments SET status='cancelled' WHERE id=? AND status IN ('pending','waiting_payment','processing')")
                ->execute([$paymentId]);
            $pdo->prepare("UPDATE seller_orders SET status='cancelled' WHERE id=? AND status='pending_payment'")
                ->execute([(int)$sellerOrders[0]['id']]);
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND status='pending_payment'")
                ->execute([(int)$order['id']]);
            $pdo->prepare("INSERT INTO order_status_history(order_id,status,notes) VALUES(?,'cancelled',?)")
                ->execute([(int)$order['id'],'Pedido de teste cancelado pelo vendedor no Asaas Sandbox.']);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }
}
