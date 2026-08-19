<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Database;
use App\Services\Settings\PlatformSettings;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class MelhorEnvioLabelService
{
    public function __construct(private readonly ?PDO $database = null)
    {
    }

    public function configured(): bool
    {
        return $this->token() !== '' && PlatformSettings::enabled('melhor_envio_enabled');
    }

    /** @return array{shipment_id:int,status:string,label_url:?string,tracking_code:?string} */
    public function purchaseForSellerOrder(string $sellerOrderCode, int $storeId, string $invoiceKey): array
    {
        $invoiceKey = preg_replace('/\D+/', '', $invoiceKey) ?? '';
        if (!preg_match('/^\d{44}$/', $invoiceKey)) {
            throw new RuntimeException('Informe a chave de acesso da NF-e com 44 dígitos.');
        }
        if (!$this->configured()) {
            throw new RuntimeException('A integração com o Melhor Envio não está configurada.');
        }

        $pdo = $this->database ?? Database::connection();
        $statement = $pdo->prepare(
            'SELECT sh.id FROM shipments sh
             JOIN seller_orders so ON so.id=sh.seller_order_id
             WHERE so.code=? AND so.store_id=? LIMIT 1'
        );
        $statement->execute([$sellerOrderCode, $storeId]);
        $shipmentId = (int) $statement->fetchColumn();
        if ($shipmentId < 1) {
            throw new RuntimeException('Remessa não encontrada para este pedido.');
        }

        $lockName = 'tuffer_me_label_' . $shipmentId;
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Outra compra de etiqueta está em andamento. Aguarde alguns segundos.');
        }

        try {
            return $this->purchase($pdo, $shipmentId, $invoiceKey);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    /** @return array{shipment_id:int,status:string,label_url:?string,tracking_code:?string} */
    private function purchase(PDO $pdo, int $shipmentId, string $invoiceKey): array
    {
        $context = $this->context($pdo, $shipmentId);
        if (!in_array((string) $context['seller_order_status'], ['paid', 'processing'], true)) {
            throw new RuntimeException('A etiqueta só pode ser comprada depois da confirmação do pagamento.');
        }
        if (!in_array((string) $context['order_status'], ['paid', 'processing'], true)) {
            throw new RuntimeException('O pedido principal ainda não está liberado para envio.');
        }
        if (!empty($context['label_url']) && ($context['label_purchase_status'] ?? '') === 'ready') {
            return $this->result($context);
        }
        if (!empty($context['invoice_key']) && !hash_equals((string) $context['invoice_key'], $invoiceKey)) {
            throw new RuntimeException('A remessa já foi vinculada a outra chave de NF-e.');
        }
        if (!empty($context['external_id']) && !in_array((string) $context['label_purchase_status'], ['cart', 'purchased', 'generated', 'ready'], true)) {
            throw new RuntimeException('Esta remessa possui uma etiqueta informada manualmente e não pode ser recomprada.');
        }

        $pdo->prepare(
            "UPDATE shipments SET invoice_key=?,label_error=NULL,label_attempted_at=NOW(),
             label_purchase_status=IF(label_purchase_status IN ('not_requested','failed'),'processing',label_purchase_status)
             WHERE id=?"
        )->execute([$invoiceKey, $shipmentId]);

        try {
            $context = $this->context($pdo, $shipmentId);
            if (empty($context['external_id'])) {
                $this->assertWalletBalance((float) $context['shipping_cost']);
                $payload = $this->cartPayload($pdo, $context, $invoiceKey);
                $cart = $this->request('POST', '/api/v2/me/cart', $payload);
                $externalId = trim((string) ($cart['id'] ?? ''));
                if ($externalId === '') {
                    throw new RuntimeException('O Melhor Envio não retornou o identificador da etiqueta.');
                }
                $actualCost = $this->moneyValue($cart['custom_price'] ?? $cart['price'] ?? $context['shipping_cost']);
                $pdo->prepare(
                    "UPDATE shipments SET external_id=?,label_actual_cost=?,label_purchase_status='cart',label_error=NULL
                     WHERE id=? AND (external_id IS NULL OR external_id='')"
                )->execute([$externalId, $actualCost, $shipmentId]);
                $context = $this->context($pdo, $shipmentId);
            }

            if ((string) $context['label_purchase_status'] === 'cart') {
                $this->request('POST', '/api/v2/me/shipment/checkout', ['orders' => [(string) $context['external_id']]]);
                $pdo->prepare(
                    "UPDATE shipments SET status='purchased',label_purchase_status='purchased',purchased_at=NOW(),label_error=NULL WHERE id=?"
                )->execute([$shipmentId]);
                $context = $this->context($pdo, $shipmentId);
            }

            if ((string) $context['label_purchase_status'] === 'purchased') {
                $this->request('POST', '/api/v2/me/shipment/generate', ['orders' => [(string) $context['external_id']]]);
                $pdo->prepare(
                    "UPDATE shipments SET label_purchase_status='generated',generated_at=NOW(),label_error=NULL WHERE id=?"
                )->execute([$shipmentId]);
                $context = $this->context($pdo, $shipmentId);
            }

            if ((string) $context['label_purchase_status'] === 'generated') {
                $printed = $this->printLabel((string) $context['external_id']);
                $labelUrl = trim((string) ($printed['url'] ?? ''));
                if (!$this->trustedLabelUrl($labelUrl)) {
                    throw new RuntimeException('O Melhor Envio não retornou um link seguro para a etiqueta.');
                }
                $trackingCode = $this->trackingCode((string) $context['external_id']);
                $pdo->prepare(
                    "UPDATE shipments SET label_url=?,tracking_code=COALESCE(NULLIF(?,''),tracking_code),
                     label_purchase_status='ready',label_error=NULL WHERE id=?"
                )->execute([$labelUrl, $trackingCode, $shipmentId]);
            }

            return $this->result($this->context($pdo, $shipmentId));
        } catch (Throwable $exception) {
            $pdo->prepare(
                "UPDATE shipments SET label_purchase_status=IF(label_purchase_status='processing','failed',label_purchase_status),
                 label_error=? WHERE id=?"
            )->execute([mb_substr($exception->getMessage(), 0, 1000), $shipmentId]);
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function context(PDO $pdo, int $shipmentId): array
    {
        $statement = $pdo->prepare(
            'SELECT sh.*,so.id seller_order_id,so.code seller_order_code,so.status seller_order_status,
                    so.store_id,o.id order_id,o.code order_code,o.status order_status,
                    customer.name customer_name,customer.email customer_email,customer.phone customer_phone,
                    customer.document customer_document,s.legal_name,s.trade_name,s.document seller_document,
                    s.state_registration,seller_user.email seller_email,seller_user.phone seller_phone,
                    st.name store_name,COALESCE(st.shipping_source_store_id,st.id) shipping_origin_store_id
             FROM shipments sh
             JOIN seller_orders so ON so.id=sh.seller_order_id
             JOIN orders o ON o.id=so.order_id
             JOIN users customer ON customer.id=o.user_id
             JOIN stores st ON st.id=so.store_id
             JOIN sellers s ON s.id=so.seller_id
             JOIN users seller_user ON seller_user.id=s.user_id
             WHERE sh.id=? LIMIT 1'
        );
        $statement->execute([$shipmentId]);
        $context = $statement->fetch();
        if (!is_array($context)) {
            throw new RuntimeException('Remessa não encontrada.');
        }
        return $context;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function cartPayload(PDO $pdo, array $context, string $invoiceKey): array
    {
        $originStatement = $pdo->prepare(
            'SELECT * FROM store_addresses WHERE store_id=? AND is_shipping_origin=1 ORDER BY id LIMIT 1'
        );
        $originStatement->execute([(int) $context['shipping_origin_store_id']]);
        $origin = $originStatement->fetch();
        if (!is_array($origin)) {
            throw new RuntimeException('Cadastre o endereço completo de origem da loja antes de comprar a etiqueta.');
        }

        $addressStatement = $pdo->prepare('SELECT * FROM order_addresses WHERE order_id=? LIMIT 1');
        $addressStatement->execute([(int) $context['order_id']]);
        $destination = $addressStatement->fetch();
        if (!is_array($destination)) {
            throw new RuntimeException('O pedido não possui endereço de entrega.');
        }

        $itemsStatement = $pdo->prepare(
            'SELECT oi.*,COALESCE(pv.weight,p.weight,0.1) shipping_weight,
                    COALESCE(pv.width,p.width,11) shipping_width,
                    COALESCE(pv.height,p.height,2) shipping_height,
                    COALESCE(pv.length,p.length,16) shipping_length
             FROM order_items oi
             LEFT JOIN product_variants pv ON pv.id=oi.product_variant_id
             LEFT JOIN products p ON p.id=oi.product_id
             WHERE oi.seller_order_id=? ORDER BY oi.id'
        );
        $itemsStatement->execute([(int) $context['seller_order_id']]);
        $items = $itemsStatement->fetchAll();
        if ($items === []) {
            throw new RuntimeException('O pedido não possui produtos para declarar no envio.');
        }

        $sellerDocument = $this->document((string) $context['seller_document'], 'vendedor');
        $customerDocument = $this->document((string) $context['customer_document'], 'cliente');
        $sellerPhone = $this->phone((string) $context['seller_phone'], 'vendedor');
        $customerPhone = $this->phone((string) $context['customer_phone'], 'cliente');
        $stateRegistration = trim((string) $context['state_registration']);
        if (strlen($sellerDocument) === 14 && $stateRegistration === '') {
            throw new RuntimeException('Informe a inscrição estadual do vendedor ou “ISENTO” nas configurações.');
        }

        $quote = $this->quote((string) ($context['quote_payload'] ?? ''), $items);
        $packages = $quote['packages'];
        $carrier = mb_strtolower((string) ($context['carrier_name'] ?? ''));
        foreach (['azul', 'latam', 'buslog'] as $unsupportedCarrier) {
            if (str_contains($carrier, $unsupportedCarrier)) {
                throw new RuntimeException('A modalidade escolhida não permite compra de etiqueta pela Tuffer. Fale com o suporte.');
            }
        }
        if (count($packages) > 1 && (str_contains($carrier, 'correios') || str_contains($carrier, 'j&t') || str_contains($carrier, 'loggi'))) {
            throw new RuntimeException('Esta transportadora exige uma etiqueta por volume. Separe o envio antes de comprar.');
        }

        $from = [
            'name' => mb_substr((string) ($context['trade_name'] ?: $context['legal_name']), 0, 150),
            'email' => (string) $context['seller_email'],
            'phone' => $sellerPhone,
            'address' => (string) $origin['street'],
            'complement' => (string) ($origin['complement'] ?? ''),
            'number' => (string) $origin['number'],
            'district' => (string) $origin['neighborhood'],
            'city' => (string) $origin['city'],
            'postal_code' => preg_replace('/\D+/', '', (string) $origin['postal_code']),
            'state_abbr' => mb_strtoupper((string) $origin['state']),
        ] + $this->documentFields($sellerDocument);
        if (strlen($sellerDocument) === 14) {
            $from['state_register'] = $stateRegistration;
        }
        $to = [
            'name' => mb_substr((string) $destination['recipient_name'], 0, 150),
            'email' => (string) $context['customer_email'],
            'phone' => $customerPhone,
            'address' => (string) $destination['street'],
            'complement' => (string) ($destination['complement'] ?? ''),
            'number' => (string) $destination['number'],
            'district' => (string) $destination['neighborhood'],
            'city' => (string) $destination['city'],
            'postal_code' => preg_replace('/\D+/', '', (string) $destination['postal_code']),
            'state_abbr' => mb_strtoupper((string) $destination['state']),
            'country_id' => 'BR',
        ] + $this->documentFields($customerDocument);

        $products = [];
        $insurance = 0.0;
        foreach ($items as $item) {
            $quantity = max(1, (int) $item['quantity']);
            $unitary = round((float) $item['unit_price'], 2);
            $insurance += $unitary * $quantity;
            $products[] = [
                'name' => mb_substr((string) $item['product_name'], 0, 255),
                'quantity' => $quantity,
                'unitary_value' => $unitary,
            ];
        }

        return [
            'service' => (int) $context['service_id'],
            'from' => $from,
            'to' => $to,
            'products' => $products,
            'volumes' => $packages,
            'options' => [
                'platform' => 'Tuffer Marketplace',
                'reminder' => 'Pedido ' . (string) $context['seller_order_code'],
                'insurance_value' => round($insurance, 2),
                'receipt' => false,
                'own_hand' => false,
                'reverse' => false,
                'invoice' => ['key' => $invoiceKey],
                'tags' => [['tag' => (string) $context['seller_order_code'], 'url' => null]],
            ],
        ];
    }

    /** @param array<int,array<string,mixed>> $items @return array{packages:array<int,array<string,float>>} */
    private function quote(string $json, array $items): array
    {
        $quote = [];
        if ($json !== '') {
            try {
                $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) $quote = $decoded;
            } catch (JsonException) {
                $quote = [];
            }
        }
        $packages = [];
        foreach (is_array($quote['packages'] ?? null) ? $quote['packages'] : [] as $package) {
            if (!is_array($package)) continue;
            $height = max(2, (float) ($package['height'] ?? 0));
            $width = max(11, (float) ($package['width'] ?? 0));
            $length = max(16, (float) ($package['length'] ?? 0));
            $weight = max(0.1, (float) ($package['weight'] ?? 0));
            if ($height > 0 && $width > 0 && $length > 0 && $weight > 0) {
                $packages[] = compact('height', 'width', 'length', 'weight');
            }
        }
        if ($packages === []) {
            $height = 2.0; $width = 11.0; $length = 16.0; $weight = 0.0;
            foreach ($items as $item) {
                $quantity = max(1, (int) $item['quantity']);
                $height = max($height, (float) $item['shipping_height']);
                $width = max($width, (float) $item['shipping_width']);
                $length = max($length, (float) $item['shipping_length']);
                $weight += max(0.1, (float) $item['shipping_weight']) * $quantity;
            }
            $packages[] = ['height' => $height, 'width' => $width, 'length' => $length, 'weight' => max(0.1, $weight)];
        }
        return ['packages' => $packages];
    }

    /** @return array<string,mixed> */
    private function printLabel(string $externalId): array
    {
        $last = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $this->request('POST', '/api/v2/me/shipment/print', [
                    'mode' => 'public',
                    'orders' => [$externalId],
                ]);
            } catch (Throwable $exception) {
                $last = $exception;
                if ($attempt < 3) usleep(750_000);
            }
        }
        throw new RuntimeException(
            'A etiqueta foi comprada e gerada, mas a impressão ainda está sendo processada. Tente novamente em instantes.',
            0,
            $last
        );
    }

    private function trackingCode(string $externalId): string
    {
        try {
            $info = $this->request('GET', '/api/v2/me/orders/' . rawurlencode($externalId));
            return mb_substr(trim((string) ($info['tracking'] ?? $info['tracking_code'] ?? '')), 0, 100);
        } catch (Throwable) {
            return '';
        }
    }

    private function assertWalletBalance(float $expectedCost): void
    {
        $response = $this->request('GET', '/api/v2/me/balance');
        if (!array_key_exists('balance', $response)) {
            throw new RuntimeException('Não foi possível confirmar o saldo de fretes da Tuffer. Tente novamente em instantes.');
        }
        if ((float) $response['balance'] + 0.009 < max(0, $expectedCost)) {
            throw new RuntimeException('O saldo central de fretes da Tuffer está insuficiente. O suporte já pode recarregar a carteira e concluir a etiqueta depois.');
        }
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
                ? 'O Melhor Envio recusou a etiqueta: ' . mb_substr($message, 0, 400)
                : "O Melhor Envio recusou a etiqueta (HTTP {$status}).");
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

    private function document(string $value, string $owner): string
    {
        $document = preg_replace('/\D+/', '', $value) ?? '';
        if (!in_array(strlen($document), [11, 14], true)) {
            throw new RuntimeException('Informe um CPF ou CNPJ válido para o ' . $owner . '.');
        }
        return $document;
    }

    /** @return array<string,string> */
    private function documentFields(string $document): array
    {
        return strlen($document) === 14
            ? ['company_document' => $document]
            : ['document' => $document];
    }

    private function phone(string $value, string $owner): string
    {
        $phone = preg_replace('/\D+/', '', $value) ?? '';
        if (!in_array(strlen($phone), [10, 11], true)) {
            throw new RuntimeException('Informe um telefone com DDD para o ' . $owner . '.');
        }
        return $phone;
    }

    private function trustedLabelUrl(string $url): bool
    {
        $parts = parse_url($url);
        return ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']);
    }

    private function moneyValue(mixed $value): string
    {
        return number_format(max(0, (float) $value), 2, '.', '');
    }

    /** @param array<string,mixed> $context @return array{shipment_id:int,status:string,label_url:?string,tracking_code:?string} */
    private function result(array $context): array
    {
        return [
            'shipment_id' => (int) $context['id'],
            'status' => (string) $context['label_purchase_status'],
            'label_url' => !empty($context['label_url']) ? (string) $context['label_url'] : null,
            'tracking_code' => !empty($context['tracking_code']) ? (string) $context['tracking_code'] : null,
        ];
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
