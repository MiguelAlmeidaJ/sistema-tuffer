<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Services\Orders\OrderCouponService;
use App\Services\Orders\OrderInventoryService;
use PDO;
use RuntimeException;

/**
 * Aprovação excepcional de recebimento externo para links Pagar.me rejeitados
 * pelo erro conhecido "The type field is required.".
 * Nunca fabrica confirmação do gateway, charge ou split financeiro.
 */
final class ManualPaymentApprovalService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function approve(string $code, int $adminId, string $reference, string $reason): void
    {
        $reference = trim($reference);
        $reason = trim($reason);
        if ($adminId < 1 || mb_strlen($reference) < 6 || mb_strlen($reference) > 150) {
            throw new RuntimeException('Informe a referência verificável do recebimento (6 a 150 caracteres).');
        }
        if (mb_strlen($reason) < 12 || mb_strlen($reason) > 500) {
            throw new RuntimeException('Informe uma justificativa de 12 a 500 caracteres.');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('A aprovação exige uma transação independente.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT id,user_id,code,status,grand_total FROM orders WHERE code=? FOR UPDATE");
            $stmt->execute([$code]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order) || $order['status'] !== 'pending_payment') {
                throw new RuntimeException('Pedido inexistente ou não está aguardando pagamento.');
            }
            $paymentStmt = $this->pdo->prepare("SELECT * FROM payments WHERE order_id=? ORDER BY id DESC FOR UPDATE");
            $paymentStmt->execute([(int) $order['id']]);
            $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($payments) !== 1) {
                throw new RuntimeException('Pedido com múltiplos pagamentos exige análise financeira antes da aprovação.');
            }
            $payment = $payments[0];
            if ((string) $payment['provider'] !== 'pagarme'
                || (string) $payment['integration_type'] !== 'payment_link'
                || (string) $payment['status'] !== 'pending'
                || (string) $payment['method'] !== 'pix'
                || (int) $payment['amount_cents'] !== (int) round((float) $order['grand_total'] * 100)
                || trim((string) ($payment['external_order_id'] ?? '')) !== ''
                || trim((string) ($payment['external_charge_id'] ?? '')) !== ''
                || trim((string) ($payment['external_checkout_id'] ?? '')) !== ''
                || trim((string) ($payment['checkout_url'] ?? '')) !== ''
                || trim((string) ($payment['pix_qr_code'] ?? '')) !== '') {
                throw new RuntimeException('Esta cobrança não é elegível para aprovação manual: verifique possíveis cobranças externas.');
            }
            $jobs = $this->pdo->prepare("SELECT * FROM async_jobs WHERE unique_key=? FOR UPDATE");
            $jobs->execute(['pagarme-payment-link:' . (int) $payment['id']]);
            $job = $jobs->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)
                || $job['job_type'] !== 'pagarme.create_payment_link'
                || !in_array($job['status'], ['pending','failed'], true)
                || (int) $job['attempts'] < 1
                || stripos((string) ($job['last_error'] ?? ''), 'The type field is required') === false) {
                throw new RuntimeException('Somente links com falha confirmada no campo type podem ser aprovados manualmente.');
            }
            $admin = $this->pdo->prepare("SELECT id FROM users WHERE id=? AND type='admin' AND status='active' FOR UPDATE");
            $admin->execute([$adminId]);
            if (!$admin->fetchColumn()) {
                throw new RuntimeException('Ação exclusiva de administrador ativo.');
            }
            // Invalidar a fila antes de alterar o pedido. Reserva simultânea é impedida por FOR UPDATE.
            $stop = $this->pdo->prepare("UPDATE async_jobs SET status='completed',payload=JSON_OBJECT(),completed_at=NOW(),reserved_at=NULL,reserved_by=NULL,last_error='Encerrado por aprovação manual auditada.' WHERE id=? AND status IN ('pending','failed')");
            $stop->execute([(int) $job['id']]);
            if ($stop->rowCount() !== 1) {
                throw new RuntimeException('A fila do gateway mudou de estado. Atualize a página.');
            }

            (new OrderInventoryService())->consume($this->pdo, (int) $order['id']);
            (new OrderCouponService())->redeem($this->pdo, (int) $order['id']);

            $this->pdo->prepare("UPDATE payments SET status='paid',paid_at=NOW() WHERE id=? AND status='pending'")
                ->execute([(int) $payment['id']]);
            $changed = $this->pdo->prepare("UPDATE orders SET status='paid' WHERE id=? AND status='pending_payment'");
            $changed->execute([(int) $order['id']]);
            if ($changed->rowCount() !== 1) {
                throw new RuntimeException('Pedido alterado durante a aprovação.');
            }
            $this->pdo->prepare("UPDATE seller_orders SET status='paid' WHERE order_id=? AND status='pending_payment'")
                ->execute([(int) $order['id']]);

            $this->pdo->prepare("INSERT INTO manual_payment_approvals(order_id,payment_id,approved_by,amount_cents,external_reference,reason) VALUES(?,?,?,?,?,?)")
                ->execute([(int) $order['id'], (int) $payment['id'], $adminId, (int) $payment['amount_cents'], $reference, $reason]);
            $this->pdo->prepare("INSERT INTO order_status_history(order_id,status,notes,created_by) VALUES(?,'paid',?,?)")
                ->execute([(int) $order['id'], 'Recebimento externo conferido e aprovado manualmente. Referência: ' . mb_substr($reference, 0, 150) . '. Não confirmado pelo gateway.', $adminId]);
            $this->pdo->prepare("INSERT INTO user_notifications(user_id,type,title,message,action_url) VALUES(?,'payment_paid',?,?,?)")
                ->execute([(int) $order['user_id'], 'Pagamento confirmado', 'O recebimento do pedido ' . $code . ' foi conferido pela equipe Tuffer.', '/minha-conta/pedidos/' . $code]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
