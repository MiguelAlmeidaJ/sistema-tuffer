<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Core\Database;
use App\Support\Str;
use App\Services\Sellers\SellerSalesEligibility;
use RuntimeException;
use Throwable;

final class ProductStoreTransferService
{
    public function move(int $productId, int $sourceStoreId, int $targetStoreId, int $sellerId): void
    {
        $this->assertSellerEnabled($sellerId);
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $this->targetStore($targetStoreId, $sellerId);
            $product = $this->sourceProduct($productId, $sourceStoreId, $sellerId, true);
            $history = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE product_id=?');
            $history->execute([$productId]);
            if ((int) $history->fetchColumn() > 0) throw new RuntimeException('Produto com pedidos não pode ser movido; duplique-o para preservar o histórico da loja original.');

            $pdo->prepare('DELETE ci FROM cart_items ci JOIN product_variants pv ON pv.id=ci.product_variant_id WHERE pv.product_id=?')->execute([$productId]);
            $pdo->prepare('UPDATE products SET store_id=? WHERE id=? AND store_id=? AND seller_id=?')->execute([$targetStoreId, $product['id'], $sourceStoreId, $sellerId]);
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public function duplicate(int $productId, int $sourceStoreId, int $targetStoreId, int $sellerId): int
    {
        $this->assertSellerEnabled($sellerId);
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $targetStore = $this->targetStore($targetStoreId, $sellerId);
            $source = $this->sourceProduct($productId, $sourceStoreId, $sellerId, true);
            $newProductId = $this->copyProduct($source, $targetStore);
            $this->copyCategoriesAndTags($productId, $newProductId);
            [$variantMap, $primarySku] = $this->copyVariantsAndStock($productId, $newProductId, $targetStoreId);
            if ($primarySku !== '') $pdo->prepare('UPDATE products SET sku=? WHERE id=?')->execute([$primarySku, $newProductId]);
            $this->copyProductRows($productId, $newProductId, $variantMap);
            $mediaMap = $this->copyMedia($productId, $newProductId, $variantMap);
            $this->copySeo($productId, $newProductId, $mediaMap);
            if ($ownsTransaction) $pdo->commit();
            return $newProductId;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function targetStore(int $storeId, int $sellerId): array
    {
        $statement = Database::connection()->prepare('SELECT id,slug,name FROM stores WHERE id=? AND seller_id=? AND status<>\'inactive\' LIMIT 1');
        $statement->execute([$storeId, $sellerId]);
        $store = $statement->fetch();
        if (!$store) throw new RuntimeException('Loja de destino inválida.');
        return $store;
    }

    private function assertSellerEnabled(int $sellerId): void
    {
        if (!(new SellerSalesEligibility())->sellerCanSell($sellerId)) {
            throw new RuntimeException('Conclua sua configuração de recebimento antes de transferir produtos.');
        }
    }

    /** @return array<string,mixed> */
    private function sourceProduct(int $productId, int $storeId, int $sellerId, bool $lock): array
    {
        $statement = Database::connection()->prepare('SELECT * FROM products WHERE id=? AND store_id=? AND seller_id=? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$productId, $storeId, $sellerId]);
        $product = $statement->fetch();
        if (!$product) throw new RuntimeException('Produto não encontrado na loja atual.');
        return $product;
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $targetStore */
    private function copyProduct(array $source, array $targetStore): int
    {
        $pdo = Database::connection();
        $columns = [
            'seller_id','store_id','brand_id','primary_category_id','name','slug','sku','description','short_description','product_type','status','featured',
            'retail_enabled','wholesale_enabled','wholesale_min_quantity','maximum_order_quantity','allow_variant_mix','allow_backorder','stock_control',
            'weight','width','height','length','package_count','original_packaging','combine_shipping','scheduled_at',
        ];
        $values = [];
        foreach ($columns as $column) $values[$column] = $source[$column] ?? null;
        $values['store_id'] = (int) $targetStore['id'];
        $values['slug'] = $this->uniqueSlug((string) $source['slug'] . '-' . (string) $targetStore['slug']);
        $values['sku'] = $this->uniqueSku((string) ($source['sku'] ?: 'PROD-' . $source['id']), (int) $targetStore['id']);
        $values['status'] = 'draft';
        $values['featured'] = 0;
        $values['scheduled_at'] = null;

        $statement = $pdo->prepare('INSERT INTO products (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $statement->execute(array_values($values));
        return (int) $pdo->lastInsertId();
    }

    private function copyCategoriesAndTags(int $sourceId, int $targetId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO product_categories(product_id,category_id) SELECT ?,category_id FROM product_categories WHERE product_id=?')->execute([$targetId, $sourceId]);
        $pdo->prepare('INSERT INTO product_tags(product_id,tag_id) SELECT ?,tag_id FROM product_tags WHERE product_id=?')->execute([$targetId, $sourceId]);
    }

    /** @return array{0:array<int,int>,1:string} */
    private function copyVariantsAndStock(int $sourceId, int $targetId, int $targetStoreId): array
    {
        $pdo = Database::connection();
        $variants = $pdo->prepare('SELECT * FROM product_variants WHERE product_id=? ORDER BY id');
        $variants->execute([$sourceId]);
        $variantMap = [];
        $primarySku = '';
        $insert = $pdo->prepare('INSERT INTO product_variants(product_id,name,sku,barcode,price,promotional_price,wholesale_price,cost_price,weight,width,height,length,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($variants->fetchAll() as $variant) {
            $sku = $this->uniqueSku((string) $variant['sku'], $targetStoreId);
            $insert->execute([$targetId,$variant['name'],$sku,$variant['barcode'],$variant['price'],$variant['promotional_price'],$variant['wholesale_price'],$variant['cost_price'],$variant['weight'],$variant['width'],$variant['height'],$variant['length'],$variant['status']]);
            $newVariantId = (int) $pdo->lastInsertId();
            $variantMap[(int) $variant['id']] = $newVariantId;
            if ($primarySku === '') $primarySku = $sku;

            $stocks = $pdo->prepare('SELECT warehouse_id,quantity,reserved_quantity,minimum_quantity FROM stocks WHERE product_variant_id=?');
            $stocks->execute([$variant['id']]);
            $insertStock = $pdo->prepare('INSERT INTO stocks(warehouse_id,product_variant_id,quantity,reserved_quantity,minimum_quantity) VALUES(?,?,?,0,?)');
            foreach ($stocks->fetchAll() as $stock) $insertStock->execute([$stock['warehouse_id'],$newVariantId,max(0,(int)$stock['quantity']-(int)$stock['reserved_quantity']),$stock['minimum_quantity']]);

            $channel = $pdo->prepare('SELECT retail_quantity,wholesale_quantity FROM product_inventory_channels WHERE variant_id=?');
            $channel->execute([$variant['id']]);
            if ($quantities = $channel->fetch()) $pdo->prepare('INSERT INTO product_inventory_channels(variant_id,retail_quantity,wholesale_quantity) VALUES(?,?,?)')->execute([$newVariantId,$quantities['retail_quantity'],$quantities['wholesale_quantity']]);
        }
        return [$variantMap, $primarySku];
    }

    /** @param array<int,int> $variantMap */
    private function copyProductRows(int $sourceId, int $targetId, array $variantMap): void
    {
        $pdo = Database::connection();
        $specifications = $pdo->prepare('SELECT name,value,sort_order FROM product_specifications WHERE product_id=? ORDER BY sort_order,id');
        $specifications->execute([$sourceId]);
        $insertSpecification = $pdo->prepare('INSERT INTO product_specifications(product_id,name,value,sort_order) VALUES(?,?,?,?)');
        foreach ($specifications->fetchAll() as $row) $insertSpecification->execute([$targetId,$row['name'],$row['value'],$row['sort_order']]);

        $shippingRules = $pdo->prepare('SELECT minimum_quantity,maximum_quantity,weight,width,height,length FROM product_shipping_rules WHERE product_id=? ORDER BY id');
        $shippingRules->execute([$sourceId]);
        $insertShipping = $pdo->prepare('INSERT INTO product_shipping_rules(product_id,minimum_quantity,maximum_quantity,weight,width,height,length) VALUES(?,?,?,?,?,?,?)');
        foreach ($shippingRules->fetchAll() as $row) $insertShipping->execute([$targetId,$row['minimum_quantity'],$row['maximum_quantity'],$row['weight'],$row['width'],$row['height'],$row['length']]);

        $tiers = $pdo->prepare('SELECT variant_id,minimum_quantity,maximum_quantity,unit_price FROM product_wholesale_tiers WHERE product_id=? ORDER BY id');
        $tiers->execute([$sourceId]);
        $insertTier = $pdo->prepare('INSERT INTO product_wholesale_tiers(product_id,variant_id,minimum_quantity,maximum_quantity,unit_price) VALUES(?,?,?,?,?)');
        foreach ($tiers->fetchAll() as $row) $insertTier->execute([$targetId,$row['variant_id'] ? ($variantMap[(int)$row['variant_id']] ?? null) : null,$row['minimum_quantity'],$row['maximum_quantity'],$row['unit_price']]);
    }

    /** @param array<int,int> $variantMap @return array<int,int> */
    private function copyMedia(int $sourceId, int $targetId, array $variantMap): array
    {
        $pdo = Database::connection();
        $media = $pdo->prepare('SELECT * FROM product_media WHERE product_id=? ORDER BY resource_type,sort_order,id');
        $media->execute([$sourceId]);
        $insert = $pdo->prepare('INSERT INTO product_media(product_id,variant_id,public_id,resource_type,url,secure_url,thumbnail_url,format,width,height,bytes,duration,sort_order,is_cover) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $mediaMap = [];
        foreach ($media->fetchAll() as $item) {
            $insert->execute([$targetId,$item['variant_id'] ? ($variantMap[(int)$item['variant_id']] ?? null) : null,$item['public_id'],$item['resource_type'],$item['url'],$item['secure_url'],$item['thumbnail_url'],$item['format'],$item['width'],$item['height'],$item['bytes'],$item['duration'],$item['sort_order'],$item['is_cover']]);
            $mediaMap[(int) $item['id']] = (int) $pdo->lastInsertId();
        }
        return $mediaMap;
    }

    /** @param array<int,int> $mediaMap */
    private function copySeo(int $sourceId, int $targetId, array $mediaMap): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT title,description,keywords,share_media_id FROM product_seo WHERE product_id=?');
        $statement->execute([$sourceId]);
        if ($seo = $statement->fetch()) $pdo->prepare('INSERT INTO product_seo(product_id,title,description,keywords,share_media_id) VALUES(?,?,?,?,?)')->execute([$targetId,$seo['title'],$seo['description'],$seo['keywords'],$seo['share_media_id'] ? ($mediaMap[(int)$seo['share_media_id']] ?? null) : null]);
    }

    private function uniqueSlug(string $base): string
    {
        $pdo = Database::connection();
        $base = mb_substr(Str::slug($base), 0, 190);
        $candidate = $base;
        $suffix = 2;
        $exists = $pdo->prepare('SELECT COUNT(*) FROM products WHERE slug=?');
        while (true) {
            $exists->execute([$candidate]);
            if ((int) $exists->fetchColumn() === 0) return $candidate;
            $candidate = mb_substr($base, 0, 180) . '-' . $suffix++;
        }
    }

    private function uniqueSku(string $base, int $targetStoreId): string
    {
        $pdo = Database::connection();
        $base = trim($base) !== '' ? trim($base) : 'PRODUTO';
        $base = mb_substr($base, 0, 82) . '-L' . $targetStoreId;
        $candidate = $base;
        $suffix = 2;
        $exists = $pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE sku=?');
        while (true) {
            $exists->execute([$candidate]);
            if ((int) $exists->fetchColumn() === 0) return $candidate;
            $candidate = mb_substr($base, 0, 92) . '-' . $suffix++;
        }
    }
}
