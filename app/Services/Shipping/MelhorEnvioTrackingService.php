<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Database;
use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;
use App\Services\Settings\PlatformSettings;

final class MelhorEnvioTrackingService
{
    public function __construct(private readonly ?PDO $database = null)
    {
    }

    public function configured(): bool
    {
        return $this->token() !== '' && PlatformSettings::enabled('melhor_envio_enabled');
    }

    /** @return array<string,mixed> */
    public function syncShipment(int $shipmentId, bool $force = false): array
    {
        $pdo = $this->database ?? Database::connection();
        $statement = $pdo->prepare('SELECT sh.*,so.order_id FROM shipments sh JOIN seller_orders so ON so.id=sh.seller_order_id WHERE sh.id=?');
        $statement->execute([$shipmentId]);
        $shipment = $statement->fetch();
        if (!is_array($shipment)) throw new RuntimeException('Remessa não encontrada.');
        if (!$this->configured()) throw new RuntimeException('O token do Melhor Envio não está configurado.');
        if (trim((string) ($shipment['external_id'] ?? '')) === '') throw new RuntimeException('Informe o ID da etiqueta do Melhor Envio antes de sincronizar.');
        if (!$force && $shipment['last_synced_at'] && strtotime((string) $shipment['last_synced_at']) > time() - 300) return $shipment;

        $response = $this->request([(string) $shipment['external_id']]);
        $tracking = $this->trackingFor($response, (string) $shipment['external_id']);
        if ($tracking === null) throw new RuntimeException('O Melhor Envio não retornou dados para esta etiqueta.');

        $rawStatus = strtolower((string) ($tracking['status'] ?? $tracking['state'] ?? 'pending'));
        $status = $this->localStatus($rawStatus);
        $trackingCode = trim((string) ($tracking['tracking'] ?? $tracking['self_tracking'] ?? $tracking['tracking_code'] ?? $shipment['tracking_code'] ?? ''));
        $trackingUrl = trim((string) ($tracking['melhorenvio_tracking'] ?? $tracking['tracking_url'] ?? $shipment['tracking_url'] ?? ''));
        if ($trackingUrl !== '' && !$this->trustedTrackingUrl($trackingUrl)) $trackingUrl = '';
        $postedAt = $this->date($tracking['posted_at'] ?? null) ?? $shipment['posted_at'];
        $deliveredAt = $this->date($tracking['delivered_at'] ?? null) ?? $shipment['delivered_at'];

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT melhor_envio_tracking');
        }
        try {
            $update = $pdo->prepare('UPDATE shipments SET tracking_code=?,tracking_url=?,status=?,raw_status=?,posted_at=?,delivered_at=?,last_synced_at=NOW() WHERE id=?');
            $update->execute([$trackingCode ?: null, $trackingUrl ?: null, $status, $rawStatus ?: null, $postedAt, $deliveredAt, $shipmentId]);
            if ($status !== (string) $shipment['status'] || $rawStatus !== (string) ($shipment['raw_status'] ?? '')) {
                $occurredAt = $this->date($tracking['updated_at'] ?? $tracking['delivered_at'] ?? $tracking['posted_at'] ?? null) ?? date('Y-m-d H:i:s');
                $eventKey = hash('sha256', $shipmentId . '|' . $rawStatus . '|' . $occurredAt);
                $payload = json_encode($tracking, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $event = $pdo->prepare('INSERT IGNORE INTO shipment_tracking_events(shipment_id,provider_event_key,event_code,description,city,state,occurred_at,raw_payload) VALUES(?,?,?,?,?,?,?,?)');
                $event->execute([$shipmentId, $eventKey, $rawStatus ?: $status, $this->description($status, $rawStatus), $tracking['city'] ?? null, $tracking['state'] ?? null, $occurredAt, $payload]);
            }
            if ($status === 'delivered') {
                $pdo->prepare("UPDATE seller_orders SET status='delivered' WHERE id=? AND status IN ('paid','processing','shipped')")->execute([$shipment['seller_order_id']]);
                $remaining = $pdo->prepare("SELECT COUNT(*) FROM seller_orders WHERE order_id=? AND status<>'delivered'");
                $remaining->execute([$shipment['order_id']]);
                if ((int) $remaining->fetchColumn() === 0) {
                    $complete = $pdo->prepare("UPDATE orders SET status='completed' WHERE id=? AND status IN ('paid','processing')");
                    $complete->execute([$shipment['order_id']]);
                    if ($complete->rowCount() > 0) {
                        $pdo->prepare("INSERT INTO order_status_history(order_id,status,notes) VALUES(?,'completed','Todas as remessas foram entregues.')")->execute([$shipment['order_id']]);
                    }
                }
            } elseif (in_array($status, ['posted', 'in_transit'], true)) {
                $pdo->prepare("UPDATE seller_orders SET status='shipped' WHERE id=? AND status IN ('paid','processing')")->execute([$shipment['seller_order_id']]);
                $pdo->prepare("UPDATE orders SET status='processing' WHERE id=? AND status='paid'")->execute([$shipment['order_id']]);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT melhor_envio_tracking');
            }
        } catch (JsonException $exception) {
            $this->rollback($pdo, $ownsTransaction);
            throw new RuntimeException('Não foi possível registrar o retorno do rastreamento.', 0, $exception);
        } catch (\Throwable $exception) {
            $this->rollback($pdo, $ownsTransaction);
            throw $exception;
        }

        $statement->execute([$shipmentId]);
        return $statement->fetch() ?: $shipment;
    }

    /** @param array<int,string> $externalIds @return array<string,mixed> */
    private function request(array $externalIds): array
    {
        $base = $this->baseUrl();
        $endpoint = (str_ends_with($base, '/api/v2') ? $base : $base . '/api/v2') . '/me/shipment/tracking';
        $payload = json_encode(['orders' => $externalIds], JSON_THROW_ON_ERROR);
        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token(),
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: ' . (string) ($_ENV['MELHOR_ENVIO_USER_AGENT'] ?? 'Tuffer Marketplace (contato@tuffer.com.br)'),
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($raw)) throw new RuntimeException('Falha de conexão com o Melhor Envio' . ($error !== '' ? ': ' . $error : '.'));
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? 'requisição rejeitada') : 'requisição rejeitada';
            throw new RuntimeException('Melhor Envio respondeu HTTP ' . $status . ': ' . mb_substr(strip_tags($message), 0, 240));
        }
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $response @return array<string,mixed>|null */
    private function trackingFor(array $response, string $externalId): ?array
    {
        if (isset($response[$externalId]) && is_array($response[$externalId])) return $response[$externalId];
        foreach ($response as $row) {
            if (is_array($row) && ((string) ($row['id'] ?? '') === $externalId || count($response) === 1)) return $row;
        }
        return isset($response['id']) ? $response : null;
    }

    private function localStatus(string $status): string
    {
        return match ($status) {
            'released', 'paid', 'generated' => 'purchased',
            'posted' => 'posted',
            'received' => 'in_transit',
            'in_transit', 'in-transit', 'transit' => 'in_transit',
            'delivered' => 'delivered',
            'cancelled', 'canceled', 'expired' => 'cancelled',
            'undelivered', 'paused', 'suspended', 'exception' => 'exception',
            default => 'pending',
        };
    }

    private function description(string $status, string $rawStatus): string
    {
        return match ($status) {
            'purchased' => 'Etiqueta liberada pelo Melhor Envio.',
            'posted' => 'Objeto postado na transportadora.',
            'in_transit' => 'Objeto em trânsito.',
            'delivered' => 'Entrega concluída.',
            'cancelled' => 'Envio cancelado.',
            'exception' => 'A transportadora informou uma ocorrência na entrega.',
            default => 'Status atualizado pelo Melhor Envio: ' . ($rawStatus ?: 'pendente') . '.',
        };
    }

    private function trustedTrackingUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        return ($parts['scheme'] ?? '') === 'https' && (
            $host === 'melhorenvio.com.br' || str_ends_with($host, '.melhorenvio.com.br') ||
            $host === 'melhorrastreio.com.br' || str_ends_with($host, '.melhorrastreio.com.br')
        );
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        try { return (new DateTimeImmutable($value))->format('Y-m-d H:i:s'); } catch (\Throwable) { return null; }
    }

    private function rollback(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        } elseif ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT melhor_envio_tracking');
        }
    }

    private function token(): string
    {
        return trim((string) ($_ENV['MELHOR_ENVIO_TOKEN'] ?? $_ENV['MELHOR_ENVIO_ACCESS_TOKEN'] ?? ''));
    }

    private function baseUrl(): string
    {
        $sandbox = filter_var($_ENV['MELHOR_ENVIO_SANDBOX'] ?? true, FILTER_VALIDATE_BOOL);
        $configured = rtrim(trim((string) ($_ENV['MELHOR_ENVIO_BASE_URL'] ?? '')), '/');
        return $configured !== '' ? $configured : ($sandbox ? 'https://sandbox.melhorenvio.com.br' : 'https://www.melhorenvio.com.br');
    }
}
