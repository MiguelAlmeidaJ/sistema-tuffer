<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Core\Database;
use App\Http\Controllers\Controller;
use Throwable;

final class HomeController extends Controller
{
    public function index(): string
    {
        $pdo = Database::connection();
        $categories = $pdo->query("WITH RECURSIVE category_descendants AS (
            SELECT id root_id,id FROM categories WHERE status='active' AND customer_visible=1
            UNION ALL
            SELECT cd.root_id,c.id FROM categories c JOIN category_descendants cd ON c.parent_id=cd.id WHERE c.status='active' AND c.customer_visible=1
        ), category_products AS (
            SELECT cd.root_id,COUNT(DISTINCT p.id) products_count
            FROM category_descendants cd
            JOIN product_categories pc ON pc.category_id=cd.id
            JOIN products p ON p.id=pc.product_id AND p.status='active' AND p.platform_paused=0
            JOIN stores st ON st.id=p.store_id AND st.status='active'
            JOIN sellers s ON s.id=p.seller_id AND s.status='active'
            WHERE EXISTS(SELECT 1 FROM product_variants pv WHERE pv.product_id=p.id AND pv.status='active')
            GROUP BY cd.root_id
        )
        SELECT c.name,c.slug,c.image_path,c.support_text,cp.products_count
        FROM categories c JOIN category_products cp ON cp.root_id=c.id
        WHERE c.status='active' AND c.customer_visible=1 AND cp.products_count>0
        ORDER BY c.show_in_home DESC,c.is_featured DESC,c.sort_order ASC,cp.products_count DESC,c.name ASC
        LIMIT 12")->fetchAll();
        $products = $pdo->query("SELECT p.name, p.slug, (SELECT v2.id FROM product_variants v2 WHERE v2.product_id=p.id AND v2.status='active' ORDER BY COALESCE(v2.promotional_price,v2.price),v2.id LIMIT 1) variant_id, MIN(COALESCE(v.promotional_price, v.price)) price, (SELECT v2.price FROM product_variants v2 WHERE v2.product_id=p.id AND v2.status='active' ORDER BY COALESCE(v2.promotional_price,v2.price),v2.id LIMIT 1) regular_price, MAX(st.name) store_name, MAX(st.slug) store_slug, MAX(st.logo_url) store_logo, (SELECT COALESCE(SUM(sk.quantity-sk.reserved_quantity),0) FROM stocks sk WHERE sk.product_variant_id=(SELECT v2.id FROM product_variants v2 WHERE v2.product_id=p.id AND v2.status='active' ORDER BY COALESCE(v2.promotional_price,v2.price),v2.id LIMIT 1)) available, (SELECT pm.secure_url FROM product_media pm WHERE pm.product_id=p.id ORDER BY pm.is_cover DESC, pm.sort_order LIMIT 1) image_url FROM products p JOIN product_variants v ON v.product_id = p.id JOIN stores st ON st.id=p.store_id JOIN sellers s ON s.id=p.seller_id WHERE p.status = 'active' AND p.platform_paused=0 AND v.status = 'active' AND st.status='active' AND s.status='active' GROUP BY p.id, p.name, p.slug, p.featured, p.created_at ORDER BY p.featured DESC, p.created_at DESC LIMIT 12")->fetchAll();
        $brands = $pdo->query("SELECT name,slug,cover_image_path FROM brands WHERE status='active' AND (LOWER(slug) IN ('tuffer','linda-flor') OR LOWER(name) IN ('tuffer','linda flor')) ORDER BY CASE WHEN LOWER(name)='tuffer' THEN 0 ELSE 1 END")->fetchAll();

        return $this->page('public/home', 'layouts/public', compact('categories', 'products', 'brands') + ['pageTitle' => 'Loja Oficial']);
    }

    public function health(): string
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $started=microtime(true);Database::connection()->query('SELECT 1');$databaseMs=(int)round((microtime(true)-$started)*1000);$storage=dirname(__DIR__,4).'/storage';$writable=is_dir($storage)&&is_writable($storage);if(!$writable)http_response_code(503);
            return json_encode(['status'=>$writable?'ok':'degraded','database'=>'connected','database_ms'=>$databaseMs,'storage'=>$writable?'writable':'unavailable','timestamp'=>date(DATE_ATOM)], JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            http_response_code(503);
            return json_encode(['status' => 'error', 'database' => 'disconnected'], JSON_THROW_ON_ERROR);
        }
    }
}
