<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Fiscal\FiscalDocumentStorage;
use App\Services\Payments\PendingPaymentRecoveryService;
use RuntimeException;
use Throwable;

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
            $fiscalStatement = $pdo->prepare("SELECT id,status,number,series,access_key,protocol,xml_storage_path,danfe_storage_path,authorized_at,cancelled_at,cancellation_reason FROM fiscal_documents WHERE seller_order_id=? AND status IN ('authorized','cancelled') ORDER BY revision DESC,id DESC");
            foreach ($sellerOrders as &$sellerOrder) {
                $itemStatement->execute([$sellerOrder['id']]);
                $sellerOrder['items'] = $itemStatement->fetchAll();
                $fiscalStatement->execute([$sellerOrder['id']]);
                $sellerOrder['fiscal_documents'] = $fiscalStatement->fetchAll();
            }
            unset($sellerOrder);
            $paymentStatement = $pdo->prepare(
                "SELECT p.method,p.status,p.integration_type,p.checkout_url,p.expires_at,
                        p.pix_qr_code,p.pix_qr_code_url,p.pix_expires_at,
                        aj.status async_status,aj.attempts async_attempts,aj.max_attempts async_max_attempts
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
            if (is_array($payment) && !$this->trustedPaymentUrl((string) ($payment['checkout_url'] ?? ''))) $payment['checkout_url'] = null;
            if (is_array($payment) && !$this->trustedPaymentUrl((string) ($payment['pix_qr_code_url'] ?? ''))) $payment['pix_qr_code_url'] = null;
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

    public function refreshPayment(string $code): string
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT id,status FROM orders WHERE user_id=? AND code=? LIMIT 1');
        $statement->execute([Auth::id(), $code]);
        $order = $statement->fetch();
        if (!is_array($order)) {
            http_response_code(404);
            Session::flash('error', 'Pedido não encontrado.');
            return Response::redirect('/minha-conta/pedidos');
        }
        if ((string) $order['status'] !== 'pending_payment') {
            Session::flash('success', 'O status deste pedido já foi atualizado.');
            return Response::redirect('/minha-conta/pedidos/' . rawurlencode($code));
        }

        try {
            $result = (new PendingPaymentRecoveryService($pdo))->recover((int) $order['id'], (int) Auth::id());
            $state = (string) ($result['state'] ?? 'processing');
            if (in_array($state, ['ready', 'order_updated'], true)) {
                Session::flash('success', 'Pagamento atualizado. Continue o pagamento abaixo.');
            } elseif ($state === 'processing') {
                Session::flash('success', 'Tentamos preparar o pagamento novamente. Se ainda não aparecer, aguarde alguns segundos e tente mais uma vez.');
            } elseif ($state === 'failed') {
                Session::flash('error', 'Não foi possível gerar esta cobrança automaticamente. Entre em contato com o suporte informando o código do pedido.');
            } else {
                Session::flash('error', 'Não encontramos uma cobrança recuperável para este pedido. Entre em contato com o suporte informando o código do pedido.');
            }
        } catch (Throwable) {
            Session::flash('error', 'Não foi possível atualizar o pagamento agora. Aguarde alguns instantes e tente novamente.');
        }

        return Response::redirect('/minha-conta/pedidos/' . rawurlencode($code));
    }

    public function downloadFiscalXml(string $code, string $id): string
    {
        return $this->downloadFiscal($code, (int) $id, 'xml');
    }

    public function downloadFiscalDanfe(string $code, string $id): string
    {
        return $this->downloadFiscal($code, (int) $id, 'danfe');
    }

    private function downloadFiscal(string $code, int $documentId, string $type): string
    {
        $column = $type === 'xml' ? 'xml_storage_path' : 'danfe_storage_path';
        $stmt = Database::connection()->prepare("SELECT fd.id,fd.access_key,fd.{$column} storage_path FROM fiscal_documents fd JOIN seller_orders so ON so.id=fd.seller_order_id JOIN orders o ON o.id=so.order_id WHERE fd.id=? AND o.code=? AND o.user_id=? AND fd.status IN ('authorized','cancelled') LIMIT 1");
        $stmt->execute([$documentId, $code, Auth::id()]);
        $document = $stmt->fetch();
        if (!is_array($document) || empty($document['storage_path'])) {
            Session::flash('error', 'Este arquivo fiscal ainda não está disponível.');
            return Response::redirect('/minha-conta/pedidos/' . rawurlencode($code));
        }
        try {
            $path = (new FiscalDocumentStorage())->path((string) $document['storage_path']);
            $key = preg_replace('/\D+/', '', (string) ($document['access_key'] ?? '')) ?: (string) $document['id'];
            return Response::privateFile($path, $type === 'xml' ? 'application/xml; charset=utf-8' : 'application/pdf', 'nfe-' . $key . '.' . ($type === 'xml' ? 'xml' : 'pdf'));
        } catch (RuntimeException) {
            Session::flash('error', 'O arquivo fiscal não foi encontrado no armazenamento privado.');
            return Response::redirect('/minha-conta/pedidos/' . rawurlencode($code));
        }
    }

    private function trustedPaymentUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return ($parts['scheme'] ?? '') === 'https' && ($host === 'pagar.me' || str_ends_with($host, '.pagar.me'));
    }
}
