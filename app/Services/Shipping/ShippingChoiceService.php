<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Session;
use App\Services\Cart\CartService;

/** Persist only a service ID, never trust a quoted price from a browser/session. */
final class ShippingChoiceService
{
    private function key(): string
    {
        return 'cart_shipping_choice_' . ((new CartService())->id() ?: 'guest');
    }

    /** @param array<string,mixed> $cart */
    public function fingerprint(array $cart, string $postalCode): string
    {
        $groups = [];
        foreach (($cart['groups'] ?? []) as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                $items[] = [
                    (int)$item['variant_id'], (int)$item['quantity'],
                    (float)$item['unit_price'],
                    (float)$item['shipping_weight'],
                    (float)$item['shipping_width'],
                    (float)$item['shipping_height'],
                    (float)$item['shipping_length'],
                ];
            }
            usort($items, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $groups[] = [(int)$group['store_id'], (string)$group['origin_postal_code'], $items];
        }
        usort($groups, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return hash('sha256', json_encode([preg_replace('/\D+/', '', $postalCode), $groups], JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $cart */
    public function selected(array $cart, string $postalCode, int $storeId): ?string
    {
        $saved = Session::get($this->key());
        if (!is_array($saved) || ($saved['fingerprint'] ?? '') !== $this->fingerprint($cart, $postalCode)
            || time() - (int)($saved['created_at'] ?? 0) > 1800) return null;
        $id = $saved['services'][$storeId] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @param array<string,mixed> $cart @param array<string,mixed> $quoteState */
    public function save(array $cart, string $postalCode, int $storeId, string $serviceId, array $quoteState): bool
    {
        $valid = false;
        foreach (($quoteState['stores'][$storeId]['options'] ?? []) as $option) {
            if ((string)($option['id'] ?? '') === $serviceId) $valid = true;
        }
        if (!$valid) return false;
        $signature = $this->fingerprint($cart, $postalCode);
        $existing = Session::get($this->key());
        $services = is_array($existing) && ($existing['fingerprint'] ?? '') === $signature
            ? (array)($existing['services'] ?? []) : [];
        $services[$storeId] = $serviceId;
        Session::put($this->key(), ['fingerprint'=>$signature,'services'=>$services,'created_at'=>time()]);
        return true;
    }

    /** @param array<string,mixed> $cart @param array<string,mixed> $state */
    public function apply(array $cart, array $state): array
    {
        $total = 0.0;
        $complete = true;
        $postal = (string)($state['postal_code'] ?? '');
        foreach (($cart['groups'] ?? []) as $group) {
            $storeId = (int)$group['store_id'];
            $id = $this->selected($cart, $postal, $storeId);
            $matched = null;
            foreach (($state['stores'][$storeId]['options'] ?? []) as $option) {
                if ($id !== null && (string)$option['id'] === $id) { $matched = $option; break; }
            }
            $state['stores'][$storeId]['selected'] = $matched['id'] ?? null;
            $state['stores'][$storeId]['selected_option'] = $matched;
            if ($matched === null) $complete = false;
            else $total += (float)$matched['price'];
        }
        $state['shipping_total'] = $complete ? round($total, 2) : 0.0;
        $state['selection_complete'] = $complete && !empty($cart['groups']);
        return $state;
    }

    public function rememberProduct(string $postalCode, int $variantId, int $quantity, int $storeId, string $serviceId): void
    {
        Session::put('shipping_product_preference', [
            'postal_code'=>preg_replace('/\D+/', '', $postalCode),
            'variant_id'=>$variantId,
            'quantity'=>$quantity,
            'store_id'=>$storeId,
            'service_id'=>$serviceId,
            'created_at'=>time(),
        ]);
    }

    /** @param array<string,mixed> $cart @param array<string,mixed> $quoteState */
    public function promoteProduct(array $cart, array $quoteState): void
    {
        $pref = Session::get('shipping_product_preference');
        if (!is_array($pref) || time()-(int)($pref['created_at'] ?? 0)>1800) return;
        $groups = $cart['groups'] ?? [];
        if (count($groups)!==1 || count($groups[0]['items'] ?? [])!==1) return;
        $item = $groups[0]['items'][0];
        if ((int)$pref['variant_id'] !== (int)$item['variant_id']
            || (int)$pref['quantity'] !== (int)$item['quantity']
            || (int)$pref['store_id'] !== (int)$groups[0]['store_id']
            || (string)$pref['postal_code'] !== (string)($quoteState['postal_code'] ?? '')) return;
        $this->save($cart, (string)$pref['postal_code'], (int)$pref['store_id'], (string)$pref['service_id'], $quoteState);
        Session::forget('shipping_product_preference');
    }
}
