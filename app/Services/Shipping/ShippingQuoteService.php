<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Session;
use App\Services\Cart\CartService;
use DateTimeImmutable;
use App\Services\Settings\PlatformSettings;

final class ShippingQuoteService
{
    private ?string $lastError = null;

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
        $fingerprint = hash('sha256', json_encode(['central-label-v1', $postalCode, array_map(static fn(array $group): array => [$group['store_id'], $group['origin_postal_code'], array_map(static fn(array $item): array => [$item['variant_id'], $item['quantity'], $item['shipping_weight'], $item['shipping_width'], $item['shipping_height'], $item['shipping_length']], $group['items'])], $cart['groups'])], JSON_THROW_ON_ERROR));
        $cached = Session::get($cacheKey);
        if (!$refresh && is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint) return $cached['state'];

        foreach ($cart['groups'] as $group) {
            $storeId = (int) $group['store_id'];
            $origin = preg_replace('/\D+/', '', (string) ($group['origin_postal_code'] ?? '')) ?? '';
            if (strlen($origin) !== 8) {
                $state['stores'][$storeId] = ['store_id' => $storeId, 'store_name' => $group['store_name'], 'options' => [], 'message' => 'A loja ainda não configurou o CEP de origem.'];
                continue;
            }
            $result = $this->request($origin, $postalCode, $group['items']);
            $options = [];
            foreach ($result as $quote) {
                if (!is_array($quote) || isset($quote['error']) || !isset($quote['id'])) continue;
                $carrier = (string) ($quote['company']['name'] ?? 'Transportadora');
                if (!$this->supportsCentralizedPurchase($carrier)) continue;
                $price = (float) ($quote['custom_price'] ?? $quote['price'] ?? 0);
                if ($price <= 0) continue;
                $days = (int) ($quote['custom_delivery_time'] ?? $quote['delivery_time'] ?? 0);
                $range = $quote['custom_delivery_range'] ?? $quote['delivery_range'] ?? [];
                $minDays = max(1, (int) ($range['min'] ?? $days ?: 1));
                $maxDays = max($minDays, (int) ($range['max'] ?? $days ?: $minDays));
                $options[] = [
                    'id' => (string) $quote['id'],
                    'service' => (string) ($quote['name'] ?? 'Entrega'),
                    'carrier' => $carrier,
                    'price' => round($price, 2),
                    'packages' => $this->packages($quote['packages'] ?? [], $group['items']),
                    'min_days' => $minDays,
                    'max_days' => $maxDays,
                    'arrival_min' => $this->businessDate($minDays),
                    'arrival_max' => $this->businessDate($maxDays),
                ];
            }
            usort($options, static fn(array $a, array $b): int => $a['price'] <=> $b['price']);
            $options = array_slice($options, 0, 4);
            $selected = $options[0]['id'] ?? null;
            $state['stores'][$storeId] = ['store_id' => $storeId, 'store_name' => $group['store_name'], 'options' => $options, 'selected' => $selected, 'message' => $options ? null : ($this->lastError ?? 'Nenhuma modalidade disponível para este CEP.')];
            if ($options) $state['shipping_total'] += (float) $options[0]['price'];
        }
        $state['shipping_total'] = round($state['shipping_total'], 2);
        Session::put($cacheKey, ['fingerprint' => $fingerprint, 'state' => $state]);
        return $state;
    }

    /** @param array<int,array<string,mixed>> $items @return array<int,mixed> */
    private function request(string $from, string $to, array $items): array
    {
        $sandbox = filter_var($_ENV['MELHOR_ENVIO_SANDBOX'] ?? true, FILTER_VALIDATE_BOOL);
        $configuredBase = rtrim(trim((string) ($_ENV['MELHOR_ENVIO_BASE_URL'] ?? '')), '/');
        $base = $configuredBase !== '' ? $configuredBase : ($sandbox ? 'https://sandbox.melhorenvio.com.br' : 'https://www.melhorenvio.com.br');
        $endpoint = str_ends_with($base, '/api/v2') ? $base . '/me/shipment/calculate' : $base . '/api/v2/me/shipment/calculate';
        $this->lastError = null;
        $products = array_map(static fn(array $item): array => [
            'id' => (string) $item['variant_id'],
            'width' => max(11, (float) $item['shipping_width']),
            'height' => max(2, (float) $item['shipping_height']),
            'length' => max(16, (float) $item['shipping_length']),
            'weight' => max(0.1, (float) $item['shipping_weight']),
            'insurance_value' => round((float) $item['unit_price'], 2),
            'quantity' => min(100, max(1, (int) $item['quantity'])),
        ], $items);
        $payload = json_encode(['from' => ['postal_code' => $from], 'to' => ['postal_code' => $to], 'products' => $products, 'options' => ['receipt' => false, 'own_hand' => false]], JSON_UNESCAPED_UNICODE);
        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token(), 'Accept: application/json', 'Content-Type: application/json', 'User-Agent: ' . (string) ($_ENV['MELHOR_ENVIO_USER_AGENT'] ?? 'Tuffer Marketplace (suporte@tuffer.com.br)')], CURLOPT_POSTFIELDS => $payload]);
        $response = curl_exec($curl); $curlError = curl_error($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        if (!is_string($response)) { $this->lastError = 'Falha de conexão com o Melhor Envio' . ($curlError !== '' ? ': ' . $curlError : '.'); return []; }
        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300) { $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? 'requisição rejeitada') : 'requisição rejeitada'; $this->lastError = "Melhor Envio respondeu HTTP {$status}: {$message}"; error_log($this->lastError); return []; }
        return is_array($decoded) ? array_values($decoded) : [];
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

    private function token(): string
    {
        return trim((string) ($_ENV['MELHOR_ENVIO_TOKEN'] ?? $_ENV['MELHOR_ENVIO_ACCESS_TOKEN'] ?? ''));
    }

    private function businessDate(int $days): string
    {
        $date = new DateTimeImmutable('today');
        while ($days > 0) { $date = $date->modify('+1 day'); if ((int) $date->format('N') < 6) $days--; }
        return $date->format('d/m/Y');
    }
}
