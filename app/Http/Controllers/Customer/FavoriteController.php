<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Core\Auth;use App\Core\Database;use App\Http\Controllers\Controller;

final class FavoriteController extends Controller
{
    public function index(): string{$s=Database::connection()->prepare("SELECT p.name,p.slug,COALESCE(pv.promotional_price,pv.price) price,st.name store_name,(SELECT pm.secure_url FROM product_media pm WHERE pm.product_id=p.id ORDER BY is_cover DESC,sort_order LIMIT 1) image_url FROM favorites f JOIN products p ON p.id=f.product_id JOIN stores st ON st.id=p.store_id JOIN sellers se ON se.id=p.seller_id JOIN product_variants pv ON pv.product_id=p.id WHERE f.user_id=? AND p.status='active' AND p.platform_paused=0 AND st.status='active' AND se.status='active' AND se.payment_enabled=1 AND se.payment_onboarding_status='active' AND se.pagarme_recipient_id IS NOT NULL ORDER BY f.created_at DESC");$s->execute([Auth::id()]);return $this->page('customer/favorites/index','layouts/customer',['pageTitle'=>'Favoritos','products'=>$s->fetchAll()]);}
}
