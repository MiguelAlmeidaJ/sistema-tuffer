<?php
declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Payments\PendingPaymentRecoveryService;
use PDO;
use Throwable;

final class CheckoutConfirmationController extends Controller
{
    public function show(string $code): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE code=? AND user_id=? LIMIT 1');
        $stmt->execute([$code, Auth::id()]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            http_response_code(404);
            return $this->page('public/checkout/order-confirmation', 'layouts/public', [
                'pageTitle' => 'Pedido não encontrado', 'order' => null,
                'payment' => null, 'items' => [],
            ]);
        }
        $stmt = $pdo->prepare(
            "SELECT p.provider,p.method,p.status,p.checkout_url,p.external_order_id,
                    p.pix_qr_code,p.asaas_pix_image,p.pix_expires_at,
                    aj.status job_status,aj.attempts job_attempts
             FROM payments p
             LEFT JOIN async_jobs aj ON aj.unique_key=CONCAT('asaas-payment:',p.id)
             WHERE p.order_id=? ORDER BY p.id DESC LIMIT 1"
        );
        $stmt->execute([(int) $order['id']]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (is_array($payment) && !\App\Services\Payments\Asaas\AsaasChargeService::trustedInvoiceUrl((string) ($payment['checkout_url'] ?? ''))) {
            $payment['checkout_url'] = null;
        }
        $itemsStmt = $pdo->prepare(
            'SELECT oi.product_name,oi.quantity,oi.unit_price,oi.total
             FROM order_items oi JOIN seller_orders so ON so.id=oi.seller_order_id
             WHERE so.order_id=? ORDER BY oi.id'
        );
        $itemsStmt->execute([(int) $order['id']]);
        return $this->page('public/checkout/order-confirmation', 'layouts/public', [
            'pageTitle' => 'Confirmação do pedido', 'order' => $order,
            'payment' => $payment, 'items' => $itemsStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    public function refresh(string $code): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id,status FROM orders WHERE code=? AND user_id=? LIMIT 1');
        $stmt->execute([$code, Auth::id()]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            http_response_code(404);
            return Response::redirect('/minha-conta/pedidos');
        }
        if ($order['status'] === 'pending_payment') {
            try {
                $result = (new PendingPaymentRecoveryService($pdo))->recover((int) $order['id'], (int) Auth::id());
                $state = (string) ($result['state'] ?? '');
                if ($state === 'ready') {
                    Session::flash('success', 'Sua cobrança está disponível. Confira os dados de pagamento.');
                } elseif ($state === 'failed') {
                    Session::flash('error', 'Não foi possível concluir a geração do Pix. A cobrança precisa ser verificada; não crie outro pedido.');
                } elseif ($state === 'processing') {
                    Session::flash('guide', 'A cobrança está em processamento. Você pode atualizar este pedido novamente.');
                } else {
                    Session::flash('guide', 'Ainda não foi possível recuperar o Pix. O pedido permanece registrado.');
                }
            } catch (Throwable) {
                Session::flash('error', 'Falha ao consultar a cobrança. Seu pedido continua registrado e não será duplicado.');
            }
        }
        return Response::redirect('/checkout/pedido/' . rawurlencode($code));
    }
}
