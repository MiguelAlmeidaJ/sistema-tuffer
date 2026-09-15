<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Database;
use App\Core\Session;
use App\Services\Cart\CartService;
use App\Services\Settings\PlatformSettings;
use DateTimeImmutable;

final class ShippingQuoteService
{
    private ?string $lastError = null;
    /** @var array<string,?int> */
    private array $agencyAvailabilityCache = [];

    public function configured(): bool
    {
        return $this->token() !== '' && PlatformSettings::enabled('melhor_envio_enabled');
    }

    /** @param array<string,mixed> $cart @return array<string,mixed> */
    public function quotes(array $cart, string $postalCode, bool $refresh = false): array
    {
        $postalCode = preg_replace('/\D+/', '', $postalCode) ?? '';
        $configured = $this->configured();
        $state = ['configured' => $configured, 'postal_code' => $postalCode, 'stores' => [], 'shipping_total' => 0.0];
        if (strlen($postalCode) !== 8) return $state + ['message' => 'Informe um CEP válido.'];
        if (!$configured) return $state + ['message' => 'Configure o Melhor Envio para consultar valores e prazos reais.'];

        $cartId = (new CartService())->id();
        $cacheKey = 'shipping_quotes_' . ($cartId ?: 'guest');
        $fingerprint = hash('sha256', json_encode([
            'central-label-v2-posting-city',
            $postalCode,
            array_map(static fn(array $group): array => [
                $group['store_id'],
                $group['origin_postal_code'],
                array_map(static fn(array $item): array => [
                    $item['variant_id'], $item['quantity'], $item['shipping_weight'],
                    $item['shipping_width'], $item['shipping_height'], $item['shipping_length'],
                ], $group['items']),
            ], $cart['groups']),
        ], JSON_THROW_ON_ERROR));
        $cached = Session::get($cacheKey);
        if (!$refresh && is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint) return $cached['state'];

        foreach ($cart['groups'] as $group) {
            $storeId = (int) $group['store_id'];
            $origin = preg_replace('/\D+/', '', (string) ($group['origin_postal_code'] ?? '')) ?? '';
            if (strlen($origin) !== 8) {
                $state['stores'][$storeId] = ['store_id' => $storeId, 'store_name' => $group['store_name'], 'options' => [], 'message' => 'A loja ainda não configurou o CEP de origem.'];
                continue;
            }

            $originLocation = $this->originLocationForStore($storeId);
            if ($originLocation['city'] === '' || $originLocation['state'] === '') {
                $state['stores'][$storeId] = [
                    'store_id' => $storeId,
                    'store_name' => $group['store_name'],
                    'options' => [],
                    'message' => 'A loja precisa ter cidade e UF configuradas no endereço de origem para validar os pontos de postagem.',
                ];
                continue;
            }

            $result = $this->request($origin, $postalCode, $group['items']);
            $options = $this->normalizeOptions($result, $group['items'], 4, $originLocation);
            $selected = $options[0]['id'] ?? null;
            $state['stores'][$storeId] = [
                'store_id' => $storeId,
                'store_name' => $group['store_name'],
                'options' => $options,
                'selected' => $selected,
                'message' => $options ? null : ($this->lastError ?? 'Nenhuma modalidade disponível para este CEP.'),
            ];
            if ($options) $state['shipping_total'] += (float) $options[0]['price'];
        }

        $state['shipping_total'] = round($state['shipping_total'], 2);
        Session::put($cacheKey, ['fingerprint' => $fingerprint, 'state' => $state]);
        return $state;
    }

    /** @return array{configured:bool,options:array<int,array<string,mixed>>,message:?string} */
    public function quotesForSellerOrder(int $sellerOrderId): array
    {
        $configured = $this->configured();
        $state = ['configured' => $configured, 'options' => [], 'message' => null];
        if (!$configured) {
            $state['message'] = 'A integração com o Melhor Envio não está configurada.';
            return $state;
        }

        $pdo = Database::connection();
        $contextStatement = $pdo->prepare(
            'SELECT so.id,so.store_id,o.id order_id,oa.postal_code destination_postal_code
             FROM seller_orders so
             JOIN orders o ON o.id=so.order_id
             LEFT JOIN order_addresses oa ON oa.order_id=o.id
             WHERE so.id=? LIMIT 1'
        );
        $contextStatement->execute([$sellerOrderId]);
        $context = $contextStatement->fetch();
        if (!is_array($context)) {
            $state['message'] = 'Pedido da loja não encontrado para recotação.';
            return $state;
        }

        $originLocation = $this->originLocationForStore((int) $context['store_id']);
        $origin = preg_replace('/\D+/', '', $originLocation['postal_code']) ?? '';
        $destination = preg_replace('/\D+/', '', (string) ($context['destination_postal_code'] ?? '')) ?? '';
        if (strlen($origin) !== 8) {
            $state['message'] = 'A loja não possui CEP de origem de frete válido.';
            return $state;
        }
        if ($originLocation['city'] === '' || $originLocation['state'] === '') {
            $state['message'] = 'A loja precisa ter cidade e UF configuradas no endereço de origem para validar os pontos de postagem.';
            return $state;
        }
        if (strlen($destination) !== 8) {
            $state['message'] = 'O pedido não possui CEP de entrega válido.';
            return $state;
        }

        $itemsStatement = $pdo->prepare(
            'SELECT oi.product_variant_id variant_id,oi.quantity,oi.unit_price,
                    COALESCE(pv.weight,p.weight,0.1) shipping_weight,
                    COALESCE(pv.width,p.width,11) shipping_width,
                    COALESCE(pv.height,p.height,2) shipping_height,
                    COALESCE(pv.length,p.length,16) shipping_length
             FROM order_items oi
             LEFT JOIN product_variants pv ON pv.id=oi.product_variant_id
             LEFT JOIN products p ON p.id=oi.product_id
             WHERE oi.seller_order_id=?
             ORDER BY oi.id'
        );
        $itemsStatement->execute([$sellerOrderId]);
        $items = $itemsStatement->fetchAll();
        if ($items === []) {
            $state['message'] = 'O pedido não possui itens para recotar o frete.';
            return $state;
        }

        $result = $this->request($origin, $destination, $items);
        $state['options'] = $this->normalizeOptions($result, $items, 12, $originLocation);
        $state['message'] = $state['options'] ? null : ($this->lastError ?? 'Nenhuma modalidade alternativa disponível para esta rota.');
        return $state;
    }

    /** @param array<int,mixed> $result @param array<int,array<string,mixed>> $items @param array{postal_code:string,city:string,state:string} $originLocation @return array<int,array<string,mixed>> */
    private function normalizeOptions(array $result, array $items, int $limit, array $originLocation): array
    {
        $candidates = [];
        foreach ($result as $quote) {
            if (!is_array($quote) || isset($quote['error']) || !isset($quote['id'])) continue;
            $carrier = trim((string) ($quote['company']['name'] ?? 'Transportadora'));
            if (!$this->supportsCentralizedPurchase($carrier)) continue;
            $price = (float) ($quote['custom_price'] ?? $quote['price'] ?? 0);
            if ($price <= 0) continue;
            $days = (int) ($quote['custom_delivery_time'] ?? $quote['delivery_time'] ?? 0);
            $range = $quote['custom_delivery_range'] ?? $quote['delivery_range'] ?? [];
            $minDays = max(1, (int) ($range['min'] ?? $days ?: 1));
            $maxDays = max($minDays, (int) ($range['max'] ?? $days ?: $minDays));
            $candidates[] = [
                'id' => (string) $quote['id'],
                'service' => trim((string) ($quote['name'] ?? 'Entrega')),
                'carrier' => $carrier,
                'company_id' => isset($quote['company']['id']) ? trim((string) $quote['company']['id']) : '',
                'price' => round($price, 2),
                'packages' => $this->packages($quote['packages'] ?? [], $items),
                'min_days' => $minDays,
                'max_days' => $maxDays,
                'arrival_min' => $this->businessDate($minDays),
                'arrival_max' => $this->businessDate($maxDays),
            ];
        }

        $options = [];
        $rejectedNoPoint = 0;
        $rejectedValidation = 0;
        foreach ($candidates as $option) {
            if ($this->isPickupAtOrigin((string) $option['carrier'], (string) $option['service'])) {
                $option['posting_point_required'] = false;
                $option['posting_validation'] = 'pickup_at_origin';
                $option['posting_point_count'] = null;
                $options[] = $option;
                continue;
            }

            $companyId = (string) $option['company_id'];
            if ($companyId === '') {
                $rejectedValidation++;
                continue;
            }

            $agencyCount = $this->agencyCountForCompany(
                $companyId,
                $originLocation['city'],
                $originLocation['state']
            );
            if ($agencyCount === null) {
                $rejectedValidation++;
                continue;
            }
            if ($agencyCount < 1) {
                $rejectedNoPoint++;
                continue;
            }

            $option['posting_point_required'] = true;
            $option['posting_validation'] = 'verified_same_city';
            $option['posting_point_count'] = $agencyCount;
            $options[] = $option;
        }

        if ($options === [] && $candidates !== []) {
            $city = $originLocation['city'] . '/' . $originLocation['state'];
            if ($rejectedValidation > 0) {
                $this->lastError = 'Não foi possível validar os pontos de postagem em ' . $city . '. Por segurança, o frete não será oferecido até a validação voltar a funcionar.';
            } elseif ($rejectedNoPoint > 0) {
                $this->lastError = 'Nenhuma modalidade possui ponto de postagem compatível na cidade de origem (' . $city . ').';
            }
        }

        usort($options, static fn(array $a, array $b): int => $a['price'] <=> $b['price']);
        return array_slice($options, 0, max(1, $limit));
    }

    /** @param array<int,array<string,mixed>> $items @return array<int,mixed> */
    private function request(string $from, string $to, array $items): array
    {
        $endpoint = $this->apiUrl('/api/v2/me/shipment/calculate');
        $this->lastError = null;
        $products = array_map(static fn(array $item): array => [
            'id' => (string) ($item['variant_id'] ?? ''),
            'width' => max(11, (float) $item['shipping_width']),
            'height' => max(2, (float) $item['shipping_height']),
            'length' => max(16, (float) $item['shipping_length']),
            'weight' => max(0.1, (float) $item['shipping_weight']),
            'insurance_value' => round((float) $item['unit_price'], 2),
            'quantity' => min(100, max(1, (int) $item['quantity'])),
        ], $items);
        $payload = json_encode([
            'from' => ['postal_code' => $from],
            'to' => ['postal_code' => $to],
            'products' => $products,
            'options' => ['receipt' => false, 'own_hand' => false],
        ], JSON_UNESCAPED_UNICODE);
        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => $this->headers(),
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($response)) {
            $this->lastError = 'Falha de conexão com o Melhor Envio' . ($curlError !== '' ? ': ' . $curlError : '.');
            return [];
        }
        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? 'requisição rejeitada') : 'requisição rejeitada';
            $this->lastError = "Melhor Envio respondeu HTTP {$status}: {$message}";
            error_log($this->lastError);
            return [];
        }
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function agencyCountForCompany(string $companyId, string $city, string $state): ?int
    {
        $city = trim($city);
        $state = strtoupper(trim($state));
        $cacheKey = $companyId . '|' . $state . '|' . mb_strtolower($city);
        if (array_key_exists($cacheKey, $this->agencyAvailabilityCache)) {
            return $this->agencyAvailabilityCache[$cacheKey];
        }

        $query = http_build_query([
            'company' => $companyId,
            'country' => 'BR',
            'state' => $state,
            'city' => $city,
        ], '', '&', PHP_QUERY_RFC3986);
        $curl = curl_init($this->apiUrl('/api/v2/me/shipment/agencies') . '?' . $query);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => $this->headers(),
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if (!is_string($response) || $status < 200 || $status >= 300) {
            error_log(sprintf(
                'Melhor Envio: falha ao validar agências company=%s city=%s state=%s HTTP=%d erro=%s',
                $companyId,
                $city,
                $state,
                $status,
                $curlError
            ));
            return $this->agencyAvailabilityCache[$cacheKey] = null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return $this->agencyAvailabilityCache[$cacheKey] = null;
        }

        if (array_is_list($decoded)) {
            return $this->agencyAvailabilityCache[$cacheKey] = count($decoded);
        }
        foreach (['data', 'agencies', 'results'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $this->agencyAvailabilityCache[$cacheKey] = count($decoded[$key]);
            }
        }

        return $this->agencyAvailabilityCache[$cacheKey] = 0;
    }

    /** @return array{postal_code:string,city:string,state:string} */
    private function originLocationForStore(int $storeId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT COALESCE(sa.postal_code,w.postal_code,'') postal_code,
                    COALESCE(sa.city,w.city,'') city,
                    COALESCE(sa.state,w.state,'') state
               FROM stores st
               LEFT JOIN store_addresses sa
                 ON sa.store_id=COALESCE(st.shipping_source_store_id,st.id)
                AND sa.is_shipping_origin=1
               LEFT JOIN warehouses w
                 ON w.seller_id=st.seller_id
                AND w.status='active'
              WHERE st.id=?
              ORDER BY (sa.id IS NULL),sa.id,w.id
              LIMIT 1"
        );
        $statement->execute([$storeId]);
        $row = $statement->fetch();
        if (!is_array($row)) return ['postal_code' => '', 'city' => '', 'state' => ''];
        return [
            'postal_code' => (string) ($row['postal_code'] ?? ''),
            'city' => trim((string) ($row['city'] ?? '')),
            'state' => strtoupper(trim((string) ($row['state'] ?? ''))),
        ];
    }

    private function isPickupAtOrigin(string $carrier, string $service): bool
    {
        $carrier = mb_strtolower($carrier);
        $service = mb_strtolower($service);
        return str_contains($carrier, 'loggi') && str_contains($service, 'coleta');
    }

    /** @param mixed $packages @param array<int,array<string,mixed>> $items @return array<int,array<string,float>> */
    private function packages(mixed $packages, array $items): array
    {
        $normalized = [];
        if (is_array($packages)) {
            foreach ($packages as $package) {
                if (!is_array($package)) continue;
                $dimensions = is_array($package['dimensions'] ?? null) ? $package['dimensions'] : $package;
                $height = (float) ($dimensions['height'] ?? 0);
                $width = (float) ($dimensions['width'] ?? 0);
                $length = (float) ($dimensions['length'] ?? 0);
                $weight = (float) ($package['weight'] ?? 0);
                if ($height > 0 && $width > 0 && $length > 0 && $weight > 0) {
                    $normalized[] = [
                        'height' => max(2, $height),
                        'width' => max(11, $width),
                        'length' => max(16, $length),
                        'weight' => max(0.1, $weight),
                    ];
                }
            }
        }
        if ($normalized !== []) return $normalized;

        $height = 2.0;
        $width = 11.0;
        $length = 16.0;
        $weight = 0.0;
        foreach ($items as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $height = max($height, (float) ($item['shipping_height'] ?? 2));
            $width = max($width, (float) ($item['shipping_width'] ?? 11));
            $length = max($length, (float) ($item['shipping_length'] ?? 16));
            $weight += max(0.1, (float) ($item['shipping_weight'] ?? 0.1)) * $quantity;
        }
        return [['height' => $height, 'width' => $width, 'length' => $length, 'weight' => max(0.1, $weight)]];
    }

    private function supportsCentralizedPurchase(string $carrier): bool
    {
        $carrier = mb_strtolower($carrier);
        foreach (['azul', 'latam', 'buslog'] as $unsupported) {
            if (str_contains($carrier, $unsupported)) return false;
        }
        return true;
    }

    /** @return array<int,string> */
    private function headers(): array
    {
        return [
            'Authorization: Bearer ' . $this->token(),
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: ' . (string) ($_ENV['MELHOR_ENVIO_USER_AGENT'] ?? 'Tuffer Marketplace (suporte@tuffer.com.br)'),
        ];
    }

    private function apiUrl(string $path): string
    {
        $sandbox = filter_var($_ENV['MELHOR_ENVIO_SANDBOX'] ?? true, FILTER_VALIDATE_BOOL);
        $configuredBase = rtrim(trim((string) ($_ENV['MELHOR_ENVIO_BASE_URL'] ?? '')), '/');
        $base = $configuredBase !== '' ? $configuredBase : ($sandbox ? 'https://sandbox.melhorenvio.com.br' : 'https://www.melhorenvio.com.br');
        if (str_ends_with($base, '/api/v2')) {
            $base = substr($base, 0, -7);
        }
        return $base . '/' . ltrim($path, '/');
    }

    private function token(): string
    {
        return trim((string) ($_ENV['MELHOR_ENVIO_TOKEN'] ?? $_ENV['MELHOR_ENVIO_ACCESS_TOKEN'] ?? ''));
    }

    private function businessDate(int $days): string
    {
        $date = new DateTimeImmutable('today');
        while ($days > 0) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') < 6) $days--;
        }
        return $date->format('d/m/Y');
    }
}
