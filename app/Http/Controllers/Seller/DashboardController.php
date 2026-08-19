<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Auth;
use App\Core\Database;
use App\Http\Controllers\Controller;
use App\Services\Stores\SellerStoreContext;
use App\Services\Sellers\SellerSalesEligibility;

final class DashboardController extends Controller
{
    public function index(): string
    {
        $context = new SellerStoreContext();
        $store = $context->current();
        $statement = Database::connection()->prepare("SELECT ? trade_name, (SELECT COUNT(*) FROM products p WHERE p.store_id=?) products, (SELECT COUNT(*) FROM seller_orders so WHERE so.store_id=? AND so.status='paid') pending_orders, (SELECT COALESCE(SUM(so.seller_net_total),0) FROM seller_orders so WHERE so.store_id=? AND so.created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')) month_sales");
        $statement->execute([$store['name'], $store['id'], $store['id'], $store['id']]);
        $daily=Database::connection()->prepare("SELECT DATE(created_at) day,COUNT(*) orders,COALESCE(SUM(CASE WHEN status IN ('paid','processing','shipped','delivered') THEN seller_net_total ELSE 0 END),0) revenue FROM seller_orders WHERE store_id=? AND created_at>=CURDATE()-INTERVAL 13 DAY GROUP BY DATE(created_at)");
        $daily->execute([$store['id']]);
        return $this->page('seller/dashboard', 'layouts/seller', ['pageTitle' => 'Dashboard', 'stats' => $statement->fetch(), 'chart'=>$this->chart($daily->fetchAll()), 'currentStore'=>$store, 'sellerStores'=>$context->stores(), 'paymentEnabled'=>(new SellerSalesEligibility())->sellerCanSell((int)$store['seller_id'])]);
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function chart(array $rows): array
    {
        $indexed=[];foreach($rows as $row)$indexed[(string)$row['day']]=$row;$chart=[];
        for($offset=13;$offset>=0;$offset--){$day=date('Y-m-d',strtotime("-{$offset} days"));$row=$indexed[$day]??[];$chart[]=['day'=>$day,'label'=>date('d/m',strtotime($day)),'orders'=>(int)($row['orders']??0),'value'=>(float)($row['revenue']??0)];}
        return $chart;
    }
}
