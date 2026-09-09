<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Core\Database;
use App\Services\Fiscal\FiscalOrchestratorService;
use App\Services\Mail\PasswordResetMailService;
use App\Services\Payments\PagarmeClient;
use App\Services\Payments\PagarmeWebhookProcessor;
use App\Services\Payments\Pagarme\PagarmeOrderService;
use RuntimeException;

final class JobProcessor
{
    /** @param array<string,mixed> $job */
    public function process(array $job): void
    {
        $payload=$job['payload']??[];if(!is_array($payload))throw new RuntimeException('Payload do job inválido.');
        match((string)($job['job_type']??'')){
            'mail.send'=>$this->sendMail((int)($payload['delivery_id']??0)),
            'pagarme.create_payment_link'=>$this->createPaymentLink($payload),
            'pagarme.create_order'=>$this->createPagarmeOrder((int)($payload['payment_id']??0)),
            'pagarme.process_webhook'=>$this->processWebhook((int)($payload['webhook_id']??0)),
            'fiscal.sync_paid_order'=>(new FiscalOrchestratorService())->syncPaidOrder((int)($payload['order_id']??0)),
            'fiscal.review_refund'=>(new FiscalOrchestratorService())->reviewRefund((int)($payload['order_id']??0),(bool)($payload['full_refund']??false)),
            default=>throw new RuntimeException('Tipo de job não suportado.'),
        };
    }

    private function sendMail(int $deliveryId): void
    {
        $pdo=Database::connection();$s=$pdo->prepare('SELECT * FROM mail_deliveries WHERE id=?');$s->execute([$deliveryId]);$delivery=$s->fetch();if(!is_array($delivery))throw new RuntimeException('Entrega de e-mail não encontrada.');if($delivery['status']==='sent')return;$mailer=new PasswordResetMailService();$sent=$mailer->sendMessage((string)$delivery['recipient_name'],(string)$delivery['recipient_email'],(string)$delivery['subject'],(string)$delivery['message_body']);if(!$sent){$error=mb_substr((string)($mailer->lastError()??'Falha não identificada no SMTP.'),0,500);$pdo->prepare("UPDATE mail_deliveries SET status='failed',error_message=? WHERE id=?")->execute([$error,$deliveryId]);throw new RuntimeException($error);}$pdo->prepare("UPDATE mail_deliveries SET status='sent',message_body=NULL,error_message=NULL,sent_at=NOW() WHERE id=?")->execute([$deliveryId]);
    }

    private function createPaymentLink(array $payload): void
    {
        $paymentId=(int)($payload['payment_id']??0);$request=$payload['request']??null;$key=(string)($payload['idempotency_key']??'');if($paymentId<1||!is_array($request)||$key==='')throw new RuntimeException('Job de pagamento incompleto.');$pdo=Database::connection();$s=$pdo->prepare("SELECT p.id,p.status,p.checkout_url,o.id order_id,o.code order_code FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.id=?");$s->execute([$paymentId]);$payment=$s->fetch();if(!is_array($payment))throw new RuntimeException('Pagamento não encontrado.');if(!empty($payment['checkout_url'])||$payment['status']!=='pending')return;$link=(new PagarmeClient())->createPaymentLink($request,$key);$encoded=json_encode($link,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$pdo->prepare('UPDATE payments SET external_checkout_id=?,checkout_url=?,provider_payload=? WHERE id=? AND checkout_url IS NULL')->execute([(string)$link['id'],(string)$link['url'],$encoded,$paymentId]);$pdo->prepare("INSERT INTO order_status_history(order_id,status,notes) VALUES(?,'pending_payment','Link seguro de pagamento gerado pela fila.')")->execute([$payment['order_id']]);
    }

    private function createPagarmeOrder(int $paymentId): void{if($paymentId<1)throw new RuntimeException('Job de pedido Pagar.me incompleto.');(new PagarmeOrderService())->createPixOrder($paymentId);}

    private function processWebhook(int $webhookId): void
    {
        if($webhookId<1)throw new RuntimeException('Webhook enfileirado inválido.');(new PagarmeWebhookProcessor())->processStored($webhookId);$pdo=Database::connection();$s=$pdo->prepare('SELECT order_id,event_type FROM payment_webhooks WHERE id=? LIMIT 1');$s->execute([$webhookId]);$webhook=$s->fetch();if(!is_array($webhook)||(int)($webhook['order_id']??0)<1)return;$orderId=(int)$webhook['order_id'];$s=$pdo->prepare('SELECT status FROM orders WHERE id=? LIMIT 1');$s->execute([$orderId]);$orderStatus=(string)$s->fetchColumn();$queue=new JobQueue($pdo);
        if(in_array($orderStatus,['paid','processing','completed'],true))$queue->dispatch('fiscal.sync_paid_order',['order_id'=>$orderId],'fiscal-sync:'.$orderId.':webhook:'.$webhookId,'fiscal',8,40);
        $eventType=mb_strtolower((string)($webhook['event_type']??''));if(str_contains($eventType,'refund'))$queue->dispatch('fiscal.review_refund',['order_id'=>$orderId,'full_refund'=>!str_contains($eventType,'partial')],'fiscal-refund:'.$orderId.':webhook:'.$webhookId,'fiscal',8,30);
    }
}
