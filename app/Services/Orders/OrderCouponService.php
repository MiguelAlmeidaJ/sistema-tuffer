<?php

declare(strict_types=1);

namespace App\Services\Orders;

use PDO;
use RuntimeException;

final class OrderCouponService
{
    /** @param array<string,mixed> $group */
    public function reserve(PDO $pdo, int $orderId, int $sellerOrderId, array $group): void
    {
        $coupon = $group['coupon'] ?? null;
        $discount = round((float) ($group['discount'] ?? 0), 2);
        if (!is_array($coupon) || $discount <= 0) return;
        $statement = $pdo->prepare("UPDATE coupons SET usage_count=usage_count+1 WHERE id=? AND store_id=? AND status='active' AND (starts_at IS NULL OR starts_at<=NOW()) AND (expires_at IS NULL OR expires_at>=NOW()) AND (usage_limit IS NULL OR usage_count<usage_limit)");
        $statement->execute([(int) $coupon['id'], (int) $group['store_id']]);
        if ($statement->rowCount() !== 1) throw new RuntimeException('O cupom selecionado atingiu o limite de uso. Revise o carrinho.');
        $fundingSource = ($coupon['funding_source'] ?? null) === 'platform' ? 'platform' : 'seller';
        $discountCents = (int) round($discount * 100);
        $pdo->prepare('INSERT INTO order_coupons(order_id,seller_order_id,coupon_id,funding_source,discount_amount,discount_amount_cents) VALUES(?,?,?,?,?,?)')
            ->execute([$orderId, $sellerOrderId, (int) $coupon['id'], $fundingSource, number_format($discount, 2, '.', ''), $discountCents]);
    }

    public function redeem(PDO $pdo, int $orderId): void
    {
        $pdo->prepare('UPDATE order_coupons SET redeemed_at=COALESCE(redeemed_at,NOW()) WHERE order_id=? AND released_at IS NULL')->execute([$orderId]);
    }

    public function release(PDO $pdo, int $orderId): void
    {
        $statement = $pdo->prepare('SELECT id,coupon_id FROM order_coupons WHERE order_id=? AND redeemed_at IS NULL AND released_at IS NULL FOR UPDATE');
        $statement->execute([$orderId]);
        foreach ($statement->fetchAll() as $reservation) {
            $pdo->prepare('UPDATE coupons SET usage_count=GREATEST(0,usage_count-1) WHERE id=?')->execute([$reservation['coupon_id']]);
            $pdo->prepare('UPDATE order_coupons SET released_at=NOW() WHERE id=? AND released_at IS NULL')->execute([$reservation['id']]);
        }
    }
}
