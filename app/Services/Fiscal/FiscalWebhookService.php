<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Database;
use App\Services\Queue\JobQueue;
use PDO;
use RuntimeException;
use Throwable;

final class FiscalWebhookService
{
    public function __construct(
        private readonly ?PDO $database = null,
        private readonly ?FiscalWebhookSecretService $secrets = null,
        private readonly ?FiscalWebhookEndpointPolicy $endpointPolicy = null,
    ) {}

    /** @return array<string,mixed>|null */
    public function metadata(int $storeId, int $sellerId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT id,store_id,seller_id,endpoint_url,secret_prefix,enabled,last_success_at,last_failure_at,last_http_status,last_error,created_at,updated_at FROM store_fiscal_webhook_configs WHERE store_id=? AND seller_id=? LIMIT 1');
        $stmt->execute([$storeId,$sellerId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function configure(int $storeId, int $sellerId, string $endpointUrl): string
    {
        $resolved = $this->policy()->validate($endpointUrl);
        $secret = $this->secretService()->generate();
        $encrypted = $this->secretService()->encrypt($secret);
        $prefix = $this->secretService()->prefix($secret);
        $this->pdo()->prepare('INSERT INTO store_fiscal_webhook_configs(store_id,seller_id,endpoint_url,secret_ciphertext,secret_prefix,enabled,last_error) VALUES(?,?,?,?,?,1,NULL) ON DUPLICATE KEY UPDATE seller_id=VALUES(seller_id),endpoint_url=VALUES(endpoint_url),secret_ciphertext=VALUES(secret_ciphertext),secret_prefix=VALUES(secret_prefix),enabled=1,last_error=NULL')
            ->execute([$storeId,$sellerId,$resolved['url'],$encrypted,$prefix]);
        return $secret;
    }

    public function disable(int $storeId, int $sellerId): void
    {
        $this->pdo()->prepare('UPDATE store_fiscal_webhook_configs SET enabled=0 WHERE store_id=? AND seller_id=?')->execute([$storeId,$sellerId]);
    }

    public function enqueueReady(int $sellerOrderId): ?int
    {
        if ($sellerOrderId < 1) return null;
        $pdo = $this->pdo();
        $stmt = $pdo->prepare("SELECT so.id,so.store_id,so.seller_id,sfp.issuance_mode,w.endpoint_url,w.enabled
            FROM seller_orders so
            JOIN store_fiscal_profiles sfp ON sfp.store_id=so.store_id AND sfp.seller_id=so.seller_id
            LEFT JOIN store_fiscal_webhook_configs w ON w.store_id=so.store_id AND w.seller_id=so.seller_id
            WHERE so.id=? LIMIT 1");
        $stmt->execute([$sellerOrderId]);
        $row = $stmt->fetch();
        if (!is_array($row) || (string)$row['issuance_mode'] !== FiscalIssuanceMode::EXTERNAL || !(bool)($row['enabled'] ?? false) || empty($row['endpoint_url'])) return null;

        $eventId = $this->uuid();
        try {
            $pdo->prepare("INSERT INTO fiscal_webhook_deliveries(event_id,store_id,seller_id,seller_order_id,event_type,status) VALUES(?,?,?,?,?,'pending')")
                ->execute([$eventId,$row['store_id'],$row['seller_id'],$sellerOrderId,'fiscal.seller_order.ready']);
            $deliveryId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            $stmt = $pdo->prepare('SELECT id FROM fiscal_webhook_deliveries WHERE seller_order_id=? AND event_type=? LIMIT 1');
            $stmt->execute([$sellerOrderId,'fiscal.seller_order.ready']);
            $deliveryId = (int)$stmt->fetchColumn();
            if ($deliveryId < 1) throw $e;
        }
        (new JobQueue($pdo))->dispatch('fiscal.deliver_webhook',['delivery_id'=>$deliveryId],'fiscal-webhook:'.$deliveryId,'fiscal',8,50);
        return $deliveryId;
    }

    public function deliver(int $deliveryId): void
    {
        if ($deliveryId < 1) throw new RuntimeException('Entrega fiscal inválida.');
        $pdo = $this->pdo();
        $stmt = $pdo->prepare("SELECT d.*,w.endpoint_url,w.secret_ciphertext,w.enabled webhook_enabled,so.code seller_order_code
            FROM fiscal_webhook_deliveries d
            JOIN store_fiscal_webhook_configs w ON w.store_id=d.store_id AND w.seller_id=d.seller_id
            JOIN seller_orders so ON so.id=d.seller_order_id
            WHERE d.id=? LIMIT 1");
        $stmt->execute([$deliveryId]);
        $delivery = $stmt->fetch();
        if (!is_array($delivery)) throw new RuntimeException('Entrega de webhook fiscal não encontrada.');
        if ((string)$delivery['status'] === 'delivered') return;
        if (!(bool)$delivery['webhook_enabled']) throw new RuntimeException('Webhook fiscal desta loja está desabilitado.');

        $resolved = $this->policy()->validate((string)$delivery['endpoint_url']);
        $base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        if ($base === '') throw new RuntimeException('APP_URL precisa estar configurada para webhooks fiscais.');
        $payload = [
            'id'=>(string)$delivery['event_id'],
            'type'=>(string)$delivery['event_type'],
            'created_at'=>(string)$delivery['created_at'],
            'data'=>[
                'seller_order_code'=>(string)$delivery['seller_order_code'],
                'resource_url'=>$base . '/api/v1/fiscal/seller-orders/' . rawurlencode((string)$delivery['seller_order_code']) . '/payload',
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $timestamp = time();
        $secret = $this->secretService()->decrypt((string)$delivery['secret_ciphertext']);
        $signature = $this->secretService()->signature($secret,$timestamp,$body);

        $pdo->prepare("UPDATE fiscal_webhook_deliveries SET status='processing',attempts=attempts+1,last_attempt_at=NOW(),payload_sha256=? WHERE id=?")
            ->execute([hash('sha256',$body),$deliveryId]);

        $ch = curl_init($resolved['url']);
        if ($ch === false) throw new RuntimeException('Não foi possível iniciar a entrega do webhook fiscal.');
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$body,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>15,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json',
                'User-Agent: Tuffer-Fiscal-Webhook/1.0',
                'X-Tuffer-Event: '.(string)$delivery['event_id'],
                'X-Tuffer-Timestamp: '.$timestamp,
                'X-Tuffer-Signature: '.$signature,
            ],
            CURLOPT_RESOLVE=>[$resolved['host'].':443:'.$resolved['ip']],
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error !== '' || $status < 200 || $status >= 300) {
            $message = $error !== '' ? $error : 'ERP respondeu HTTP '.$status.'.';
            $pdo->prepare("UPDATE fiscal_webhook_deliveries SET status='failed',http_status=?,last_error=? WHERE id=?")->execute([$status?:null,mb_substr($message,0,1000),$deliveryId]);
            $pdo->prepare('UPDATE store_fiscal_webhook_configs SET last_failure_at=NOW(),last_http_status=?,last_error=? WHERE store_id=? AND seller_id=?')->execute([$status?:null,mb_substr($message,0,1000),$delivery['store_id'],$delivery['seller_id']]);
            throw new RuntimeException('Falha ao entregar webhook fiscal: '.$message);
        }

        $pdo->prepare("UPDATE fiscal_webhook_deliveries SET status='delivered',http_status=?,last_error=NULL,delivered_at=NOW() WHERE id=?")->execute([$status,$deliveryId]);
        $pdo->prepare('UPDATE store_fiscal_webhook_configs SET last_success_at=NOW(),last_http_status=?,last_error=NULL WHERE store_id=? AND seller_id=?')->execute([$status,$delivery['store_id'],$delivery['seller_id']]);
    }

    private function pdo(): PDO { return $this->database ?? Database::connection(); }
    private function secretService(): FiscalWebhookSecretService { return $this->secrets ?? new FiscalWebhookSecretService(); }
    private function policy(): FiscalWebhookEndpointPolicy { return $this->endpointPolicy ?? new FiscalWebhookEndpointPolicy(); }

    private function uuid(): string
    {
        $b = random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
