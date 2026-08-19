<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Core\Auth;
use App\Core\Database;
use App\Http\Controllers\Controller;
use App\Services\Cart\CartService;
use App\Services\Shipping\ShippingQuoteService;
use JsonException;

final class ProductController extends Controller
{
    public function show(string $slug): string
    {
        $pdo = Database::connection();
        $preview = ($_GET['preview'] ?? '') === '1' && (Auth::user()['type'] ?? null) === 'seller';
        if ($preview) {
            $statement = $pdo->prepare("SELECT p.*,st.name store_name,st.slug store_slug,st.description store_description,st.logo_url store_logo,b.name brand_name,seo.title seo_title,seo.description seo_description,seo.keywords seo_keywords FROM products p JOIN stores st ON st.id=p.store_id JOIN sellers s ON s.id=p.seller_id LEFT JOIN brands b ON b.id=p.brand_id LEFT JOIN product_seo seo ON seo.product_id=p.id WHERE p.slug=? AND s.user_id=? LIMIT 1");
            $statement->execute([$slug, Auth::id()]);
        } else {
            $statement = $pdo->prepare("SELECT p.*,st.name store_name,st.slug store_slug,st.description store_description,st.logo_url store_logo,b.name brand_name,seo.title seo_title,seo.description seo_description,seo.keywords seo_keywords FROM products p JOIN stores st ON st.id=p.store_id JOIN sellers s ON s.id=p.seller_id LEFT JOIN brands b ON b.id=p.brand_id LEFT JOIN product_seo seo ON seo.product_id=p.id WHERE p.slug=? AND p.status='active' AND p.platform_paused=0 AND st.status='active' AND s.status='active' AND s.payment_enabled=1 AND s.payment_onboarding_status='active' AND s.pagarme_recipient_id IS NOT NULL LIMIT 1");
            $statement->execute([$slug]);
        }
        $product = $statement->fetch();
        if (!$product) {
            http_response_code(404);
            return $this->page('errors/404', 'layouts/public', ['pageTitle' => 'Produto não encontrado', 'path' => $slug]);
        }

        $variants = $pdo->prepare("SELECT v.id,v.sku,v.name,v.price,v.promotional_price,v.wholesale_price,COALESCE(v.weight,p.weight,0.1) shipping_weight,COALESCE(v.width,p.width,11) shipping_width,COALESCE(v.height,p.height,2) shipping_height,COALESCE(v.length,p.length,16) shipping_length,COALESCE(SUM(sk.quantity-sk.reserved_quantity),0) available FROM product_variants v JOIN products p ON p.id=v.product_id LEFT JOIN stocks sk ON sk.product_variant_id=v.id WHERE v.product_id=? AND v.status='active' GROUP BY v.id,p.id ORDER BY COALESCE(v.promotional_price,v.price),v.id");
        $variants->execute([$product['id']]);
        $variantRows = $variants->fetchAll();
        if (!$variantRows) {
            http_response_code(404);
            return $this->page('errors/404', 'layouts/public', ['pageTitle' => 'Produto indisponível', 'path' => $slug]);
        }
        $media = $pdo->prepare("SELECT resource_type,secure_url,thumbnail_url,format,duration,is_cover FROM product_media WHERE product_id=? ORDER BY CASE WHEN resource_type='video' THEN 0 ELSE 1 END,is_cover DESC,sort_order,id");
        $media->execute([$product['id']]);
        $mediaRows = $media->fetchAll();
        $categories = $pdo->prepare("SELECT c.id,c.name,c.slug FROM categories c JOIN product_categories pc ON pc.category_id=c.id WHERE pc.product_id=? AND c.status='active' ORDER BY c.name");
        $categories->execute([$product['id']]);
        $categoryRows = $categories->fetchAll();
        $categoryIds = array_map(static fn(array $row): int => (int) $row['id'], $categoryRows);
        $relatedParams = [$product['id'], $product['store_id']];
        $relatedWhere = 'p.id<>? AND p.status=\'active\' AND p.platform_paused=0 AND v.status=\'active\' AND st.status=\'active\' AND s.status=\'active\' AND s.payment_enabled=1 AND s.payment_onboarding_status=\'active\' AND s.pagarme_recipient_id IS NOT NULL AND (p.store_id=?';
        if ($categoryIds) {
            $relatedWhere .= ' OR EXISTS(SELECT 1 FROM product_categories rpc WHERE rpc.product_id=p.id AND rpc.category_id IN (' . implode(',', array_fill(0, count($categoryIds), '?')) . ')';
            $relatedParams = array_merge($relatedParams, $categoryIds);
        }
        $relatedWhere .= $categoryIds ? '))' : ')';
        $related = $pdo->prepare("SELECT p.name,p.slug,MIN(v.id) variant_id,MIN(COALESCE(v.promotional_price,v.price)) price,MIN(v.price) regular_price,MAX(st.name) store_name,(SELECT pm.secure_url FROM product_media pm WHERE pm.product_id=p.id ORDER BY pm.is_cover DESC,pm.sort_order LIMIT 1) image_url FROM products p JOIN product_variants v ON v.product_id=p.id JOIN stores st ON st.id=p.store_id JOIN sellers s ON s.id=p.seller_id WHERE {$relatedWhere} GROUP BY p.id,p.name,p.slug,p.featured,p.created_at ORDER BY p.featured DESC,p.created_at DESC LIMIT 4");
        $related->execute($relatedParams);

        $wholesaleApproved = false;
        if ((Auth::user()['type'] ?? null) === 'customer') {
            $approved = $pdo->prepare("SELECT COUNT(*) FROM wholesale_accounts WHERE user_id=? AND status='approved'");
            $approved->execute([Auth::id()]);
            $wholesaleApproved = (int) $approved->fetchColumn() === 1;
        }
        $productUrl=absolute_url('/produto/'.$product['slug']);
        $imageUrls=array_values(array_map(static fn(array $row):string=>str_starts_with((string)$row['secure_url'],'http')?(string)$row['secure_url']:absolute_url('/uploads/'.ltrim((string)$row['secure_url'],'/')),array_filter($mediaRows,static fn(array $row):bool=>$row['resource_type']!=='video')));
        $offers=array_map(static fn(array $variant):array=>['@type'=>'Offer','sku'=>(string)$variant['sku'],'priceCurrency'=>'BRL','price'=>number_format((float)($variant['promotional_price']?:$variant['price']),2,'.',''),'availability'=>(int)$variant['available']>0?'https://schema.org/InStock':'https://schema.org/OutOfStock','url'=>$productUrl],$variantRows);
        $productSchema=['@context'=>'https://schema.org','@type'=>'Product','name'=>$product['name'],'description'=>$product['short_description']?:trim(strip_tags((string)$product['description'])),'sku'=>$product['sku']?:$variantRows[0]['sku'],'image'=>$imageUrls,'offers'=>$offers];if($product['brand_name'])$productSchema['brand']=['@type'=>'Brand','name'=>$product['brand_name']];
        $structuredData=[$productSchema,['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>array_values(array_filter([['@type'=>'ListItem','position'=>1,'name'=>'Início','item'=>absolute_url('/')],['@type'=>'ListItem','position'=>2,'name'=>'Produtos','item'=>absolute_url('/produtos')],$categoryRows?['@type'=>'ListItem','position'=>3,'name'=>$categoryRows[0]['name'],'item'=>absolute_url('/categoria/'.$categoryRows[0]['slug'])]:null,['@type'=>'ListItem','position'=>$categoryRows?4:3,'name'=>$product['name'],'item'=>$productUrl]]))]];
        return $this->page('public/products/show', 'layouts/public', [
            'pageTitle' => ($product['seo_title'] ?? '') ?: $product['name'],
            'product' => $product,
            'variants' => $variantRows,
            'media' => $mediaRows,
            'categories' => $categoryRows,
            'relatedProducts' => $related->fetchAll(),
            'canChat' => (Auth::user()['type'] ?? null) === 'customer',
            'wholesaleApproved' => $wholesaleApproved,
            'cartMode' => (new CartService())->mode(),
            'shippingConfigured' => (new ShippingQuoteService())->configured(),
            'metaDescription' => ($product['seo_description'] ?? '') ?: ($product['short_description'] ?? '') ?: mb_substr(strip_tags((string) ($product['description'] ?? '')), 0, 155),
            'canonicalUrl' => $productUrl,
            'openGraphType' => 'product',
            'openGraphImage' => $imageUrls[0] ?? null,
            'structuredData' => $structuredData,
        ]);
    }

    public function shipping(string $slug): string
    {
        header('Content-Type: application/json; charset=utf-8');
        $postalCode = (string) ($_POST['postal_code'] ?? '');
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        $quantity = min(100, max(1, (int) ($_POST['quantity'] ?? 1)));
        $statement = Database::connection()->prepare("SELECT p.id product_id,p.name,p.store_id,p.seller_id,st.name store_name,COALESCE((SELECT sa.postal_code FROM store_addresses sa WHERE sa.store_id=COALESCE(st.shipping_source_store_id,st.id) AND sa.is_shipping_origin=1 ORDER BY sa.id LIMIT 1),(SELECT w.postal_code FROM warehouses w WHERE w.seller_id=p.seller_id AND w.status='active' ORDER BY w.id LIMIT 1)) origin_postal_code,v.id variant_id,COALESCE(v.promotional_price,v.price) unit_price,COALESCE(v.weight,p.weight,0.1) shipping_weight,COALESCE(v.width,p.width,11) shipping_width,COALESCE(v.height,p.height,2) shipping_height,COALESCE(v.length,p.length,16) shipping_length FROM products p JOIN product_variants v ON v.product_id=p.id JOIN stores st ON st.id=p.store_id JOIN sellers s ON s.id=p.seller_id WHERE p.slug=? AND v.id=? AND p.status='active' AND p.platform_paused=0 AND v.status='active' AND st.status='active' AND s.status='active' AND s.payment_enabled=1 AND s.payment_onboarding_status='active' AND s.pagarme_recipient_id IS NOT NULL LIMIT 1");
        $statement->execute([$slug, $variantId]);
        $item = $statement->fetch();
        if (!$item) { http_response_code(404); return $this->json(['ok' => false, 'message' => 'Produto ou variação indisponível.']); }
        $item['quantity'] = $quantity;
        $group = ['store_id' => (int) $item['store_id'], 'store_name' => $item['store_name'], 'origin_postal_code' => $item['origin_postal_code'], 'items' => [$item]];
        $quotes = (new ShippingQuoteService())->quotes(['groups' => [$group]], $postalCode, true);
        $store = $quotes['stores'][(int) $item['store_id']] ?? null;
        $options = is_array($store) ? ($store['options'] ?? []) : [];
        if (!$options) http_response_code(422);
        return $this->json(['ok' => (bool) $options, 'postal_code' => preg_replace('/\D+/', '', $postalCode), 'options' => $options, 'message' => $store['message'] ?? $quotes['message'] ?? null]);
    }

    /** @param array<string,mixed> $data */
    private function json(array $data): string
    {
        try { return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        catch (JsonException) { http_response_code(500); return '{"ok":false,"message":"Falha ao gerar resposta."}'; }
    }
}
