<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Core\Database;
use App\Http\Controllers\Controller;

final class CatalogController extends Controller
{
    public function index(): string { return $this->catalog('Todos os produtos'); }
    public function offers(): string { return $this->catalog('Ofertas', null, true); }
    public function search(): string
    {
        $term = trim((string) ($_GET['q'] ?? ''));
        return $this->catalog($term === '' ? 'Buscar produtos' : 'Resultados para “' . $term . '”');
    }
    public function category(string $slug): string { return $this->catalog('Categoria', $slug); }

    private function catalog(string $title, ?string $fixedCategory = null, bool $offers = false): string
    {
        $pdo = Database::connection();
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'category' => $fixedCategory ?? trim((string) ($_GET['category'] ?? '')),
            'store' => trim((string) ($_GET['store'] ?? '')),
            'brand' => trim((string) ($_GET['brand'] ?? '')),
            'min_price' => max(0, (float) ($_GET['min_price'] ?? 0)),
            'max_price' => max(0, (float) ($_GET['max_price'] ?? 0)),
            'sort' => (string) ($_GET['sort'] ?? 'newest'),
            'offers' => $offers,
        ];

        $where = ["p.status='active'", "p.platform_paused=0", "v.status='active'", "st.status='active'", "s.status='active'", "s.payment_enabled=1", "s.payment_onboarding_status='active'", "s.pagarme_recipient_id IS NOT NULL"];
        $params = [];
        if ($filters['q'] !== '') {
            $where[] = '(p.name LIKE ? OR p.short_description LIKE ? OR p.sku LIKE ? OR st.name LIKE ?)';
            $search = '%' . $filters['q'] . '%';
            array_push($params, $search, $search, $search, $search);
        }
        if ($filters['category'] !== '') {
            $descendants = $pdo->prepare("WITH RECURSIVE category_descendants AS (SELECT id FROM categories WHERE slug=? AND status='active' AND customer_visible=1 UNION ALL SELECT c.id FROM categories c JOIN category_descendants cd ON cd.id=c.parent_id WHERE c.status='active' AND c.customer_visible=1) SELECT id FROM category_descendants");
            $descendants->execute([$filters['category']]);
            $categoryIds = array_map('intval', $descendants->fetchAll(\PDO::FETCH_COLUMN));
            if ($categoryIds) {
                $where[] = 'EXISTS(SELECT 1 FROM product_categories pc WHERE pc.product_id=p.id AND pc.category_id IN (' . implode(',', array_fill(0, count($categoryIds), '?')) . '))';
                array_push($params, ...$categoryIds);
            } else {
                $where[] = '1=0';
            }
        }
        if ($filters['store'] !== '') { $where[] = 'st.slug=?'; $params[] = $filters['store']; }
        if ($filters['brand'] !== '') { $where[] = 'b.slug=?'; $params[] = $filters['brand']; }
        if ($offers) { $where[] = 'v.promotional_price IS NOT NULL AND v.promotional_price < v.price'; }
        if ($filters['min_price'] > 0) { $where[] = 'COALESCE(v.promotional_price,v.price)>=?'; $params[] = $filters['min_price']; }
        if ($filters['max_price'] > 0) { $where[] = 'COALESCE(v.promotional_price,v.price)<=?'; $params[] = $filters['max_price']; }

        $whereSql = implode(' AND ', $where);
        $count = $pdo->prepare("SELECT COUNT(DISTINCT p.id) FROM products p JOIN product_variants v ON v.product_id=p.id JOIN stores st ON st.id=p.store_id JOIN sellers s ON s.id=p.seller_id LEFT JOIN brands b ON b.id=p.brand_id WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $perPage = 12;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($lastPage, max(1, (int) ($_GET['page'] ?? 1)));
        $offset = ($page - 1) * $perPage;
        $orders = [
            'price_asc' => 'price ASC, p.name ASC',
            'price_desc' => 'price DESC, p.name ASC',
            'name' => 'p.name ASC',
            'featured' => 'p.featured DESC, p.created_at DESC',
            'newest' => 'p.created_at DESC',
        ];
        if (!isset($orders[$filters['sort']])) { $filters['sort'] = 'newest'; }

        $sql = "SELECT p.id,p.name,p.slug,p.short_description,p.created_at,MIN(v.id) variant_id,MIN(COALESCE(v.promotional_price,v.price)) price,MIN(v.price) regular_price,MAX(st.name) store_name,MAX(st.slug) store_slug,MAX(b.name) brand_name,(SELECT pm.secure_url FROM product_media pm WHERE pm.product_id=p.id ORDER BY pm.is_cover DESC,pm.sort_order,pm.id LIMIT 1) image_url FROM products p JOIN product_variants v ON v.product_id=p.id JOIN stores st ON st.id=p.store_id JOIN sellers s ON s.id=p.seller_id LEFT JOIN brands b ON b.id=p.brand_id WHERE {$whereSql} GROUP BY p.id,p.name,p.slug,p.short_description,p.created_at,p.featured ORDER BY {$orders[$filters['sort']]} LIMIT {$perPage} OFFSET {$offset}";
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        $pageTitle = $title;
        $metaDescription = null;
        $categorySupport = null;
        $categoryImage = null;
        if ($fixedCategory !== null) {
            $categoryData = $pdo->prepare("SELECT name,meta_title,meta_description,support_text,image_path FROM categories WHERE slug=? AND status='active' AND customer_visible=1");
            $categoryData->execute([$fixedCategory]);
            $category = $categoryData->fetch();
            if (!$category) {
                http_response_code(404);
                return $this->page('errors/404', 'layouts/public', ['pageTitle' => 'Categoria não encontrada', 'path' => $fixedCategory]);
            }
            $title = (string) ($category['name'] ?? 'Categoria');
            $pageTitle = trim((string) ($category['meta_title'] ?? '')) ?: $title;
            $metaDescription = trim((string) ($category['meta_description'] ?? '')) ?: null;
            $categorySupport = trim((string) ($category['support_text'] ?? '')) ?: null;
            $categoryImage = trim((string) ($category['image_path'] ?? '')) ?: null;
        }

        return $this->page('public/catalog/index', 'layouts/public', [
            'pageTitle' => $pageTitle,
            'heading' => $title,
            'metaDescription' => $metaDescription,
            'categorySupport' => $categorySupport,
            'openGraphImage' => $categoryImage ? absolute_url('/uploads/' . ltrim($categoryImage, '/')) : null,
            'products' => $statement->fetchAll(),
            'categories' => $pdo->query("SELECT name,slug FROM categories WHERE status='active' AND customer_visible=1 ORDER BY sort_order,name")->fetchAll(),
            'stores' => $pdo->query("SELECT st.name,st.slug FROM stores st JOIN sellers s ON s.id=st.seller_id WHERE st.status='active' AND s.status='active' AND s.payment_enabled=1 AND s.payment_onboarding_status='active' AND s.pagarme_recipient_id IS NOT NULL ORDER BY st.name")->fetchAll(),
            'brands' => $pdo->query("SELECT name,slug FROM brands WHERE status='active' ORDER BY name")->fetchAll(),
            'filters' => $filters,
            'pagination' => compact('total', 'page', 'lastPage', 'perPage'),
            'fixedCategory' => $fixedCategory,
        ]);
    }
}
