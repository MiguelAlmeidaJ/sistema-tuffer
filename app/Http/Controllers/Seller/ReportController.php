<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Database;
use App\Http\Controllers\Controller;
use App\Services\Stores\SellerStoreContext;

final class ReportController extends Controller
{
    public function index(): string
    {
        $store = (new SellerStoreContext())->current();
        $pdo = Database::connection();

        $summaryStatement = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM products WHERE store_id=?) products,
                (SELECT COALESCE(SUM(quantity-reserved_quantity),0)
                   FROM stocks sk
                   JOIN product_variants pv ON pv.id=sk.product_variant_id
                   JOIN products p ON p.id=pv.product_id
                  WHERE p.store_id=?) stock,
                (SELECT COUNT(*) FROM seller_orders WHERE store_id=?) orders,
                (SELECT COALESCE(SUM(products_total),0)
                   FROM seller_orders
                  WHERE store_id=?
                    AND status IN ('paid','processing','shipped','delivered')) sales"
        );
        $summaryStatement->execute([
            $store['id'],
            $store['id'],
            $store['id'],
            $store['id'],
        ]);
        $summary = $summaryStatement->fetch();

        $topStatement = $pdo->prepare(
            "SELECT
                p.name,
                COALESCE(SUM(CASE
                    WHEN so.status IN ('paid','processing','shipped','delivered')
                    THEN oi.quantity
                    ELSE 0
                END),0) sold,
                COALESCE(SUM(CASE
                    WHEN so.status IN ('paid','processing','shipped','delivered')
                    THEN oi.total
                    ELSE 0
                END),0) revenue
             FROM products p
             LEFT JOIN order_items oi ON oi.product_id=p.id
             LEFT JOIN seller_orders so ON so.id=oi.seller_order_id
             WHERE p.store_id=?
             GROUP BY p.id
             ORDER BY sold DESC
             LIMIT 10"
        );
        $topStatement->execute([$store['id']]);

        return $this->page('seller/reports/index', 'layouts/seller', [
            'pageTitle' => 'Relatório da loja',
            'summary' => $summary,
            'topProducts' => $topStatement->fetchAll(),
            'currentStore' => $store,
        ]);
    }
}
