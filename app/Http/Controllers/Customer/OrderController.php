<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Core\Auth;
use App\Core\Database;
use App\Http\Controllers\Controller;

final class OrderController extends Controller
{
    public function index(): string
    {
        $type = ($_GET['tipo'] ?? '') === 'atacado' ? 'wholesale' : '';
        $sql = 'SELECT code,grand_total,status,created_at,order_type FROM orders WHERE user_id=?';
        if ($type !== '') $sql .= ' AND order_type=?';
        $sql .= ' ORDER BY created_at DESC';
        $statement = Database::connection()->prepare($sql);
        $statement->execute($type !== '' ? [Auth::id(), $type] : [Auth::id()]);
        return $this->page('customer/orders/index', 'layouts/customer', ['pageTitle' => $type ? 'Pedidos de atacado' : 'Meus pedidos', 'orders' => $statement->fetchAll()]);
    }

    public function show(string $code): string
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM orders WHERE user_id=? AND code=?');
        $statement->execute([Auth::id(), $code]);
        $order = $statement->fetch();
        $sellerOrders = [];
        $payment = null;
        $address = null;
        if ($order) {
            $details = $pdo->prepare('SELECT so.*,st.name store_name FROM seller_orders so JOIN stores st ON st.id=so.store_id WHERE so.order_id=? ORDER BY so.id');
            $details->execute([$order['id']]);
            $sellerOrders = $details->fetchAll();
            $itemStatement = $pdo->prepare('SELECT * FROM order_items WHERE seller_order_id=? ORDER BY id');
            foreach ($sellerOrders as &$sellerOrder) {
                $itemStatement->execute([$sellerOrder['id']]);
                $sellerOrder['items'] = $itemStatement->fetchAll();
            }
            unset($sellerOrder);
            $paymentStatement = $pdo->prepare(
                "SELECT p.method,p.status,p.integration_type,p.checkout_url,p.expires_at,
                        p.pix_qr_code,p.pix_qr_code_url,p.pix_expires_at,aj.status async_status
                 FROM payments p
                 LEFT JOIN async_jobs aj ON aj.unique_key=CASE
                    WHEN p.integration_type='orders' THEN CONCAT('pagarme-order:',p.id)
                    ELSE CONCAT('pagarme-payment-link:',p.id)
                 END
                 WHERE p.order_id=?
                 ORDER BY p.id DESC LIMIT 1"
            );
            $paymentStatement->execute([$order['id']]);
            $payment = $paymentStatement->fetch() ?: null;
            if (is_array($payment) && !$this->trustedPaymentUrl((string) ($payment['checkout_url'] ?? ''))) {
                $payment['checkout_url'] = null;
            }
            if (is_array($payment) && !$this->trustedPaymentUrl((string) ($payment['pix_qr_code_url'] ?? ''))) {
                $payment['pix_qr_code_url'] = null;
            }
            $addressStatement = $pdo->prepare('SELECT * FROM order_addresses WHERE order_id=?');
            $addressStatement->execute([$order['id']]);
            $address = $addressStatement->fetch() ?: null;
        }
        return $this->page('customer/orders/show', 'layouts/customer', [
            'pageTitle' => "Pedido {$code}",
            'order' => $order,
            'sellerOrders' => $sellerOrders,
            'payment' => $payment,
            'address' => $address,
        ]);
    }

    private function trustedPaymentUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return ($parts['scheme'] ?? '') === 'https' && ($host === 'pagar.me' || str_ends_with($host, '.pagar.me'));
    }
}
