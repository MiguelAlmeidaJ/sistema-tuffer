<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Http\Controllers\Controller;

final class ReportController extends Controller
{
    public function index(): string
    {
        $pdo = Database::connection();
        $summary = $pdo->query("SELECT (SELECT COUNT(*) FROM stores WHERE status='active') stores, (SELECT COUNT(*) FROM users WHERE type='customer' AND status='active') customers, (SELECT COUNT(*) FROM products WHERE status='active') products, (SELECT COUNT(*) FROM orders) orders, (SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE status IN ('paid','processing','completed')) gmv")->fetch();
        $stores = $pdo->query("SELECT st.name, COUNT(DISTINCT p.id) products, COUNT(DISTINCT so.id) orders, COALESCE(SUM(so.products_total),0) sales FROM stores st LEFT JOIN products p ON p.store_id=st.id LEFT JOIN seller_orders so ON so.store_id=st.id GROUP BY st.id ORDER BY sales DESC LIMIT 10")->fetchAll();
        return $this->page('admin/reports/index', 'layouts/admin', ['pageTitle'=>'Relatório da plataforma','summary'=>$summary,'stores'=>$stores]);
    }
}
