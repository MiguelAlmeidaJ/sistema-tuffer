<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Database;
use App\Services\Queue\JobQueue;
use PDO;
use RuntimeException;

final class FiscalOutboundWebhookService
{
    private readonly PDO $pdo;
    private readonly JobQueue $queue;
    private readonly FiscalWebhookSecretService $secrets;
    private readonly FiscalWebhookUrlPolicy $urls;

    public function __construct(?PDO $pdo = null, ?JobQueue $queue = null, ?FiscalWebhookSecretService $secrets = null, ?FiscalWebhookUrlPolicy $urls = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->queue = $queue ?? new JobQueue($this->pdo);
        $this->secrets = $secrets ?? new FiscalWebhookSecretService();
        $this->urls = $urls ?? new FiscalWebhookUrlPolicy();
    }

    /** @return array<string,mixed>|null */
    public function configuration(int $storeId, int $sellerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id,store_id,seller_id,endpoint_url,secret_prefix,enabled,last_success_at,last_failure_at,last_http_status,last_error,created_at,updated_at FROM store_fiscal_webhook_configs WHERE store_id=? AND seller_id=? LIMIT 1');
        $stmt->execute([$storeId,$sellerId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array{configuration:array<string,mixed>,generated_secret:?string} */
    public function saveConfiguration(int $storeId, int $sellerId, string $endpointUrl): array
    {
        $this->assertExternalStore($storeId,$sellerId);
        $validated = $this->urls->validate($endpointUrl);
        $stmt = $this->pdo->prepare('SELECT secret_ciphertext,secret_prefix FROM store_fiscal_webhook_configs WHERE store_id=? AND seller_id=? LIMIT 1');
        $stmt->execute([$storeId,$sellerId]);
        $existing = $stmt->fetch();
        $generated = null;
        if (is_array($existing) && !empty($existing['secret_ciphertext'])) {
            $ciphertext = (string)$existing['secret_ciphertext'];
            $prefix = (string)$existing['secret_prefix'];
        } else {
            $generated = $this->secrets->generate();
            $ciphertext = $this->secrets->encrypt($generated);
            $prefix = $this->secrets->prefix($generated);
        }

        $this->pdo->prepare("INSERT INTO store_fiscal_webhook_configs(store_id,seller_id,endpoint_url,secret_ciphertext,secret_prefix,enabled,last_error,last_http_status) VALUES(?,?,?,?,?,1,NULL,NULL)
            ON DUPLICATE KEY UPDATE seller_id=VALUES(seller_id),endpoint_url=VALUES(endpoint_url),secret_ciphertext=VALUES(secret_ciphertext),secret_prefix=VALUES(secret_prefix),enabled=1,last_error=NULL,last_http_status=NULL")
            ->execute([$storeId,$sellerId,$validated['url'],$ciphertext,$prefix]);

        return ['configuration'=>$this->configuration($storeId,$sellerId) ?? [],'generated_secret'=>$generated];
    }

    public function rotateSecret(int $storeId, int $sellerId): string
    {
        $this->assertExternalStore($storeId,$sellerId);
        $config = $this->configuration($storeId,$sellerId);
        if ($config === null) throw new RuntimeException('Configure primeiro a URL do webhook fiscal.');
        $secret = $this->secrets->generate();
        $this->pdo->prepare('UPDATE store_fiscal_webhook_configs SET secret_ciphertext=?,secret_prefix=?,enabled=1,last_error=NULL,last_http_status=NULL WHERE store_id=? AND seller_id=?')
            ->execute([$this->secrets->encrypt($secret),$this->secrets->prefix($secret),$storeId,$sellerId]);
        return $secret;
    }

    public function disable(int $storeId, int $sellerId): void
    {
        $this->pdo->prepare('UPDATE store_fiscal_webhook_configs SET enabled=0 WHERE store_id=? AND seller_id=?')->execute([$storeId,$sellerId]);
    }

    public function scheduleSellerOrderReady(int $sellerOrderId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT so.id,so.store_id,so.seller_id,fd.status document_status,sfp.enabled profile_enabled,sfp.issuance_mode,wc.enabled webhook_enabled
            FROM seller_orders so
            JOIN fiscal_documents fd ON fd.seller_order_id=so.id AND fd.document_type='nfe' AND fd.revision=1
            JOIN store_fiscal_profiles sfp ON sfp.store_id=so.store_id AND sfp.seller_id=so.seller_id
            LEFT JOIN store_fiscal_webhook_configs wc ON wc.store_id=so.store_id AND wc.seller_id=so.seller_id
            WHERE so.id=? LIMIT 1");
        $stmt->execute([$sellerOrderId]);
        $row = $stmt->fetch();
        if (!is_array($row) || !(bool)$row['profile_enabled'] || (string)$row['issuance_mode'] !== FiscalIssuanceMode::EXTERNAL || !(bool)($row['webhook_enabled'] ?? false)) return null;
        if (!in_array((string)$row['document_status'], ['awaiting_external','authorized','cancelled'], true)) return null;

        $eventType = 'fiscal.seller_order.ready';
        $eventId = $this->uuid4();
        $this->pdo->prepare("INSERT INTO fiscal_webhook_deliveries(event_id,store_id,seller_id,seller_order_id,event_type,status)
            VALUES(?,?,?,?,?,'pending') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)")
            ->execute([$eventId,$row['store_id'],$row['seller_id'],$sellerOrderId,$eventType]);
        $deliveryId = (int)$this->pdo->lastInsertId();
        if ($deliveryId < 1) {
            $find = $this->pdo->prepare('SELECT id FROM fiscal_webhook_deliveries WHERE seller_order_id=? AND event_type=? LIMIT 1');
            $find->execute([$sellerOrderId,$eventType]);
            $deliveryId = (int)$find->fetchColumn();
        }
        if ($deliveryId < 1) throw new RuntimeException('Não foi possível criar a entrega do webhook fiscal.');

        $status = $this->pdo->prepare('SELECT status FROM fiscal_webhook_deliveries WHERE id=?');
        $status->execute([$deliveryId]);
        if ((string)$status->fetchColumn() === 'pending') {
            $this->queue->dispatch('fiscal.deliver_webhook',['delivery_id'=>$deliveryId],'fiscal-webhook:'.$deliveryId,'fiscal',8,35);
        }
        return $deliveryId;
    }

    public function requeue(int $deliveryId, int $storeId, int $sellerId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM fiscal_webhook_deliveries WHERE id=? AND store_id=? AND seller_id=? LIMIT 1');
        $stmt->execute([$deliveryId,$storeId,$sellerId]);
        if (!(int)$stmt->fetchColumn()) throw new RuntimeException('Entrega fiscal não encontrada nesta loja.');
        $this->pdo->prepare("UPDATE fiscal_webhook_deliveries SET status='pending',http_status=NULL,last_error=NULL WHERE id=?")->execute([$deliveryId]);
        $this->queue->dispatch('fiscal.deliver_webhook',['delivery_id'=>$deliveryId],'fiscal-webhook:'.$deliveryId.':manual:'.time(),'fiscal',8,35);
    }

    public function deliver(int $deliveryId): void
    {
        $stmt = $this->pdo->prepare("SELECT d.*,wc.endpoint_url,wc.secret_ciphertext,wc.enabled webhook_enabled,so.code seller_order_code,o.code order_code
            FROM fiscal_webhook_deliveries d
            JOIN store_fiscal_webhook_configs wc ON wc.store_id=d.store_id AND wc.seller_id=d.seller_id
            JOIN seller_orders so ON so.id=d.seller_order_id
            JOIN orders o ON o.id=so.order_id
            WHERE d.id=? LIMIT 1");
        $stmt->execute([$deliveryId]);
        $delivery = $stmt->fetch();
        if (!is_array($delivery)) throw new RuntimeException('Entrega de webhook fiscal não encontrada.');
        if ((string)$delivery['status'] === 'delivered') return;
        if (!(bool)$delivery['webhook_enabled']) throw new RuntimeException('Webhook fiscal desabilitado para esta loja.');

        $target = $this->urls->validate((string)$delivery['endpoint_url']);
        $secret = $this->secrets->decrypt((string)$delivery['secret_ciphertext']);
        $base = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
        if ($base === '') throw new RuntimeException('APP_URL precisa estar configurada para os webhooks fiscais.');
        $code = rawurlencode((string)$delivery['seller_order_code']);
        $resource = $base . '/api/v1/fiscal/seller-orders/' . $code;
        $payload = [
            'id'=>(string)$delivery['event_id'],
            'type'=>(string)$delivery['event_type'],
            'schema_version'=>'1.0',
            'occurred_at'=>gmdate('c'),
            'data'=>[
                'seller_order_code'=>(string)$delivery['seller_order_code'],
                'order_code'=>(string)$delivery['order_code'],
                'resource_url'=>$resource,
                'authorize_url'=>$resource . '/authorize',
                'cancel_url'=>$resource . '/cancel',
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = $this->secrets->signature($secret,$timestamp,$body);
        $this->pdo->prepare("UPDATE fiscal_webhook_deliveries SET status='processing',attempts=attempts+1,payload_sha256=?,last_attempt_at=NOW(),last_error=NULL WHERE id=?")
            ->execute([hash('sha256',$body),$deliveryId]);

        [$httpStatus,$error] = $this->post($target,$body,$timestamp,$signature,(string)$delivery['event_id'],(string)$delivery['event_type']);
        if ($error !== null) {
            $this->markFailure($deliveryId,(int)$delivery['store_id'],null,$error);
            throw new RuntimeException($error);
        }
        if ($httpStatus >= 200 && $httpStatus < 300) {
            $this->pdo->prepare("UPDATE fiscal_webhook_deliveries SET status='delivered',http_status=?,last_error=NULL,delivered_at=NOW() WHERE id=?")->execute([$httpStatus,$deliveryId]);
            $this->pdo->prepare('UPDATE store_fiscal_webhook_configs SET last_success_at=NOW(),last_http_status=?,last_error=NULL WHERE store_id=?')->execute([$httpStatus,$delivery['store_id']]);
            return;
        }

        $message = 'Endpoint fiscal respondeu HTTP ' . $httpStatus . '.';
        $this->markFailure($deliveryId,(int)$delivery['store_id'],$httpStatus,$message);
        if ($httpStatus >= 500 || in_array($httpStatus,[408,425,429],true)) throw new RuntimeException($message);
    }

    /** @param array{url:string,host:string,port:int,ip:string} $target @return array{0:int,1:?string} */
    private function post(array $target,string $body,int $timestamp,string $signature,string $eventId,string $eventType): array
    {
        if (!function_exists('curl_init')) return [0,'Extensão cURL indisponível para entrega do webhook fiscal.'];
        $response = '';
        $ch = curl_init($target['url']);
        if ($ch === false) return [0,'Não foi possível iniciar a entrega do webhook fiscal.'];
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Tuffer-Event: '.$eventType,
                'X-Tuffer-Event-Id: '.$eventId,
                'X-Tuffer-Timestamp: '.$timestamp,
                'X-Tuffer-Signature: '.$signature,
            ],
            CURLOPT_USERAGENT=>'Tuffer-Fiscal-Webhook/1.0',
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>12,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_RESOLVE=>[$target['host'].':'.$target['port'].':'.$target['ip']],
            CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$response): int {
                if (strlen($response) < 4096) $response .= substr($chunk,0,4096-strlen($response));
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? mb_substr((string)curl_error($ch),0,500) : null;
        curl_close($ch);
        return [$status,$error !== null && $error !== '' ? 'Falha de rede no webhook fiscal: '.$error : null];
    }

    private function markFailure(int $deliveryId,int $storeId,?int $httpStatus,string $message): void
    {
        $message = mb_substr($message,0,1000);
        $this->pdo->prepare("UPDATE fiscal_webhook_deliveries SET status='failed',http_status=?,last_error=? WHERE id=?")->execute([$httpStatus,$message,$deliveryId]);
        $this->pdo->prepare('UPDATE store_fiscal_webhook_configs SET last_failure_at=NOW(),last_http_status=?,last_error=? WHERE store_id=?')->execute([$httpStatus,$message,$storeId]);
    }

    private function assertExternalStore(int $storeId,int $sellerId): void
    {
        $stmt = $this->pdo->prepare("SELECT enabled,issuance_mode FROM store_fiscal_profiles WHERE store_id=? AND seller_id=? LIMIT 1");
        $stmt->execute([$storeId,$sellerId]);
        $profile = $stmt->fetch();
        if (!is_array($profile) || !(bool)$profile['enabled'] || (string)$profile['issuance_mode'] !== FiscalIssuanceMode::EXTERNAL) {
            throw new RuntimeException('Configure esta loja no modo Integração externa / ERP antes de habilitar o webhook.');
        }
    }

    private function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
