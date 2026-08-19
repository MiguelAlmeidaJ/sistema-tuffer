<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Core\Database;
use App\Http\Controllers\Controller;

final class StoreController extends Controller
{
    public function index(): string
    {
        $stores = Database::connection()->query("SELECT st.name,st.slug,st.description,st.logo_url,st.banner_url FROM stores st JOIN sellers s ON s.id=st.seller_id WHERE st.status='active' AND s.status='active' AND s.payment_enabled=1 AND s.payment_onboarding_status='active' AND s.pagarme_recipient_id IS NOT NULL ORDER BY CASE WHEN LOWER(st.name) LIKE 'tuffer%' OR LOWER(st.slug) LIKE 'tuffer%' THEN 0 ELSE 1 END,st.name")->fetchAll();
        return $this->page('public/stores/index', 'layouts/public', ['pageTitle' => 'Lojas', 'stores' => $stores]);
    }

    public function show(string $slug): string
    {
        $statement = Database::connection()->prepare("SELECT st.* FROM stores st JOIN sellers s ON s.id=st.seller_id WHERE st.slug=? AND st.status='active' AND s.status='active' AND s.payment_enabled=1 AND s.payment_onboarding_status='active' AND s.pagarme_recipient_id IS NOT NULL");
        $statement->execute([$slug]);
        $store = $statement->fetch();
        if (!$store) { http_response_code(404); return $this->page('errors/404', 'layouts/public', ['pageTitle' => 'Loja não encontrada', 'path' => $slug]); }
        $productStatement = Database::connection()->prepare("SELECT p.name, p.slug, MIN(v.id) variant_id, MIN(COALESCE(v.promotional_price, v.price)) price, MIN(v.price) regular_price, ? store_name, (SELECT pm.secure_url FROM product_media pm WHERE pm.product_id=p.id ORDER BY pm.is_cover DESC, pm.sort_order LIMIT 1) image_url FROM products p JOIN product_variants v ON v.product_id=p.id WHERE p.store_id=? AND p.status='active' AND p.platform_paused=0 AND v.status='active' GROUP BY p.id,p.name,p.slug,p.created_at ORDER BY p.featured DESC,p.created_at DESC");
        $productStatement->execute([$store['name'], $store['id']]);

        return $this->page('public/stores/show', 'layouts/public', [
            'pageTitle' => $store['name'],
            'store' => $store,
            'products' => $productStatement->fetchAll(),
        ]);
    }
}
