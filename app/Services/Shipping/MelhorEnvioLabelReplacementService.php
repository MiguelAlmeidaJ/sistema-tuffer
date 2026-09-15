<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Database;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class MelhorEnvioLabelReplacementService
{
    public function __construct(private readonly ?PDO $database = null)
    {
    }

    public function configured(): bool
    {
        return (new MelhorEnvioLabelService($this->database))->configured();
    }

    /**
     * @return array{shipment_id:int,replacement_id:int,status:string,label_url:?string,tracking_code:?string,carrier:string,service:string,expected_cost:float,actual_cost:?float}
     */
    public function replace(int $shipmentId, string $serviceId, int $adminId, string $reason = ''): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('A integração com o Melhor Envio não está configurada.');
        }
        $serviceId = trim($serviceId);
        if ($shipmentId < 1 || $serviceId === '') {
            throw new RuntimeException('Selecione uma modalidade válida para substituir a etiqueta.');
        }

        $pdo = $this->database ?? Database::connection();
        $context = $this->context($pdo, $shipmentId, false);
        $this->assertReplaceable($context);

        $quoteState = (new ShippingQuoteService())->quotesForSellerOrder((int) $context['seller_order_id']);
        $selected = $this->selectedQuote($quoteState, $serviceId);
        if ((string) ($context['service_id'] ?? '') === (string) $selected['id']) {
            throw new RuntimeException('Escolha uma modalidade diferente da etiqueta atual.');
        }

        $invoiceKey = preg_replace('/\D+/', '', (string) ($context['effective_invoice_key'] ?? '')) ?? '';
        if (!preg_match('/^\d{44}$/', $invoiceKey)) {
            throw new RuntimeException('A remessa não possui uma chave de NF-e autorizada com 44 dígitos para gerar a nova etiqueta.');
        }

        $lockName = 'tuffer_me_replace_' . $shipmentId;
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 12)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Outra alteração desta remessa está em andamento. Aguarde alguns segundos.');
        }

        $replacementId = 0;
        try {
            $pdo->beginTransaction();
            $context = $this->context($pdo, $shipmentId, true);
            $this->assertReplaceable($context);
            if ((string) ($context['service_id'] ?? '') === (string) $selected['id']) {
                throw new RuntimeException('A remessa já está usando a modalidade selecionada.');
            }

            $remoteCancelled = false;
            $externalId = trim((string) ($context['external_id'] ?? ''));
            $purchaseStatus = (string) ($context['label_purchase_status'] ?? 'not_requested');
            if ($externalId !== '') {
                if (!in_array($purchaseStatus, ['cart', 'purchased', 'generated', 'ready'], true)) {
                    throw new RuntimeException('A remessa possui um identificador externo que não foi gerado pelo fluxo central de etiquetas. Faça a conferência manual antes de substituir.');
                }
                $this->assertRemoteCancellable($externalId);
                $this->cancelRemoteLabel(
                    $externalId,
                    $reason !== '' ? $reason : 'Substituição de transportadora pelo administrador da Tuffer.'
                );
                $remoteCancelled = true;
            }

            $quotePayload = json_encode([
                'packages' => $selected['packages'] ?? [],
                'replacement' => [
                    'quoted_at' => date(DATE_ATOM),
                    'service_id' => (string) $selected['id'],
                    'service' => (string) $selected['service'],
                    'carrier' => (string) $selected['carrier'],
                    'expected_cost' => (float) $selected['price'],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $audit = $pdo->prepare(
                'INSERT INTO shipment_label_replacements(
                    shipment_id,seller_order_id,order_id,changed_by,reason,
                    old_service_id,old_service_name,old_carrier_name,old_external_id,old_tracking_code,
                    old_label_url,old_label_actual_cost,old_label_purchase_status,remote_cancelled_at,
                    new_service_id,new_service_name,new_carrier_name,new_expected_cost
                 ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?)'
            );
            $audit->execute([
                $shipmentId,
                (int) $context['seller_order_id'],
                (int) $context['order_id'],
                $adminId > 0 ? $adminId : null,
                mb_substr($reason !== '' ? $reason : 'Troca administrativa de transportadora.', 0, 500),
                (string) ($context['service_id'] ?? ''),
                (string) ($context['service_name'] ?? ''),
                (string) ($context['carrier_name'] ?? ''),
                $externalId !== '' ? $externalId : null,
                !empty($context['tracking_code']) ? (string) $context['tracking_code'] : null,
                !empty($context['label_url']) ? (string) $context['label_url'] : null,
                ($context['label_actual_cost'] ?? null) !== null ? (float) $context['label_actual_cost'] : null,
                $purchaseStatus,
                $remoteCancelled ? date('Y-m-d H:i:s') : null,
                (string) $selected['id'],
                (string) $selected['service'],
                (string) $selected['carrier'],
                (float) $selected['price'],
            ]);
            $replacementId = (int) $pdo->lastInsertId();

            $reset = $pdo->prepare(
                "UPDATE shipments
                    SET service_id=?,service_name=?,carrier_name=?,quote_payload=?,invoice_key=?,
                        external_id=NULL,tracking_code=NULL,tracking_url=NULL,label_url=NULL,
                        label_purchase_status='not_requested',label_actual_cost=NULL,label_error=NULL,
                        label_attempted_at=NULL,purchased_at=NULL,generated_at=NULL,raw_status=NULL,last_synced_at=NULL
                  WHERE id=?"
            );
            $reset->execute([
                (string) $selected['id'],
                (string) $selected['service'],
                (string) $selected['carrier'],
                $quotePayload,
                $invoiceKey,
                $shipmentId,
            ]);

            $note = sprintf(
                'Transportadora substituída pelo admin: %s / %s → %s / %s. Etiqueta anterior %s. Frete cobrado do cliente mantido em R$ %s.',
                (string) ($context['carrier_name'] ?: 'Transportadora'),
                (string) ($context['service_name'] ?: 'modalidade'),
                (string) $selected['carrier'],
                (string) $selected['service'],
                $remoteCancelled ? 'cancelada no Melhor Envio' : 'não possuía compra remota',
                number_format((float) ($context['shipping_cost'] ?? 0), 2, ',', '.')
            );
            $history = $pdo->prepare(
                'INSERT INTO order_status_history(order_id,status,notes,created_by) VALUES(?,?,?,?)'
            );
            $history->execute([
                (int) $context['order_id'],
                (string) $context['order_status'],
                mb_substr($note, 0, 500),
                $adminId > 0 ? $adminId : null,
            ]);

            $pdo->commit();

            try {
                $result = (new MelhorEnvioLabelService($pdo))->purchaseForSellerOrder(
                    (string) $context['seller_order_code'],
                    (int) $context['store_id'],
                    $invoiceKey
                );
                $this->syncAudit($pdo, $replacementId, $shipmentId);
                $current = $this->context($pdo, $shipmentId, false);
                return [
                    'shipment_id' => $shipmentId,
                    'replacement_id' => $replacementId,
                    'status' => (string) $result['status'],
                    'label_url' => $result['label_url'],
                    'tracking_code' => $result['tracking_code'],
                    'carrier' => (string) $selected['carrier'],
                    'service' => (string) $selected['service'],
                    'expected_cost' => (float) $selected['price'],
                    'actual_cost' => ($current['label_actual_cost'] ?? null) !== null ? (float) $current['label_actual_cost'] : null,
                ];
            } catch (Throwable $exception) {
                $this->syncAudit($pdo, $replacementId, $shipmentId);
                throw new RuntimeException(
                    'A etiqueta anterior foi cancelada e a transportadora foi trocada, mas a nova etiqueta não foi concluída: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        } catch (JsonException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new RuntimeException('Não foi possível preparar os dados da nova cotação.', 0, $exception);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($exception instanceof RuntimeException) throw $exception;
            throw new RuntimeException('Não foi possível substituir a transportadora desta remessa.', 0, $exception);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    /** @return array<string,mixed> */
    private function context(PDO $pdo, int $shipmentId, bool $lock): array
    {
        $sql = 'SELECT sh.*,so.id seller_order_id,so.code seller_order_code,so.status seller_order_status,so.store_id,
                       o.id order_id,o.code order_code,o.status order_status,
                       COALESCE(NULLIF(sh.invoice_key,\'\'),(
                           SELECT fd.access_key FROM fiscal_documents fd
                            WHERE fd.seller_order_id=so.id AND fd.document_type=\'nfe\'
                              AND fd.revision=1 AND fd.status=\'authorized\'
                            ORDER BY fd.id DESC LIMIT 1
                       )) effective_invoice_key
                  FROM shipments sh
                  JOIN seller_orders so ON so.id=sh.seller_order_id
                  JOIN orders o ON o.id=so.order_id
                 WHERE sh.id=? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
        $statement = $pdo->prepare($sql);
        $statement->execute([$shipmentId]);
        $context = $statement->fetch();
        if (!is_array($context)) {
            throw new RuntimeException('Remessa não encontrada.');
        }
        return $context;
    }

    /** @param array<string,mixed> $context */
    private function assertReplaceable(array $context): void
    {
        if (!in_array((string) $context['order_status'], ['paid', 'processing'], true)
            || !in_array((string) $context['seller_order_status'], ['paid', 'processing'], true)) {
            throw new RuntimeException('A transportadora só pode ser substituída enquanto o pedido estiver pago ou em preparação.');
        }
        if (in_array((string) ($context['status'] ?? ''), ['posted', 'in_transit', 'delivered'], true)) {
            throw new RuntimeException('Esta remessa já foi postada ou entrou em trânsito e não pode mais trocar de transportadora.');
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function selectedQuote(array $state, string $serviceId): array
    {
        foreach (is_array($state['options'] ?? null) ? $state['options'] : [] as $option) {
            if (is_array($option) && (string) ($option['id'] ?? '') === $serviceId) return $option;
        }
        $message = trim((string) ($state['message'] ?? ''));
        throw new RuntimeException($message !== '' ? $message : 'A modalidade selecionada não está mais disponível. Atualize a cotação e tente novamente.');
    }

    private function assertRemoteCancellable(string $externalId): void
    {
        $response = $this->request('POST', '/api/v2/me/shipment/cancellable', ['orders' => [$externalId]]);
        $flag = $this->cancellableFlag($response);
        if ($flag === false) {
            throw new RuntimeException('O Melhor Envio informou que a etiqueta atual não pode mais ser cancelada. Verifique se ela já foi postada ou entrou em coleta.');
        }
    }

    private function cancelRemoteLabel(string $externalId, string $description): void
    {
        $this->request('POST', '/api/v2/me/shipment/cancel', [
            'order' => [
                'id' => $externalId,
                'reason_id' => 2,
                'description' => mb_substr($description, 0, 255),
            ],
        ]);
    }

    /** @param array<string,mixed> $body */
    private function cancellableFlag(array $body): ?bool
    {
        foreach ($body as $key => $value) {
            $normalizedKey = mb_strtolower((string) $key);
            if (in_array($normalizedKey, ['cancellable', 'cancelable', 'is_cancellable', 'can_cancel'], true)) {
                if (is_bool($value)) return $value;
                if (is_int($value)) return $value === 1;
                if (is_string($value) && in_array(mb_strtolower($value), ['true', '1', 'yes', 'sim'], true)) return true;
                if (is_string($value) && in_array(mb_strtolower($value), ['false', '0', 'no', 'não', 'nao'], true)) return false;
            }
            if (is_array($value)) {
                $nested = $this->cancellableFlag($value);
                if ($nested !== null) return $nested;
            }
        }
        return null;
    }

    private function syncAudit(PDO $pdo, int $replacementId, int $shipmentId): void
    {
        if ($replacementId < 1) return;
        $statement = $pdo->prepare(
            'UPDATE shipment_label_replacements r
             JOIN shipments sh ON sh.id=?
                SET r.new_external_id=sh.external_id,
                    r.new_tracking_code=sh.tracking_code,
                    r.new_label_url=sh.label_url,
                    r.new_label_actual_cost=sh.label_actual_cost,
                    r.new_label_purchase_status=sh.label_purchase_status
              WHERE r.id=?'
        );
        $statement->execute([$shipmentId, $replacementId]);
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function request(string $method, string $endpoint, ?array $payload = null): array
    {
        $curl = curl_init($this->baseUrl() . '/' . ltrim($endpoint, '/'));
        if ($curl === false) throw new RuntimeException('Não foi possível iniciar a comunicação com o Melhor Envio.');
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token(),
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: ' . (string) ($_ENV['MELHOR_ENVIO_USER_AGENT'] ?? 'Tuffer Marketplace (suporte@tuffer.com.br)'),
            ],
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($raw)) {
            throw new RuntimeException('Falha de conexão com o Melhor Envio' . ($error !== '' ? ': ' . $error : '.'));
        }
        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? $this->errorMessage($decoded) : '';
            throw new RuntimeException($message !== ''
                ? 'O Melhor Envio recusou a operação: ' . mb_substr($message, 0, 400)
                : "O Melhor Envio recusou a operação (HTTP {$status}).");
        }
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $body */
    private function errorMessage(array $body): string
    {
        $message = $body['message'] ?? $body['error'] ?? null;
        if (is_string($message)) return trim(strip_tags($message));
        $errors = $body['errors'] ?? null;
        if (!is_array($errors)) return '';
        $messages = [];
        array_walk_recursive($errors, static function (mixed $value) use (&$messages): void {
            if (is_string($value) && trim($value) !== '') $messages[] = trim(strip_tags($value));
        });
        return implode(' ', array_slice(array_unique($messages), 0, 5));
    }

    private function token(): string
    {
        return trim((string) ($_ENV['MELHOR_ENVIO_TOKEN'] ?? $_ENV['MELHOR_ENVIO_ACCESS_TOKEN'] ?? ''));
    }

    private function baseUrl(): string
    {
        $sandbox = filter_var($_ENV['MELHOR_ENVIO_SANDBOX'] ?? true, FILTER_VALIDATE_BOOL);
        $configured = rtrim(trim((string) ($_ENV['MELHOR_ENVIO_BASE_URL'] ?? '')), '/');
        $base = $configured !== '' ? $configured : ($sandbox ? 'https://sandbox.melhorenvio.com.br' : 'https://melhorenvio.com.br');
        $parts = parse_url($base);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, ['sandbox.melhorenvio.com.br', 'melhorenvio.com.br', 'www.melhorenvio.com.br'], true)) {
            throw new RuntimeException('A URL configurada do Melhor Envio não é válida.');
        }
        return str_ends_with($base, '/api/v2') ? substr($base, 0, -7) : $base;
    }
}
