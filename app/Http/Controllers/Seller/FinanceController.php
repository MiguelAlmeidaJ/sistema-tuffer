<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Database;use App\Http\Controllers\Controller;use App\Services\Stores\SellerStoreContext;

final class FinanceController extends Controller
{
    public function index(): string{$store=(new SellerStoreContext())->current();$pdo=Database::connection();$s=$pdo->prepare('SELECT COALESCE(SUM(products_total+shipping_total-discount_total),0) gross,COALESCE(SUM(commission_total),0) commissions,COALESCE(SUM(seller_net_total),0) net,COUNT(*) orders FROM seller_orders WHERE store_id=?');$s->execute([$store['id']]);$orders=$pdo->prepare('SELECT code,products_total,commission_total,seller_net_total,status,created_at FROM seller_orders WHERE store_id=? ORDER BY created_at DESC LIMIT 50');$orders->execute([$store['id']]);return $this->page('seller/finance/index','layouts/seller',['pageTitle'=>'Financeiro da loja','summary'=>$s->fetch(),'orders'=>$orders->fetchAll(),'currentStore'=>$store]);}
}
