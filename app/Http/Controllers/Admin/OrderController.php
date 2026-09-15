<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Finance\MarketplaceFinancialLedgerService;
use App\Services\Orders\OrderCouponService;
use App\Services\Orders\OrderInventoryService;
use App\Services\Payments\Pagarme\PagarmeOrderReconciliationService;
use App\Services\Payments\Pagarme\PagarmePixRefundService;
use App\Services\Shipping\MelhorEnvioLabelReplacementService;
use App\Services\Shipping\MelhorEnvioTrackingService;
use App\Services\Shipping\ShippingQuoteService;
use PDO;
use RuntimeException;
use Throwable;

final class OrderController extends Controller
{
    public function index(): string
    {
        $status = trim((string) ($_GET['status'] ?? ''));
        $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $allowed = ['pending_payment', 'active', 'paid', 'processing', 'completed', 'cancelled', 'refunded'];

        $sql = "SELECT o.*,u.name customer_name,u.email customer_email,
                       (SELECT COUNT(*) FROM seller_orders so WHERE so.order_id=o.id) store_count,
                       (SELECT COUNT(*)
                          FROM shipments sh
                          JOIN seller_orders so2 ON so2.id=sh.seller_order_id
                         WHERE so2.order_id=o.id) shipment_count,
                       (SELECT p.status FROM payments p WHERE p.order_id=o.id ORDER BY p.id DESC LIMIT 1) payment_status,
                       (SELECT p.method FROM payments p WHERE p.order_id=o.id ORDER BY p.id DESC LIMIT 1) payment_method,
                       (SELECT pa.last_error
                          FROM pagarme_order_attempts pa
                          JOIN payments p2 ON p2.id=pa.payment_id
                         WHERE p2.order_id=o.id AND pa.last_error IS NOT NULL AND pa.last_error<>''
                         ORDER BY p2.id DESC
                         LIMIT 1) payment_error,
                       (SELECT h.notes
                          FROM order_status_history h
                         WHERE h.order_id=o.id AND h.status='cancelled'
                         ORDER BY h.id DESC
                         LIMIT 1) cancellation_reason
                  FROM orders o
                  JOIN users u ON u.id=o.user_id
                 WHERE 1=1";
        $params = [];

        if (in_array($status, $allowed, true)) {
            if ($status === 'active') {
                $sql .= " AND o.status IN ('paid','processing')";
            } else {
                $sql .= ' AND o.status=?';
                $params[] = $status;
            }
        }
        if ($search !== '') {
            $sql .= ' AND (o.code LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term);
        }

        $sql .= ' ORDER BY o.created_at DESC LIMIT 150';
        $pdo = Database::connection();
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $counts = $pdo->query(
            "SELECT COUNT(*) total,
                    SUM(status='pending_payment') pending_payment,
                    SUM(status IN ('paid','processing')) active,
                    SUM(status='completed') completed,
                    SUM(status='cancelled') cancelled
               FROM orders"
        )->fetch();

        return $this->page('admin/orders/index', 'layouts/admin', [
            'pageTitle' => 'Pedidos',
            'orders' => $statement->fetchAll(),
            'counts' => $counts,
            'filters' => ['status' => $status, 'q' => $search],
        ]);
    }

    public function show(string $code): string
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT o.*,u.name customer_name,u.email customer_email,u.phone customer_phone,u.document customer_document
             FROM orders o
             JOIN users u ON u.id=o.user_id
             WHERE o.code=?'
        );
        $statement->execute([$code]);
        $order = $statement->fetch();
        if (!$order) {
            http_response_code(404);
            return $this->page('admin/orders/show', 'layouts/admin', [
                'pageTitle' => 'Pedido não encontrado',
                'order' => null,
            ]);
        }

        if ((string) ($order['status'] ?? '') === 'pending_payment') {
            try {
                $reconciled = (new PagarmeOrderReconciliationService(null, $pdo))->reconcileOrder($code);
                if ($reconciled) {
                    $statement->execute([$code]);
                    $refreshedOrder = $statement->fetch();
                    if (is_array($refreshedOrder)) {
                        $order = $refreshedOrder;
                    }
                }
            } catch (Throwable $exception) {
                Logger::exception($exception, [
                    'order_code' => $code,
                    'order_id' => (int) $order['id'],
                ], 'pagarme_reconciliation');
            }
        }

        $sub = $pdo->prepare(
            'SELECT so.*,st.name store_name,s.trade_name,sh.id shipment_id,sh.service_id,sh.external_id,sh.service_name,
                    sh.carrier_name,sh.tracking_code,sh.tracking_url,sh.status shipment_status,sh.raw_status,sh.last_synced_at,
                    sh.shipping_cost,sh.label_purchase_status,sh.label_actual_cost,sh.label_error,sh.label_url,sh.invoice_key,
                    (SELECT fd.access_key FROM fiscal_documents fd
                      WHERE fd.seller_order_id=so.id AND fd.document_type=\'nfe\' AND fd.revision=1
                      ORDER BY fd.id DESC LIMIT 1) fiscal_access_key
             FROM seller_orders so
             JOIN stores st ON st.id=so.store_id
             JOIN sellers s ON s.id=so.seller_id
             LEFT JOIN shipments sh ON sh.seller_order_id=so.id
             WHERE so.order_id=?
             ORDER BY so.id'
        );
        $sub->execute([$order['id']]);
        $sellerOrders = $sub->fetchAll();

        $replacementService = new MelhorEnvioLabelReplacementService($pdo);
        $replacementConfigured = $replacementService->configured();
        $replaceShipmentId = max(0, (int) ($_GET['trocar_remessa'] ?? 0));
        $quoteService = new ShippingQuoteService();

        $items = $pdo->prepare('SELECT * FROM order_items WHERE seller_order_id=? ORDER BY id');
        foreach ($sellerOrders as &$sellerOrder) {
            $items->execute([$sellerOrder['id']]);
            $sellerOrder['items'] = $items->fetchAll();
            $sellerOrder['replacement_quote'] = null;
            $sellerOrder['replacement_history'] = [];
            if ($replaceShipmentId > 0
                && (int) ($sellerOrder['shipment_id'] ?? 0) === $replaceShipmentId
                && $replacementConfigured) {
                $sellerOrder['replacement_quote'] = $quoteService->quotesForSellerOrder((int) $sellerOrder['id']);
            }
        }
        unset($sellerOrder);

        $replacementHistory = $pdo->prepare(
            'SELECT r.*,u.name changed_by_name
               FROM shipment_label_replacements r
               LEFT JOIN users u ON u.id=r.changed_by
              WHERE r.order_id=?
              ORDER BY r.created_at DESC,r.id DESC'
        );
        $replacementHistory->execute([(int) $order['id']]);
        $historyByShipment = [];
        foreach ($replacementHistory->fetchAll() as $replacement) {
            $historyByShipment[(int) $replacement['shipment_id']][] = $replacement;
        }
        foreach ($sellerOrders as &$sellerOrder) {
            $sellerOrder['replacement_history'] = $historyByShipment[(int) ($sellerOrder['shipment_id'] ?? 0)] ?? [];
        }
        unset($sellerOrder);

        $payment = $pdo->prepare('SELECT * FROM payments WHERE order_id=? ORDER BY id DESC');
        $payment->execute([$order['id']]);
        $history = $pdo->prepare('SELECT * FROM order_status_history WHERE order_id=? ORDER BY created_at DESC,id DESC');
        $history->execute([$order['id']]);
        $address = $pdo->prepare('SELECT * FROM order_addresses WHERE order_id=?');
        $address->execute([$order['id']]);

        return $this->page('admin/orders/show', 'layouts/admin', [
            'pageTitle' => 'Pedido ' . $code,
            'order' => $order,
            'sellerOrders' => $sellerOrders,
            'payments' => $payment->fetchAll(),
            'history' => $history->fetchAll(),
            'address' => $address->fetch() ?: null,
            'trackingConfigured' => (new MelhorEnvioTrackingService())->configured(),
            'labelReplacementConfigured' => $replacementConfigured,
            'replaceShipmentId' => $replaceShipmentId,
        ]);
    }

    public function cancel(string $code): string
    {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $details = mb_substr(trim((string) ($_POST['details'] ?? '')), 0, 300);
        $reasons = $this->cancellationReasons();

        if (!isset($reasons[$reason])) {
            Session::flash('error', 'Selecione um motivo válido para o cancelamento.');
            return Response::redirect('/admin/pedidos');
        }
        if ($reason === 'other' && mb_strlen($details) < 3) {
            Session::flash('error', 'Descreva o motivo do cancelamento.');
            return Response::redirect('/admin/pedidos');
        }

        $pdo = Database::connection();

        try {
            try {
                (new PagarmeOrderReconciliationService(null, $pdo))->reconcileOrder($code);
            } catch (Throwable $exception) {
                Logger::exception($exception, ['order_code' => $code], 'pagarme_reconciliation');
            }

            $pdo->beginTransaction();

            $orderStatement = $pdo->prepare(
                'SELECT id,status FROM orders WHERE code=? LIMIT 1 FOR UPDATE'
            );
            $orderStatement->execute([$code]);
            $order = $orderStatement->fetch();

            if (!is_array($order)) {
                throw new RuntimeException('Pedido não encontrado.');
            }
            if ((string) $order['status'] === 'cancelled') {
                throw new RuntimeException('Este pedido já está cancelado.');
            }
            if ((string) $order['status'] !== 'pending_payment') {
                throw new RuntimeException(
                    'Somente pedidos aguardando pagamento podem ser cancelados manualmente por esta ação.'
                );
            }

            $paymentStatement = $pdo->prepare(
                'SELECT * FROM payments WHERE order_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $paymentStatement->execute([(int) $order['id']]);
            $payment = $paymentStatement->fetch();

            if (is_array($payment)) {
                $paymentStatus = strtolower((string) ($payment['status'] ?? ''));
                if (in_array($paymentStatus, ['paid', 'partially_refunded', 'refunded'], true)) {
                    throw new RuntimeException(
                        'O pagamento já foi confirmado. Use o fluxo de estorno antes de cancelar o pedido.'
                    );
                }
                if ($paymentStatus === 'processing') {
                    throw new RuntimeException(
                        'O pagamento ainda está em processamento. Aguarde a sincronização para evitar cancelar uma cobrança aprovada.'
                    );
                }

                $hasRemotePayment = trim((string) ($payment['external_order_id'] ?? '')) !== ''
                    || trim((string) ($payment['external_charge_id'] ?? '')) !== ''
                    || trim((string) ($payment['external_checkout_id'] ?? '')) !== '';
                $expiresAt = trim((string) ($payment['expires_at'] ?? ''));
                $expired = $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) <= time();

                if (in_array($paymentStatus, ['pending', 'waiting_payment'], true)
                    && $hasRemotePayment
                    && !$expired) {
                    throw new RuntimeException(
                        'Esta cobrança ainda pode receber pagamento. Aguarde a expiração ou confirme a situação na Pagar.me antes de cancelar.'
                    );
                }
            }

            (new OrderInventoryService())->release($pdo, (int) $order['id']);
            (new OrderCouponService())->release($pdo, (int) $order['id']);

            if (is_array($payment)) {
                if (!in_array((string) $payment['status'], ['expired', 'cancelled'], true)) {
                    $pdo->prepare(
                        "UPDATE payments
                            SET status='cancelled'
                          WHERE id=? AND status NOT IN ('paid','partially_refunded','refunded')"
                    )->execute([(int) $payment['id']]);
                }
                (new MarketplaceFinancialLedgerService($pdo))->voidPending((int) $payment['id']);
            }

            $pdo->prepare(
                "UPDATE orders SET status='cancelled' WHERE id=? AND status='pending_payment'"
            )->execute([(int) $order['id']]);
            $pdo->prepare(
                "UPDATE seller_orders SET status='cancelled'
                  WHERE order_id=? AND status='pending_payment'"
            )->execute([(int) $order['id']]);

            $note = 'Cancelamento manual: ' . $reasons[$reason];
            if ($details !== '') {
                $note .= ' — ' . $details;
            }
            $pdo->prepare(
                "INSERT INTO order_status_history(order_id,status,notes,created_by)
                 VALUES(?,'cancelled',?,?)"
            )->execute([(int) $order['id'], mb_substr($note, 0, 500), Auth::id()]);

            $pdo->commit();
            Session::flash(
                'success',
                'Pedido ' . $code . ' cancelado. Estoque e cupom foram liberados e o motivo ficou registrado no histórico.'
            );
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Session::flash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::exception($exception, ['order_code' => $code], 'admin_order_cancel');
            Session::flash('error', 'Não foi possível cancelar o pedido agora. Tente novamente.');
        }

        return Response::redirect('/admin/pedidos');
    }

    public function sync(string $code, string $shipmentId): string
    {
        $statement = Database::connection()->prepare(
            'SELECT sh.id FROM shipments sh
             JOIN seller_orders so ON so.id=sh.seller_order_id
             JOIN orders o ON o.id=so.order_id
             WHERE o.code=? AND sh.id=?'
        );
        $statement->execute([$code, (int) $shipmentId]);
        try {
            $id = (int) $statement->fetchColumn();
            if (!$id) {
                throw new RuntimeException('Remessa não encontrada.');
            }
            (new MelhorEnvioTrackingService())->syncShipment($id, true);
            Session::flash('success', 'Rastreamento sincronizado com o Melhor Envio.');
        } catch (Throwable $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect('/admin/pedidos/' . $code);
    }

    public function replaceShipment(string $code, string $shipmentId): string
    {
        $id = (int) $shipmentId;
        $serviceId = trim((string) ($_POST['service_id'] ?? ''));
        $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 500);
        $confirmed = !empty($_POST['confirm_replacement']);
        $redirect = '/admin/pedidos/' . rawurlencode($code) . '#remessa-' . $id;

        if (!$confirmed) {
            Session::flash('error', 'Confirme que deseja cancelar a etiqueta atual e gerar uma nova.');
            return Response::redirect($redirect);
        }
        if ($serviceId === '') {
            Session::flash('error', 'Selecione a nova transportadora/modalidade.');
            return Response::redirect($redirect);
        }
        if (mb_strlen($reason) < 5) {
            Session::flash('error', 'Informe brevemente o motivo da troca de transportadora.');
            return Response::redirect($redirect);
        }

        $statement = Database::connection()->prepare(
            'SELECT sh.id FROM shipments sh
             JOIN seller_orders so ON so.id=sh.seller_order_id
             JOIN orders o ON o.id=so.order_id
             WHERE o.code=? AND sh.id=? LIMIT 1'
        );
        $statement->execute([$code, $id]);
        if ((int) $statement->fetchColumn() !== $id || $id < 1) {
            Session::flash('error', 'Remessa não encontrada neste pedido.');
            return Response::redirect('/admin/pedidos/' . rawurlencode($code));
        }

        try {
            $result = (new MelhorEnvioLabelReplacementService())->replace(
                $id,
                $serviceId,
                (int) Auth::id(),
                $reason
            );
            $cost = $result['actual_cost'] ?? $result['expected_cost'];
            Session::flash(
                'success',
                'Transportadora alterada para ' . $result['carrier'] . ' · ' . $result['service']
                . '. Nova etiqueta ' . ($result['status'] === 'ready' ? 'pronta' : 'em processamento')
                . ' (custo R$ ' . number_format((float) $cost, 2, ',', '.') . '). O pedido, o valor cobrado do cliente e a NF-e foram mantidos.'
            );
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            Logger::exception($exception, ['order_code' => $code, 'shipment_id' => $id], 'admin_shipping_replacement');
            Session::flash('error', 'Não foi possível trocar a transportadora desta remessa agora.');
        }
        return Response::redirect($redirect);
    }

    public function refundPix(string $code, string $paymentId): string
    {
        $statement = Database::connection()->prepare(
            "SELECT p.id FROM payments p JOIN orders o ON o.id=p.order_id
             WHERE o.code=? AND p.id=? AND p.provider='pagarme'
               AND p.integration_type='orders' AND p.method='pix'"
        );
        $statement->execute([$code, (int) $paymentId]);
        try {
            $id = (int) $statement->fetchColumn();
            if ($id < 1) {
                throw new RuntimeException('Pagamento Pix não encontrado neste pedido.');
            }
            (new PagarmePixRefundService())->refundFull($id);
            Session::flash('success', 'Solicitação de estorno integral enviada. A confirmação final virá da Pagar.me.');
        } catch (Throwable $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect('/admin/pedidos/' . rawurlencode($code));
    }

    /** @return array<string,string> */
    private function cancellationReasons(): array
    {
        return [
            'test_error' => 'Teste / homologação com erro',
            'payment_error' => 'Falha no pagamento',
            'duplicate' => 'Pedido duplicado',
            'operational_error' => 'Erro operacional',
            'customer_request' => 'Solicitação do cliente',
            'other' => 'Outro motivo',
        ];
    }
}
