<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Media\ProductMediaRepository;
use App\Services\Products\ProductStoreTransferService;
use App\Services\Stores\SellerStoreContext;
use App\Services\Sellers\SellerSalesEligibility;
use App\Services\Finance\ProductCostService;
use App\Core\Auth;
use App\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

final class ProductController extends Controller
{
    public function index(): string
    {
        $storeContext = new SellerStoreContext();
        $store = $storeContext->current();
        $pdo = Database::connection();
        $allowedPageSizes = [15, 25, 50];
        $perPage = (int) ($_GET['per_page'] ?? 15);
        if (!in_array($perPage, $allowedPageSizes, true)) $perPage = 15;

        $count = $pdo->prepare('SELECT COUNT(*) FROM products WHERE store_id=?');
        $count->execute([$store['id']]);
        $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $lastPage);
        $offset = ($page - 1) * $perPage;

        $statement = $pdo->prepare("SELECT p.id,p.name,p.sku,p.product_type,p.status,p.moderation_status,p.platform_paused,p.moderation_reason,p.updated_at,MIN(pv.id) variant_id,MIN(COALESCE(pv.promotional_price,pv.price)) price,COALESCE(SUM(sk.quantity-sk.reserved_quantity),0) stock,(SELECT pm.secure_url FROM product_media pm WHERE pm.product_id=p.id AND pm.resource_type='image' ORDER BY pm.is_cover DESC,pm.sort_order,pm.id LIMIT 1) image_url FROM products p LEFT JOIN product_variants pv ON pv.product_id=p.id LEFT JOIN stocks sk ON sk.product_variant_id=pv.id WHERE p.store_id=:store_id GROUP BY p.id ORDER BY p.updated_at DESC LIMIT :limit OFFSET :offset");
        $statement->bindValue(':store_id', (int) $store['id'], PDO::PARAM_INT);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $this->page('seller/products/index', 'layouts/seller', [
            'pageTitle' => 'Produtos',
            'products' => $statement->fetchAll(),
            'currentStore' => $store,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => $lastPage,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $total),
            ],
            'allowedPageSizes' => $allowedPageSizes,
            'transferStores' => array_values(array_filter($storeContext->stores(), static fn(array $candidate): bool => (int) $candidate['id'] !== (int) $store['id'])),
            'paymentEnabled' => (new SellerSalesEligibility())->sellerCanSell((int) $store['seller_id']),
        ]);
    }

    public function create(): string { return $this->form(); }

    public function store(): string
    {
        $store = (new SellerStoreContext())->current();
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $productId = $this->insertProduct($store);
            $this->syncProductRelations($productId, (int) $store['seller_id']);
            $pdo->commit();
            Session::flash('success', $this->requestedStatus() === 'active' ? 'Produto criado e publicado.' : 'Produto criado como rascunho.');
            return Response::redirect('/vendedor/produtos/' . $productId . '/editar');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            Session::flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível criar o produto. Revise nome, SKU, preços e slug.');
            return Response::redirect('/vendedor/produtos/novo');
        }
    }

    public function edit(string $id): string
    {
        $store = (new SellerStoreContext())->current();
        $statement = Database::connection()->prepare('SELECT * FROM products WHERE id=? AND store_id=? LIMIT 1');
        $statement->execute([(int) $id, $store['id']]);
        return $this->form($statement->fetch() ?: null);
    }

    public function update(string $id): string
    {
        $store = (new SellerStoreContext())->current();
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare('SELECT id FROM products WHERE id=? AND store_id=? FOR UPDATE');
            $check->execute([(int) $id, $store['id']]);
            if (!$check->fetchColumn()) throw new RuntimeException('Produto não encontrado.');
            $this->updateProduct((int) $id);
            $this->syncProductRelations((int) $id, (int) $store['seller_id']);
            $pdo->commit();
            Session::flash('success', $this->requestedStatus() === 'active' ? 'Produto salvo e publicado.' : 'Produto atualizado.');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            Session::flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível atualizar. Revise os campos e tente novamente.');
        }
        return Response::redirect('/vendedor/produtos/' . (int) $id . '/editar');
    }

    public function adjustStock(string $id): string
    {
        $store = (new SellerStoreContext())->current();
        $statement = Database::connection()->prepare('UPDATE stocks sk JOIN product_variants pv ON pv.id=sk.product_variant_id JOIN products p ON p.id=pv.product_id SET sk.quantity=? WHERE pv.id=? AND p.id=? AND p.store_id=?');
        $statement->execute([max(0, (int) $_POST['quantity']), (int) $_POST['variant_id'], (int) $id, $store['id']]);
        Session::flash('success', 'Estoque ajustado.');
        return Response::redirect('/vendedor/produtos');
    }

    public function destroy(string $id): string
    {
        $store = (new SellerStoreContext())->current();
        try {
            $result = $this->removeProduct((int) $id, (int) $store['id']);
            if ($result === 'archived') Session::flash('error', 'Produto com histórico foi arquivado em vez de excluído.');
            elseif ($result === 'deleted') Session::flash('success', 'Produto excluído.');
            else Session::flash('error', 'Produto não encontrado nesta loja.');
        } catch (Throwable) {
            Session::flash('error', 'Não foi possível excluir o produto.');
        }
        return Response::redirect('/vendedor/produtos');
    }

    public function bulkDestroy(): string
    {
        $store = (new SellerStoreContext())->current();
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($_POST['product_ids'] ?? null) ? $_POST['product_ids'] : []), static fn(int $id): bool => $id > 0))), 0, 500);
        if (!$ids) {
            Session::flash('error', 'Selecione ao menos um produto.');
            return Response::redirect('/vendedor/produtos');
        }
        $summary = ['deleted' => 0, 'archived' => 0, 'missing' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            try { $summary[$this->removeProduct($id, (int) $store['id'])]++; }
            catch (Throwable) { $summary['failed']++; }
        }
        if ($summary['deleted'] || $summary['archived']) Session::flash('success', $summary['deleted'] . ' produto(s) excluído(s) e ' . $summary['archived'] . ' arquivado(s) por possuírem histórico.');
        if ($summary['missing'] || $summary['failed']) Session::flash('error', ($summary['missing'] + $summary['failed']) . ' produto(s) não puderam ser processados.');
        return Response::redirect('/vendedor/produtos');
    }

    public function bulkStatus(): string
    {
        $store = (new SellerStoreContext())->current();
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($_POST['product_ids'] ?? null) ? $_POST['product_ids'] : []), static fn(int $id): bool => $id > 0))), 0, 500);
        $status = (string) ($_POST['status'] ?? '');
        $allowedStatuses = ['draft', 'active', 'paused', 'archived'];
        if (!$ids || !in_array($status, $allowedStatuses, true)) {
            Session::flash('error', 'Selecione produtos e um status válido.');
            return Response::redirect('/vendedor/produtos');
        }
        if ($status === 'active' && !(new SellerSalesEligibility())->sellerCanSell((int) $store['seller_id'])) {
            Session::flash('error', 'Conclua sua configuração de recebimento antes de publicar produtos.');
            return Response::redirect('/vendedor/configuracoes/recebimentos');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = Database::connection()->prepare("UPDATE products SET status=? WHERE store_id=? AND id IN ({$placeholders})");
        $statement->execute([$status, (int) $store['id'], ...$ids]);
        $labels = ['draft' => 'rascunho', 'active' => 'publicado', 'paused' => 'pausado', 'archived' => 'arquivado'];
        Session::flash('success', $statement->rowCount() . ' produto(s) alterado(s) para ' . $labels[$status] . '.');
        return Response::redirect('/vendedor/produtos');
    }

    public function bulkTransfer(): string
    {
        $storeContext = new SellerStoreContext();
        $store = $storeContext->current();
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($_POST['product_ids'] ?? null) ? $_POST['product_ids'] : []), static fn(int $id): bool => $id > 0))), 0, 50);
        $targetStoreId = (int) ($_POST['target_store_id'] ?? 0);
        $action = (string) ($_POST['transfer_action'] ?? '');
        $validTarget = false;
        foreach ($storeContext->stores() as $sellerStore) if ((int) $sellerStore['id'] === $targetStoreId && $targetStoreId !== (int) $store['id']) $validTarget = true;
        if (!$ids || !$validTarget || !in_array($action, ['duplicate', 'move'], true)) {
            Session::flash('error', 'Selecione produtos, uma loja de destino e uma ação válida.');
            return Response::redirect('/vendedor/produtos');
        }

        $service = new ProductStoreTransferService();
        $success = 0;
        $errors = [];
        foreach ($ids as $productId) {
            try {
                if ($action === 'duplicate') $service->duplicate($productId, (int) $store['id'], $targetStoreId, (int) $store['seller_id']);
                else $service->move($productId, (int) $store['id'], $targetStoreId, (int) $store['seller_id']);
                $success++;
            } catch (Throwable $exception) {
                if (count($errors) < 3) $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Falha inesperada ao processar um produto.';
            }
        }
        $verb = $action === 'duplicate' ? 'duplicado(s)' : 'movido(s)';
        if ($success > 0) Session::flash('success', $success . ' produto(s) ' . $verb . ' para a loja de destino.');
        if ($errors) Session::flash('error', (count($ids) - $success) . ' produto(s) não foram processados. ' . implode(' ', array_unique($errors)));
        return Response::redirect('/vendedor/produtos');
    }

    private function removeProduct(int $productId, int $storeId): string
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $product = $pdo->prepare('SELECT id FROM products WHERE id=? AND store_id=? FOR UPDATE');
            $product->execute([$productId, $storeId]);
            if (!$product->fetchColumn()) { $pdo->rollBack(); return 'missing'; }
            $history = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE product_id=?');
            $history->execute([$productId]);
            if ((int) $history->fetchColumn() > 0) {
                $pdo->prepare("UPDATE products SET status='archived' WHERE id=? AND store_id=?")->execute([$productId, $storeId]);
                $pdo->commit();
                return 'archived';
            }
            $pdo->prepare('DELETE ci FROM cart_items ci JOIN product_variants pv ON pv.id=ci.product_variant_id WHERE pv.product_id=?')->execute([$productId]);
            $pdo->prepare('DELETE sm FROM stock_movements sm JOIN stocks sk ON sk.id=sm.stock_id JOIN product_variants pv ON pv.id=sk.product_variant_id WHERE pv.product_id=?')->execute([$productId]);
            $pdo->prepare('DELETE sk FROM stocks sk JOIN product_variants pv ON pv.id=sk.product_variant_id WHERE pv.product_id=?')->execute([$productId]);
            $pdo->prepare('DELETE FROM products WHERE id=? AND store_id=?')->execute([$productId, $storeId]);
            $pdo->commit();
            return 'deleted';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    /** @param array<string,mixed> $store */
    private function insertProduct(array $store): int
    {
        $data = $this->productData();
        $statement = Database::connection()->prepare('INSERT INTO products (seller_id,store_id,brand_id,primary_category_id,name,slug,sku,description,short_description,product_type,status,featured,retail_enabled,wholesale_enabled,wholesale_min_quantity,maximum_order_quantity,allow_variant_mix,allow_backorder,stock_control,weight,width,height,length,package_count,original_packaging,combine_shipping,scheduled_at) VALUES (:seller_id,:store_id,:brand_id,:primary_category_id,:name,:slug,:sku,:description,:short_description,:product_type,:status,:featured,:retail_enabled,:wholesale_enabled,:wholesale_min_quantity,:maximum_order_quantity,:allow_variant_mix,:allow_backorder,:stock_control,:weight,:width,:height,:length,:package_count,:original_packaging,:combine_shipping,:scheduled_at)');
        $statement->execute(array_merge($data, ['seller_id' => $store['seller_id'], 'store_id' => $store['id']]));
        return (int) Database::connection()->lastInsertId();
    }

    private function updateProduct(int $productId): void
    {
        $data = $this->productData($productId);
        $data['id'] = $productId;
        unset($data['featured']);
        Database::connection()->prepare("UPDATE products SET brand_id=:brand_id,primary_category_id=:primary_category_id,name=:name,slug=:slug,sku=:sku,description=:description,short_description=:short_description,product_type=:product_type,status=:status,retail_enabled=:retail_enabled,wholesale_enabled=:wholesale_enabled,wholesale_min_quantity=:wholesale_min_quantity,maximum_order_quantity=:maximum_order_quantity,allow_variant_mix=:allow_variant_mix,allow_backorder=:allow_backorder,stock_control=:stock_control,weight=:weight,width=:width,height=:height,length=:length,package_count=:package_count,original_packaging=:original_packaging,combine_shipping=:combine_shipping,scheduled_at=:scheduled_at,moderation_status=IF(moderation_status IN ('changes_requested','rejected'),'pending',moderation_status) WHERE id=:id")->execute($data);
    }

    /** @return array<string,mixed> */
    private function productData(?int $productId = null): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $sku = trim((string) ($_POST['sku'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) throw new RuntimeException('Dados básicos inválidos.');
        if ($sku === '' && $productId) {
            $statement = Database::connection()->prepare('SELECT sku FROM products WHERE id=? LIMIT 1');
            $statement->execute([$productId]);
            $sku = trim((string) $statement->fetchColumn());
        }
        if ($sku === '') $sku = $this->generateSku($name);
        $_POST['sku'] = $sku;
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $scheduled = trim((string) ($_POST['scheduled_at'] ?? ''));
        $scheduledTimestamp = $scheduled !== '' ? strtotime($scheduled) : false;
        if (!isset($_POST['retail_enabled']) && !isset($_POST['wholesale_enabled'])) throw new RuntimeException('Selecione uma modalidade de venda.');
        $primaryCategoryId = (int) ($_POST['primary_category_id'] ?? 0);
        if (!$this->isSelectableCategory($primaryCategoryId)) {
            throw new RuntimeException('Selecione uma categoria principal final e ativa.');
        }
        $status = $this->requestedStatus();
        if ($status === 'active') {
            $store = (new SellerStoreContext())->current();
            if (!$store || !(new SellerSalesEligibility())->sellerCanSell((int) $store['seller_id'])) {
                throw new RuntimeException('Conclua sua configuração de recebimento antes de publicar produtos.');
            }
        }
        return [
            'brand_id' => (int) ($_POST['brand_id'] ?? 0) ?: null,
            'primary_category_id' => $primaryCategoryId,
            'name' => $name,
            'slug' => Str::slug($slugInput !== '' ? $slugInput : $name),
            'sku' => $sku,
            'description' => rich_text_html($_POST['description'] ?? ''),
            'short_description' => trim((string) ($_POST['short_description'] ?? '')),
            'product_type' => in_array($_POST['product_type'] ?? '', ['simple', 'variable', 'kit'], true) ? $_POST['product_type'] : 'simple',
            'status' => $status,
            'featured' => isset($_POST['featured']) ? 1 : 0,
            'retail_enabled' => isset($_POST['retail_enabled']) ? 1 : 0,
            'wholesale_enabled' => isset($_POST['wholesale_enabled']) ? 1 : 0,
            'wholesale_min_quantity' => isset($_POST['wholesale_enabled']) ? $this->nullableInt('wholesale_min_quantity') : null,
            'maximum_order_quantity' => $this->nullableInt('maximum_order_quantity'),
            'allow_variant_mix' => isset($_POST['wholesale_enabled'], $_POST['allow_variant_mix']) ? 1 : 0,
            'allow_backorder' => isset($_POST['allow_backorder']) ? 1 : 0,
            'stock_control' => ($_POST['stock_control'] ?? 'shared') === 'separate' ? 'separate' : 'shared',
            'weight' => max(0, (float) ($_POST['weight'] ?? 0)),
            'width' => max(0, (float) ($_POST['width'] ?? 0)),
            'height' => max(0, (float) ($_POST['height'] ?? 0)),
            'length' => max(0, (float) ($_POST['length'] ?? 0)),
            'package_count' => max(1, (int) ($_POST['package_count'] ?? 1)),
            'original_packaging' => isset($_POST['original_packaging']) ? 1 : 0,
            'combine_shipping' => isset($_POST['combine_shipping']) ? 1 : 0,
            'scheduled_at' => $scheduledTimestamp !== false ? date('Y-m-d H:i:s', $scheduledTimestamp) : null,
        ];
    }

    private function requestedStatus(): string
    {
        $action = (string) ($_POST['save_action'] ?? 'save');
        if ($action === 'draft') return 'draft';
        if ($action === 'publish') return 'active';
        return in_array($_POST['status'] ?? '', ['draft', 'active', 'paused', 'archived'], true) ? (string) $_POST['status'] : 'draft';
    }

    private function syncProductRelations(int $productId, int $sellerId): void
    {
        $this->syncCategories($productId);
        $this->syncTags($productId);
        $this->syncVariants($productId, $sellerId);
        if (!isset($_POST['wholesale_enabled'])) $_POST['wholesale_tiers_json'] = '[]';
        $this->syncJsonRows('product_wholesale_tiers', $productId, 'wholesale_tiers_json', ['minimum_quantity', 'maximum_quantity', 'unit_price']);
        $this->syncJsonRows('product_specifications', $productId, 'specifications_json', ['name', 'value', 'sort_order']);
        $this->syncJsonRows('product_shipping_rules', $productId, 'shipping_rules_json', ['minimum_quantity', 'maximum_quantity', 'weight', 'width', 'height', 'length']);
        $this->syncSeo($productId);
        $this->syncMedia($productId);
    }

    private function syncVariants(int $productId, int $sellerId): void
    {
        $pdo = Database::connection();
        $wholesaleEnabled = isset($_POST['wholesale_enabled']);
        $variants = $this->jsonArray('variants_json');
        if (($_POST['product_type'] ?? 'simple') !== 'variable' || $variants === []) {
            $basePrice = (float) ($_POST['price'] ?? 0);
            if ($basePrice <= 0 && $wholesaleEnabled) $basePrice = (float) ($_POST['wholesale_price'] ?? 0);
            $variants = [[
                'id' => (int) ($_POST['variant_id'] ?? 0), 'name' => 'Padrão', 'sku' => trim((string) $_POST['sku']),
                'barcode' => trim((string) ($_POST['barcode'] ?? '')), 'price' => $basePrice,
                'promotional_price' => $_POST['promotional_price'] ?? null, 'cost_price' => $_POST['cost_price'] ?? null,
                'wholesale_price' => $_POST['wholesale_price'] ?? null, 'stock' => $_POST['stock'] ?? 0,
                'minimum_quantity' => $_POST['minimum_quantity'] ?? 0, 'retail_stock' => $_POST['retail_stock'] ?? 0,
                'wholesale_stock' => $_POST['wholesale_stock'] ?? 0, 'status' => 'active',
            ]];
        }
        $existing = $pdo->prepare('SELECT id FROM product_variants WHERE product_id=?');
        $existing->execute([$productId]);
        $existingIds = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));
        $kept = [];
        $warehouseId = $this->warehouse($sellerId);
        foreach ($variants as $index => $variant) {
            if (!is_array($variant)) continue;
            $sku = trim((string) ($variant['sku'] ?? ''));
            $price = (float) ($variant['price'] ?? 0);
            if ($price <= 0 && $wholesaleEnabled) $price = (float) ($variant['wholesale_price'] ?? 0);
            if ($sku === '' || $price <= 0) throw new RuntimeException('Variação inválida.');
            $values = [
                trim((string) ($variant['name'] ?? ('Variação ' . ($index + 1)))), $sku,
                trim((string) ($variant['barcode'] ?? '')) ?: null, $price,
                $this->nullableNumberValue($variant['promotional_price'] ?? null), $wholesaleEnabled ? $this->nullableNumberValue($variant['wholesale_price'] ?? null) : null,
                $this->nullableNumberValue($variant['cost_price'] ?? null), ($variant['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ];
            $variantId = (int) ($variant['id'] ?? 0);
            if ($variantId > 0 && in_array($variantId, $existingIds, true)) {
                $pdo->prepare('UPDATE product_variants SET name=?,sku=?,barcode=?,price=?,promotional_price=?,wholesale_price=?,cost_price=?,status=? WHERE id=? AND product_id=?')->execute([...$values, $variantId, $productId]);
            } else {
                $pdo->prepare('INSERT INTO product_variants(product_id,name,sku,barcode,price,promotional_price,wholesale_price,cost_price,status) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$productId, ...$values]);
                $variantId = (int) $pdo->lastInsertId();
            }
            $kept[] = $variantId;
            $costValue = $values[6];
            if ($costValue !== null) {
                (new ProductCostService($pdo))->record(
                    $variantId,
                    (int) round((float) $costValue * 100),
                    Auth::id()
                );
            }
            $stock = max(0, (int) ($variant['stock'] ?? 0));
            $minimum = max(0, (int) ($variant['minimum_quantity'] ?? 0));
            $pdo->prepare('INSERT INTO stocks(warehouse_id,product_variant_id,quantity,reserved_quantity,minimum_quantity) VALUES(?,?,?,0,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),minimum_quantity=VALUES(minimum_quantity)')->execute([$warehouseId, $variantId, $stock, $minimum]);
            $pdo->prepare('INSERT INTO product_inventory_channels(variant_id,retail_quantity,wholesale_quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE retail_quantity=VALUES(retail_quantity),wholesale_quantity=VALUES(wholesale_quantity)')->execute([$variantId, max(0, (int) ($variant['retail_stock'] ?? 0)), $wholesaleEnabled ? max(0, (int) ($variant['wholesale_stock'] ?? 0)) : 0]);
        }
        $inactive = array_diff($existingIds, $kept);
        if ($inactive) $pdo->exec('UPDATE product_variants SET status=\'inactive\' WHERE product_id=' . $productId . ' AND id IN (' . implode(',', $inactive) . ')');
    }

    private function syncCategories(int $productId): void
    {
        $pdo = Database::connection();
        $primary = (int) ($_POST['primary_category_id'] ?? 0);
        $additional = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['categories'] ?? [])), static fn(int $id): bool => $id > 0 && $id !== $primary))), 0, 3);
        $ids = array_filter([$primary, ...$additional]);
        $pdo->prepare('DELETE FROM product_categories WHERE product_id=?')->execute([$productId]);
        $statement = $pdo->prepare("INSERT INTO product_categories(product_id,category_id) SELECT ?,c.id FROM categories c WHERE c.id=? AND c.status='active' AND c.allow_products=1 AND NOT EXISTS (SELECT 1 FROM categories child WHERE child.parent_id=c.id AND child.status='active')");
        foreach ($ids as $categoryId) $statement->execute([$productId, $categoryId]);
    }

    private function isSelectableCategory(int $categoryId): bool
    {
        if ($categoryId <= 0) return false;
        $statement = Database::connection()->prepare("SELECT 1 FROM categories c WHERE c.id=? AND c.status='active' AND c.allow_products=1 AND NOT EXISTS (SELECT 1 FROM categories child WHERE child.parent_id=c.id AND child.status='active') LIMIT 1");
        $statement->execute([$categoryId]);
        return (bool) $statement->fetchColumn();
    }

    private function syncTags(int $productId): void
    {
        $pdo = Database::connection();
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['tags'] ?? [])), static fn(int $id): bool => $id > 0))), 0, 10);
        $pdo->prepare('DELETE FROM product_tags WHERE product_id=?')->execute([$productId]);
        if (!$ids) return;
        $statement = $pdo->prepare("INSERT INTO product_tags(product_id,tag_id) SELECT ?,id FROM tags WHERE id=? AND status='active' AND is_admin_only=0");
        foreach ($ids as $tagId) $statement->execute([$productId, $tagId]);
    }

    /** @param array<int,string> $columns */
    private function syncJsonRows(string $table, int $productId, string $field, array $columns): void
    {
        $pdo = Database::connection();
        $pdo->prepare("DELETE FROM {$table} WHERE product_id=?")->execute([$productId]);
        $rows = $this->jsonArray($field);
        if (!$rows) return;
        $names = implode(',', ['product_id', ...$columns]);
        $marks = implode(',', array_fill(0, count($columns) + 1, '?'));
        $statement = $pdo->prepare("INSERT INTO {$table} ({$names}) VALUES ({$marks})");
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $values = [$productId];
            foreach ($columns as $column) $values[] = ($row[$column] ?? '') === '' ? null : $row[$column];
            if (($row[$columns[0]] ?? '') === '') continue;
            $statement->execute($values);
        }
    }

    private function syncSeo(int $productId): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $title = trim((string) ($_POST['seo_title'] ?? '')) ?: $name;
        $description = trim((string) ($_POST['seo_description'] ?? ''));
        if ($description === '') $description = trim((string) ($_POST['short_description'] ?? ''));
        if ($description === '') $description = trim(strip_tags((string) ($_POST['description'] ?? '')));
        $keywords = trim((string) ($_POST['seo_keywords'] ?? '')) ?: $this->generatedSeoKeywords($name);
        Database::connection()->prepare('INSERT INTO product_seo(product_id,title,description,keywords) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),keywords=VALUES(keywords)')->execute([
            $productId, mb_substr($title, 0, 190), mb_substr($description, 0, 320), mb_substr($keywords, 0, 500),
        ]);
    }

    private function syncMedia(int $productId): void
    {
        $pdo = Database::connection();
        $deleteIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['delete_media'] ?? [])), static fn(int $id): bool => $id > 0)));
        if ($deleteIds) {
            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $pdo->prepare("DELETE FROM product_media WHERE product_id=? AND id IN ({$placeholders})")->execute([$productId, ...$deleteIds]);
        }
        $mediaOrder = array_values(array_unique(array_filter(array_map('strval', $this->jsonArray('media_order')), static fn(string $key): bool => preg_match('/^(existing:\d+|new:[a-zA-Z0-9_-]+)$/', $key) === 1)));
        $orderPositions = array_flip($mediaOrder);
        $coverKey = trim((string) ($_POST['media_cover'] ?? ''));
        $payload = $this->jsonArray('media_payload');
        $existingVideo = $pdo->prepare("SELECT COUNT(*) FROM product_media WHERE product_id=? AND resource_type='video'");
        $existingVideo->execute([$productId]);
        $hasVideo = (int) $existingVideo->fetchColumn() > 0;
        $imageCount = (int) $pdo->query("SELECT COUNT(*) FROM product_media WHERE product_id={$productId} AND resource_type='image'")->fetchColumn();
        $repository = new ProductMediaRepository();
        $newImageIds = [];
        foreach ($payload as $media) {
            if (!is_array($media)) continue;
            if (($media['resource_type'] ?? '') === 'video') {
                if ($hasVideo) continue;
                $duration = (float) ($media['duration'] ?? 0);
                if ($duration < 8 || $duration > 80 || (int) ($media['bytes'] ?? 0) > 100 * 1024 * 1024) throw new RuntimeException('O vídeo deve ter entre 8 segundos e 1 minuto e 20 segundos, com até 100 MB.');
                $hasVideo = true;
                $media['sort_order'] = 0;
            } else {
                if ($imageCount >= 10) continue;
                if ((int) ($media['width'] ?? 0) < 1080 || (int) ($media['height'] ?? 0) < 1080) throw new RuntimeException('Imagem com resolução insuficiente.');
                $imageCount++;
                $clientKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($media['client_key'] ?? ''));
                if ($clientKey === '') $clientKey = 'upload-' . $imageCount;
                $orderKey = 'new:' . $clientKey;
                $media['sort_order'] = isset($orderPositions[$orderKey]) ? (int) $orderPositions[$orderKey] : $imageCount + count($mediaOrder);
                $media['is_cover'] = false;
            }
            $createdId = $repository->create($productId, null, $media);
            if (($media['resource_type'] ?? '') === 'image') $newImageIds[$orderKey] = $createdId;
        }

        $images = $pdo->prepare("SELECT id FROM product_media WHERE product_id=? AND resource_type='image' ORDER BY sort_order,id");
        $images->execute([$productId]);
        $availableIds = array_map('intval', $images->fetchAll(PDO::FETCH_COLUMN));
        $availableMap = array_fill_keys($availableIds, true);
        $orderedIds = [];
        foreach ($mediaOrder as $key) {
            $mediaId = 0;
            if (preg_match('/^existing:(\d+)$/', $key, $match)) $mediaId = (int) $match[1];
            elseif (isset($newImageIds[$key])) $mediaId = $newImageIds[$key];
            if ($mediaId > 0 && isset($availableMap[$mediaId]) && !in_array($mediaId, $orderedIds, true)) $orderedIds[] = $mediaId;
        }
        foreach ($availableIds as $mediaId) if (!in_array($mediaId, $orderedIds, true)) $orderedIds[] = $mediaId;
        $updateOrder = $pdo->prepare('UPDATE product_media SET sort_order=? WHERE id=? AND product_id=?');
        foreach ($orderedIds as $sortOrder => $mediaId) $updateOrder->execute([$sortOrder, $mediaId, $productId]);

        $coverId = 0;
        if (preg_match('/^existing:(\d+)$/', $coverKey, $match) && isset($availableMap[(int) $match[1]])) $coverId = (int) $match[1];
        elseif (isset($newImageIds[$coverKey])) $coverId = $newImageIds[$coverKey];
        if ($coverId > 0) {
            $pdo->prepare("UPDATE product_media SET is_cover=0 WHERE product_id=? AND resource_type='image'")->execute([$productId]);
            $pdo->prepare("UPDATE product_media SET is_cover=1 WHERE id=? AND product_id=? AND resource_type='image'")->execute([$coverId, $productId]);
        }
        $cover = $pdo->prepare("SELECT COUNT(*) FROM product_media WHERE product_id=? AND resource_type='image' AND is_cover=1");
        $cover->execute([$productId]);
        if ((int) $cover->fetchColumn() === 0) {
            $pdo->exec("UPDATE product_media SET is_cover=1 WHERE product_id={$productId} AND resource_type='image' ORDER BY sort_order,id LIMIT 1");
        }
    }

    private function form(?array $product = null): string
    {
        if ($product === null && func_num_args() > 0) {
            http_response_code(404);
            return $this->page('errors/404', 'layouts/seller', ['pageTitle' => 'Produto não encontrado', 'path' => 'produto']);
        }
        $pdo = Database::connection();
        $categories = $pdo->query("WITH RECURSIVE category_tree AS (SELECT id,parent_id,name,sort_order,allow_products,0 depth,CAST(name AS CHAR(1000)) path FROM categories WHERE parent_id IS NULL AND status='active' UNION ALL SELECT c.id,c.parent_id,c.name,c.sort_order,c.allow_products,ct.depth+1,CONCAT(ct.path,' › ',c.name) FROM categories c JOIN category_tree ct ON ct.id=c.parent_id WHERE c.status='active') SELECT * FROM category_tree ORDER BY path")->fetchAll();
        $brands = $pdo->query("SELECT id,name FROM brands WHERE status='active' ORDER BY name")->fetchAll();
        $tags = $pdo->query("SELECT id,name,type FROM tags WHERE status='active' AND is_admin_only=0 ORDER BY FIELD(type,'audience','material','feature','occasion','style','collection','commercial'),name")->fetchAll();
        $selected = $selectedTags = $variants = $tiers = $specifications = $shippingRules = $media = [];
        $seo = [];
        if ($product) {
            $selectedStatement = $pdo->prepare('SELECT category_id FROM product_categories WHERE product_id=?');
            $selectedStatement->execute([$product['id']]);
            $selected = array_map('intval', $selectedStatement->fetchAll(PDO::FETCH_COLUMN));
            $selectedTagsStatement = $pdo->prepare('SELECT tag_id FROM product_tags WHERE product_id=?');
            $selectedTagsStatement->execute([$product['id']]);
            $selectedTags = array_map('intval', $selectedTagsStatement->fetchAll(PDO::FETCH_COLUMN));
            $variantsStatement = $pdo->prepare('SELECT pv.*,COALESCE(SUM(sk.quantity),0) stock,COALESCE(MAX(sk.minimum_quantity),0) minimum_quantity,COALESCE(MAX(pic.retail_quantity),0) retail_stock,COALESCE(MAX(pic.wholesale_quantity),0) wholesale_stock FROM product_variants pv LEFT JOIN stocks sk ON sk.product_variant_id=pv.id LEFT JOIN product_inventory_channels pic ON pic.variant_id=pv.id WHERE pv.product_id=? GROUP BY pv.id ORDER BY pv.id');
            $variantsStatement->execute([$product['id']]);
            $variants = $variantsStatement->fetchAll();
            foreach (['product_wholesale_tiers' => 'tiers', 'product_specifications' => 'specifications', 'product_shipping_rules' => 'shippingRules'] as $table => $variable) {
                $statement = $pdo->prepare("SELECT * FROM {$table} WHERE product_id=? ORDER BY id");
                $statement->execute([$product['id']]);
                ${$variable} = $statement->fetchAll();
            }
            $mediaStatement = $pdo->prepare('SELECT * FROM product_media WHERE product_id=? ORDER BY resource_type,sort_order,id');
            $mediaStatement->execute([$product['id']]);
            $media = $mediaStatement->fetchAll();
            $seoStatement = $pdo->prepare('SELECT * FROM product_seo WHERE product_id=?');
            $seoStatement->execute([$product['id']]);
            $seo = $seoStatement->fetch() ?: [];
        }
        return $this->page('seller/products/form', 'layouts/seller', [
            'pageTitle' => $product ? 'Editar produto' : 'Criar produto', 'product' => $product, 'categories' => $categories,
            'brands' => $brands, 'tags' => $tags, 'selectedCategories' => $selected, 'selectedTags' => $selectedTags, 'variants' => $variants, 'tiers' => $tiers,
            'specifications' => $specifications, 'shippingRules' => $shippingRules, 'media' => $media, 'seo' => $seo,
            'currentStore' => (new SellerStoreContext())->current(),
            'cloudinary' => \App\Services\Settings\PlatformSettings::enabled('cloudinary_enabled')
                ? ['cloudName' => (string) ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? ''), 'uploadPreset' => (string) ($_ENV['CLOUDINARY_UPLOAD_PRESET'] ?? '')]
                : ['cloudName' => '', 'uploadPreset' => ''],
        ]);
    }

    /** @return array<int,mixed> */
    private function jsonArray(string $field): array
    {
        $value = json_decode((string) ($_POST[$field] ?? '[]'), true);
        return is_array($value) ? array_values($value) : [];
    }

    private function nullableInt(string $field): ?int
    {
        return ($_POST[$field] ?? '') === '' ? null : max(0, (int) $_POST[$field]);
    }

    private function nullableNumberValue(mixed $value): ?float
    {
        return $value === '' || $value === null ? null : max(0, (float) $value);
    }

    private function generateSku(string $name): string
    {
        $tokens = array_values(array_filter(explode('-', Str::slug($name))));
        $base = strtoupper(implode('-', array_slice($tokens, 0, 4))) ?: 'PRODUTO';
        $check = Database::connection()->prepare('SELECT COUNT(*) FROM product_variants WHERE sku=?');
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = substr('TUF-' . $base . '-' . strtoupper(bin2hex(random_bytes(3))), 0, 100);
            $check->execute([$candidate]);
            if (!(int) $check->fetchColumn()) return $candidate;
        }
        throw new RuntimeException('Não foi possível gerar um SKU único. Tente novamente.');
    }

    private function generatedSeoKeywords(string $name): string
    {
        $ignored = ['a', 'as', 'com', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'o', 'os', 'para', 'por', 'um', 'uma'];
        $tokens = array_values(array_unique(array_filter(
            explode('-', Str::slug($name)),
            static fn(string $token): bool => mb_strlen($token) >= 2 && !in_array($token, $ignored, true)
        )));
        return implode(', ', array_slice($tokens, 0, 10));
    }

    private function warehouse(int $sellerId): int
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT id FROM warehouses WHERE seller_id=? ORDER BY id LIMIT 1');
        $statement->execute([$sellerId]);
        $id = (int) $statement->fetchColumn();
        if (!$id) {
            $pdo->prepare("INSERT INTO warehouses(seller_id,name,postal_code,city,state,status) VALUES(?,'Estoque principal','00000000','Não informado','SP','active')")->execute([$sellerId]);
            $id = (int) $pdo->lastInsertId();
        }
        return $id;
    }
}
