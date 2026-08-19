<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Core\Database;
use App\Services\Cart\CartService;
use App\Services\Payments\PagarmeClient;
use App\Services\Payments\PagarmePaymentLinkBuilder;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\MarketplaceFinancialPolicy;
use App\Services\Payments\Pagarme\PagarmeCheckoutConfiguration;
use App\Services\Payments\Pagarme\PagarmeSplitService;
use App\Services\Finance\MarketplaceFinancialLedgerService;
use App\Services\Queue\JobQueue;
use App\Services\Settings\PlatformSettings;
use App\Services\Sellers\SellerSalesEligibility;
use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class OrderPlacementService
{
    private const SAVEPOINT = 'transactional_checkout';
    public const TERMS_VERSION = 'checkout-2026-07-v1';

    private readonly PDO $pdo;
    private readonly PaymentGateway $gateway;
    private readonly PagarmePaymentLinkBuilder $paymentLinkBuilder;
    private readonly PagarmeCheckoutConfiguration $checkoutConfiguration;
    private readonly MarketplaceFinancialPolicy $financialPolicy;

    public function __construct(
        ?PDO $pdo = null,
        ?PaymentGateway $gateway = null,
        ?PagarmePaymentLinkBuilder $paymentLinkBuilder = null,
        ?PagarmeCheckoutConfiguration $checkoutConfiguration = null,
        ?MarketplaceFinancialPolicy $financialPolicy = null
    ) {
        $this->pdo = $pdo ?? Database::connection();
        $this->gateway = $gateway ?? new PagarmeClient();
        $this->paymentLinkBuilder = $paymentLinkBuilder ?? new PagarmePaymentLinkBuilder();
        $this->checkoutConfiguration = $checkoutConfiguration ?? new PagarmeCheckoutConfiguration();
        $this->financialPolicy = $financialPolicy ?? new MarketplaceFinancialPolicy();
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * @param array<int,array<string,mixed>> $shippingSelections Indexed by store ID.
     * @return array{order_id:int,order_code:string,payment_id:int,payment_url:?string,payment_queued:bool}
     */
    public function place(
        int $userId,
        int $cartId,
        int $addressId,
        array $shippingSelections,
        string $paymentMethod,
        array $consent = []
    ): array {
        if (!$this->gateway->configured()) {
            throw new RuntimeException('A integração com a Pagar.me ainda não está configurada.');
        }
        if (!in_array($paymentMethod, ['pix', 'card', 'boleto'], true)) {
            throw new RuntimeException('Selecione uma forma de pagamento válida.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        $committed = false;
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }

        try {
            $cartRow = $this->lockCart($cartId, $userId);
            $this->lockCartDependencies($cartId);
            $cartService = new CartService();
            if ($cartService->id() !== $cartId) {
                throw new RuntimeException('O carrinho ativo mudou. Revise a compra antes de continuar.');
            }
            $cart = $cartService->summary();
            if ($cart['items'] === []) {
                throw new RuntimeException('Seu carrinho está vazio.');
            }
            (new SellerSalesEligibility($this->pdo))->assertAllCanSell(array_map(
                static fn(array $item): int => (int) ($item['seller_id'] ?? 0),
                $cart['items']
            ));
            if (!($cart['minimums_met'] ?? false)) {
                throw new RuntimeException('Complete os mínimos de cada loja antes de finalizar.');
            }

            $address = $this->address($addressId, $userId);
            $customer = $this->customer($userId);
            $selections = $this->validateShippingSelections($cart['groups'], $shippingSelections);
            $productsCents = $this->cents($cart['subtotal']);
            $discountCents = $this->cents($cart['discount_total']);
            $shippingCents = array_sum(array_map(fn(array $selection): int => $this->cents($selection['price']), $selections));
            $grandTotalCents = $productsCents - $discountCents + $shippingCents;
            if ($grandTotalCents < 1) {
                throw new RuntimeException('O total do pedido precisa ser maior que zero.');
            }

            $fingerprint = $this->fingerprint($cart, $selections, $addressId, $paymentMethod);
            $orderCode = $this->orderCode($cartId, $fingerprint);
            $idempotencyKey = 'checkout-' . $cartId . '-' . substr($fingerprint, 0, 40);
            $orderId = $this->createOrder(
                $userId,
                $orderCode,
                (string) $cartRow['cart_type'],
                $productsCents,
                $shippingCents,
                $discountCents,
                $grandTotalCents,
                $consent
            );
            $this->snapshotAddress($orderId, $address);

            foreach ($cart['groups'] as $group) {
                $storeId = (int) $group['store_id'];
                $this->createSellerOrder($orderId, $orderCode, $group, $selections[$storeId], $userId);
            }

            $this->pdo->prepare('INSERT INTO order_status_history(order_id,status,notes,created_by) VALUES(?,?,?,?)')
                ->execute([$orderId, 'pending_payment', 'Pedido criado e estoque reservado; aguardando pagamento.', $userId]);
            $sellerIds = array_values(array_unique(array_map(
                static fn(array $item): int => (int) ($item['seller_id'] ?? 0),
                $cart['items']
            )));
            $usesOrders = $this->checkoutConfiguration->usesOrders($paymentMethod, $sellerIds);
            $paymentId = $this->createPayment(
                $orderId,
                $paymentMethod,
                $grandTotalCents,
                $idempotencyKey,
                $usesOrders ? 'orders' : 'payment_link'
            );

            if ($usesOrders) {
                (new PagarmeSplitService($this->pdo, $this->financialPolicy))->createSnapshot($paymentId);
                (new MarketplaceFinancialLedgerService($this->pdo))->createPending($paymentId);
                (new JobQueue($this->pdo))->dispatch(
                    'pagarme.create_order',
                    ['payment_id' => $paymentId],
                    'pagarme-order:' . $paymentId,
                    'payment',
                    8,
                    10,
                );
            } else {
                $successUrl = $this->successUrl($orderCode);
                $payload = $this->paymentLinkBuilder->build(
                    $orderCode,
                    $grandTotalCents,
                    $cart['groups'],
                    $selections,
                    $customer,
                    $address,
                    $paymentMethod,
                    $successUrl
                );
                (new JobQueue($this->pdo))->dispatch(
                    'pagarme.create_payment_link',
                    ['payment_id' => $paymentId, 'request' => $payload, 'idempotency_key' => $idempotencyKey],
                    'pagarme-payment-link:' . $paymentId,
                    'payment',
                    8,
                    10,
                );
            }
            $this->pdo->prepare("UPDATE carts SET status='converted',updated_at=NOW() WHERE id=? AND status='active'")->execute([$cartId]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            $committed = true;

            return [
                'order_id' => $orderId,
                'order_code' => $orderCode,
                'payment_id' => $paymentId,
                'payment_url' => null,
                'payment_queued' => true,
            ];
        } catch (Throwable $exception) {
            if (!$committed && $this->pdo->inTransaction()) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                } else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                }
            }
            if ($exception instanceof RuntimeException && !$exception instanceof PDOException) {
                throw $exception;
            }
            throw new RuntimeException('Não foi possível criar o pedido. Tente novamente.', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    private function lockCart(int $cartId, int $userId): array
    {
        $statement = $this->pdo->prepare("SELECT id,cart_type FROM carts WHERE id=? AND user_id=? AND status='active' FOR UPDATE");
        $statement->execute([$cartId, $userId]);
        $cart = $statement->fetch();
        if (!is_array($cart)) {
            throw new RuntimeException('Este carrinho já foi finalizado ou não está mais disponível.');
        }
        return $cart;
    }

    private function lockCartDependencies(int $cartId): void
    {
        $items = $this->pdo->prepare('SELECT id FROM cart_items WHERE cart_id=? ORDER BY id FOR UPDATE');
        $items->execute([$cartId]);
        $items->fetchAll();
        $coupons = $this->pdo->prepare('SELECT c.id FROM coupons c JOIN cart_coupons cc ON cc.coupon_id=c.id WHERE cc.cart_id=? ORDER BY c.id FOR UPDATE');
        $coupons->execute([$cartId]);
        $coupons->fetchAll();
    }

    /** @return array<string,mixed> */
    private function address(int $addressId, int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM user_addresses WHERE id=? AND user_id=?');
        $statement->execute([$addressId, $userId]);
        $address = $statement->fetch();
        if (!is_array($address)) {
            throw new RuntimeException('Selecione um endereço de entrega válido.');
        }
        return $address;
    }

    /** @return array<string,mixed> */
    private function customer(int $userId): array
    {
        $statement = $this->pdo->prepare("SELECT id,name,email,phone,document FROM users WHERE id=? AND type='customer' AND status='active'");
        $statement->execute([$userId]);
        $customer = $statement->fetch();
        if (!is_array($customer)) {
            throw new RuntimeException('Sua conta não está habilitada para concluir compras.');
        }
        return $customer;
    }

    /** @param array<int,array<string,mixed>> $groups @param array<int,array<string,mixed>> $selections @return array<int,array<string,mixed>> */
    private function validateShippingSelections(array $groups, array $selections): array
    {
        $validated = [];
        foreach ($groups as $group) {
            $storeId = (int) $group['store_id'];
            $selection = $selections[$storeId] ?? null;
            if (!is_array($selection) || trim((string) ($selection['id'] ?? '')) === '' || (float) ($selection['price'] ?? 0) <= 0) {
                throw new RuntimeException('Selecione uma modalidade de entrega válida para cada loja.');
            }
            $validated[$storeId] = $selection;
        }
        return $validated;
    }

    /** @param array<string,mixed> $consent */
    private function createOrder(int $userId, string $code, string $type, int $products, int $shipping, int $discount, int $grand, array $consent): int
    {
        if (($consent['version'] ?? '') !== self::TERMS_VERSION || !is_string($consent['ip_hash'] ?? null) || strlen($consent['ip_hash']) !== 64) throw new RuntimeException('Não foi possível registrar o aceite dos termos da compra.');
        $statement = $this->pdo->prepare("INSERT INTO orders(user_id,code,products_total,shipping_total,discount_total,grand_total,currency,status,placed_at,order_type,terms_version,terms_accepted_at,terms_ip_hash) VALUES(?,?,?,?,?,?,?,'pending_payment',NOW(),?,?,NOW(),?)");
        $statement->execute([$userId, $code, $this->money($products), $this->money($shipping), $this->money($discount), $this->money($grand), 'BRL', $type, self::TERMS_VERSION, $consent['ip_hash']]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $address */
    private function snapshotAddress(int $orderId, array $address): void
    {
        $statement = $this->pdo->prepare('INSERT INTO order_addresses(order_id,recipient_name,postal_code,street,number,complement,neighborhood,city,state) VALUES(?,?,?,?,?,?,?,?,?)');
        $statement->execute([$orderId, $address['recipient_name'], $address['postal_code'], $address['street'], $address['number'], $address['complement'] ?: null, $address['neighborhood'], $address['city'], mb_strtoupper((string) $address['state'])]);
    }

    /** @param array<string,mixed> $group @param array<string,mixed> $shipping */
    private function createSellerOrder(int $orderId, string $orderCode, array $group, array $shipping, int $userId): void
    {
        $sellerId = (int) ($group['items'][0]['seller_id'] ?? 0);
        $storeId = (int) $group['store_id'];
        if ($sellerId < 1 || $storeId < 1) {
            throw new RuntimeException('Uma das lojas do carrinho não está disponível.');
        }
        $commission = $this->commissionRate($sellerId);
        $products = $this->cents($group['subtotal']);
        $discount = $this->cents($group['discount'] ?? 0);
        $shippingCents = $this->cents($shipping['price']);
        $financial = $this->financialPolicy->sellerAmounts(
            $products,
            $shippingCents,
            $discount,
            $commission,
            $this->financialPolicy->couponFundingSource($group['coupon']['funding_source'] ?? null)
        );
        $commissionCents = $financial['commission_cents'];
        $sellerNet = $financial['seller_net_cents'];
        $sellerOrderCode = mb_substr($orderCode . '-L' . $storeId, 0, 50);
        $quotePayload = json_encode([
            'service_id' => (string) ($shipping['id'] ?? ''),
            'service' => (string) ($shipping['service'] ?? 'Entrega'),
            'carrier' => (string) ($shipping['carrier'] ?? 'Transportadora'),
            'price' => round((float) ($shipping['price'] ?? 0), 2),
            'packages' => is_array($shipping['packages'] ?? null) ? $shipping['packages'] : [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $statement = $this->pdo->prepare("INSERT INTO seller_orders(order_id,seller_id,store_id,code,products_total,shipping_total,discount_total,commission_rate,commission_total,seller_net_total,status) VALUES(?,?,?,?,?,?,?,?,?,?,'pending_payment')");
        $statement->execute([$orderId, $sellerId, $storeId, $sellerOrderCode, $this->money($products), $this->money($shippingCents), $this->money($discount), number_format($commission, 2, '.', ''), $this->money($commissionCents), $this->money($sellerNet)]);
        $sellerOrderId = (int) $this->pdo->lastInsertId();
        (new OrderCouponService())->reserve($this->pdo, $orderId, $sellerOrderId, $group);

        $itemInsert = $this->pdo->prepare('INSERT INTO order_items(seller_order_id,product_id,product_variant_id,product_name,sku,quantity,unit_price,total) VALUES(?,?,?,?,?,?,?,?)');
        foreach ($group['items'] as $item) {
            $lineCents = $this->cents($item['line_total']);
            $itemInsert->execute([$sellerOrderId, $item['product_id'], $item['variant_id'], $item['name'], $item['sku'], $item['quantity'], $this->money($this->cents($item['unit_price'])), $this->money($lineCents)]);
            $this->reserveStock((int) $item['variant_id'], (int) $item['quantity'], $orderId, $userId);
        }

        $warehouseId = $this->warehouseId($sellerId);
        $shipment = $this->pdo->prepare("INSERT INTO shipments(seller_order_id,warehouse_id,provider,service_id,service_name,carrier_name,shipping_cost,quote_payload,estimated_delivery_min,estimated_delivery_max,status) VALUES(?,?,'melhor_envio',?,?,?,?,?,?,?,'pending')");
        $shipment->execute([
            $sellerOrderId,
            $warehouseId ?: null,
            (string) $shipping['id'],
            mb_substr((string) ($shipping['service'] ?? 'Entrega'), 0, 120),
            mb_substr((string) ($shipping['carrier'] ?? 'Transportadora'), 0, 120),
            $this->money($shippingCents),
            $quotePayload,
            $this->shippingDate($shipping['arrival_min'] ?? null),
            $this->shippingDate($shipping['arrival_max'] ?? null),
        ]);
    }

    private function reserveStock(int $variantId, int $quantity, int $orderId, int $userId): void
    {
        $statement = $this->pdo->prepare('SELECT id,quantity,reserved_quantity FROM stocks WHERE product_variant_id=? ORDER BY id FOR UPDATE');
        $statement->execute([$variantId]);
        $stocks = $statement->fetchAll();
        $remaining = $quantity;
        foreach ($stocks as $stock) {
            $available = max(0, (int) $stock['quantity'] - (int) $stock['reserved_quantity']);
            $reserved = min($remaining, $available);
            if ($reserved < 1) {
                continue;
            }
            $update = $this->pdo->prepare('UPDATE stocks SET reserved_quantity=reserved_quantity+? WHERE id=? AND quantity-reserved_quantity>=?');
            $update->execute([$reserved, $stock['id'], $reserved]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('O estoque mudou durante a compra. Revise o carrinho.');
            }
            $this->pdo->prepare("INSERT INTO stock_movements(stock_id,user_id,type,quantity,reference_type,reference_id,notes) VALUES(?,?,'reserve',?,'order',?,'Reserva criada no checkout')")
                ->execute([$stock['id'], $userId, $reserved, $orderId]);
            $remaining -= $reserved;
            if ($remaining === 0) {
                break;
            }
        }
        if ($remaining > 0) {
            throw new RuntimeException('Um dos produtos ficou sem estoque. Revise o carrinho.');
        }
    }

    private function createPayment(int $orderId, string $method, int $amount, string $idempotencyKey, string $integrationType): int
    {
        $statement = $this->pdo->prepare("INSERT INTO payments(order_id,provider,integration_type,method,amount,amount_cents,status,idempotency_key,expires_at) VALUES(?,'pagarme',?,?,?,?, 'pending',?,DATE_ADD(NOW(),INTERVAL 1 DAY))");
        $statement->execute([$orderId, $integrationType, $method, $this->money($amount), $amount, $idempotencyKey]);
        return (int) $this->pdo->lastInsertId();
    }

    private function commissionRate(int $sellerId): float
    {
        $statement = $this->pdo->prepare('SELECT commission_rate FROM sellers WHERE id=? AND status=?');
        $statement->execute([$sellerId, 'active']);
        $value = $statement->fetchColumn();
        if ($value === false) {
            throw new RuntimeException('Uma das lojas não está habilitada para receber pedidos.');
        }
        $settings = PlatformSettings::all();
        return max(0.0, min(100.0, (float) ($value ?? $settings['default_commission'] ?? 10)));
    }

    private function warehouseId(int $sellerId): int
    {
        $statement = $this->pdo->prepare("SELECT id FROM warehouses WHERE seller_id=? AND status='active' ORDER BY id LIMIT 1");
        $statement->execute([$sellerId]);
        return (int) $statement->fetchColumn();
    }

    /** @param array<string,mixed> $cart @param array<int,array<string,mixed>> $shipping */
    private function fingerprint(array $cart, array $shipping, int $addressId, string $method): string
    {
        $items = array_map(static fn(array $item): array => [
            (int) $item['variant_id'],
            (int) $item['quantity'],
            (string) $item['unit_price'],
            (int) $item['store_id'],
        ], $cart['items']);
        ksort($shipping);
        $shippingValues = array_map(static fn(array $item): array => [(string) $item['id'], (string) $item['price']], $shipping);
        return hash('sha256', json_encode([$items, $shippingValues, $addressId, $method, $cart['type']], JSON_THROW_ON_ERROR));
    }

    private function orderCode(int $cartId, string $fingerprint): string
    {
        $settings = PlatformSettings::all();
        $prefix = preg_replace('/[^A-Za-z0-9-]+/', '', (string) ($settings['orders_prefix'] ?? 'TF')) ?: 'TF';
        return mb_substr(mb_strtoupper($prefix) . '-' . $cartId . '-' . mb_strtoupper(substr($fingerprint, 0, 12)), 0, 50);
    }

    private function successUrl(string $orderCode): string
    {
        $base = rtrim(trim((string) ($_ENV['APP_URL'] ?? '')), '/');
        if (!preg_match('#^https?://#i', $base)) {
            throw new RuntimeException('Configure APP_URL com a URL completa do sistema antes de receber pagamentos.');
        }
        return $base . '/minha-conta/pedidos/' . rawurlencode($orderCode) . '?pagamento=retorno';
    }

    private function shippingDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!d/m/Y', $value);
        return $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : null;
    }

    private function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
