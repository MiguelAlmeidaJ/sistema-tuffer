<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Core\Auth;
use App\Core\Database;
use App\Support\Str;
use App\Services\Sellers\SellerSalesEligibility;
use RuntimeException;
use Throwable;

final class ProductImportService
{
    private const MAX_IMPORT_ROWS = 2_000;

    /** @var array<string,array{label:string,required:bool,aliases:array<int,string>}> */
    private const FIELDS = [
        'name' => ['label' => 'Nome do produto', 'required' => true, 'aliases' => ['nome', 'produto', 'name', 'product_name']],
        'sku' => ['label' => 'SKU', 'required' => true, 'aliases' => ['sku', 'codigo', 'codigo_sku', 'variant_sku', 'sku_da_variacao', 'product_sku', 'sku_do_produto']],
        'price' => ['label' => 'Preço', 'required' => true, 'aliases' => ['preco', 'price', 'valor', 'preco_de_venda']],
        'promotional_price' => ['label' => 'Preço promocional', 'required' => false, 'aliases' => ['preco_promocional', 'promotional_price', 'preco_de_promocao']],
        'wholesale_price' => ['label' => 'Preço de atacado', 'required' => false, 'aliases' => ['preco_de_atacado', 'preco_atacado', 'wholesale_price']],
        'stock' => ['label' => 'Estoque', 'required' => false, 'aliases' => ['estoque', 'stock', 'quantidade', 'estoque_disponivel']],
        'barcode' => ['label' => 'Código de barras', 'required' => false, 'aliases' => ['codigo_de_barras', 'barcode', 'ean', 'gtin']],
        'short_description' => ['label' => 'Descrição curta', 'required' => false, 'aliases' => ['descricao_curta', 'short_description', 'resumo']],
        'description' => ['label' => 'Descrição completa', 'required' => false, 'aliases' => ['descricao', 'description', 'descricao_completa']],
        'category' => ['label' => 'Categorias', 'required' => false, 'aliases' => ['categoria', 'categorias', 'category']],
        'brand' => ['label' => 'Marca', 'required' => false, 'aliases' => ['marca', 'brand']],
        'weight' => ['label' => 'Peso (kg)', 'required' => false, 'aliases' => ['peso', 'peso_kg', 'weight']],
        'width' => ['label' => 'Largura (cm)', 'required' => false, 'aliases' => ['largura', 'largura_cm', 'width']],
        'height' => ['label' => 'Altura (cm)', 'required' => false, 'aliases' => ['altura', 'altura_cm', 'height']],
        'length' => ['label' => 'Comprimento (cm)', 'required' => false, 'aliases' => ['comprimento', 'comprimento_cm', 'length']],
    ];

    /** @return array<string,array{label:string,required:bool,aliases:array<int,string>}> */
    public function fields(): array
    {
        return self::FIELDS;
    }

    /** @param array<int,string> $headers @return array<string,string> */
    public function suggestions(array $headers): array
    {
        $suggestions = [];
        foreach (self::FIELDS as $field => $definition) {
            foreach ($definition['aliases'] as $alias) {
                if (in_array($alias, $headers, true)) { $suggestions[$field] = $alias; break; }
            }
        }
        return $suggestions;
    }

    /** @param array<string,mixed> $store @param array<string,mixed> $stage @param array<string,string> $mapping @return array<string,mixed> */
    public function import(array $store, array $stage, array $mapping, string $conflict, string $newStatus): array
    {
        if (!(new SellerSalesEligibility())->sellerCanSell((int) ($store['seller_id'] ?? 0))) {
            throw new RuntimeException('Conclua sua configuração de recebimento antes de importar produtos.');
        }
        if (($stage['source_type'] ?? '') !== 'upload') throw new RuntimeException('Envie um arquivo CSV ou XML antes de importar.');
        $headers = array_values(array_filter($stage['headers'] ?? [], 'is_string'));
        $mapping = array_filter($mapping, static fn(string $source, string $target): bool => isset(self::FIELDS[$target]) && in_array($source, $headers, true), ARRAY_FILTER_USE_BOTH);
        foreach (self::FIELDS as $field => $definition) if ($definition['required'] && empty($mapping[$field])) throw new RuntimeException('Mapeie o campo obrigatório: ' . $definition['label'] . '.');
        $conflict = $conflict === 'update' ? 'update' : 'skip';
        $newStatus = $newStatus === 'active' ? 'active' : 'draft';
        $rows = array_slice(is_array($stage['rows'] ?? null) ? $stage['rows'] : [], 0, self::MAX_IMPORT_ROWS);
        if (!$rows) throw new RuntimeException('O arquivo preparado não possui produtos.');

        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'limited' => count($stage['rows'] ?? []) > self::MAX_IMPORT_ROWS];
        $pdo = Database::connection();
        $warehouseId = $this->warehouse((int) $store['seller_id']);
        foreach ($rows as $index => $row) {
            if (!is_array($row)) continue;
            try {
                $pdo->beginTransaction();
                $action = $this->importRow($store, $row, $mapping, $conflict, $newStatus, $warehouseId);
                $pdo->commit();
                $result[$action]++;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $result['failed']++;
                if (count($result['errors']) < 20) $result['errors'][] = 'Linha ' . ($index + 2) . ': ' . ($exception instanceof RuntimeException ? $exception->getMessage() : 'não foi possível gravar o produto.');
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $store @param array<string,mixed> $row @param array<string,string> $mapping */
    private function importRow(array $store, array $row, array $mapping, string $conflict, string $newStatus, int $warehouseId): string
    {
        $pdo = Database::connection();
        $name = mb_substr($this->value($row, $mapping, 'name'), 0, 190);
        $sku = mb_substr($this->value($row, $mapping, 'sku'), 0, 100);
        $price = $this->decimal($this->value($row, $mapping, 'price'));
        if (mb_strlen($name) < 3) throw new RuntimeException('nome do produto inválido.');
        if ($sku === '') throw new RuntimeException('SKU não informado.');
        if ($price === null || $price <= 0) throw new RuntimeException('preço inválido.');

        $existing = $pdo->prepare('SELECT pv.id variant_id,pv.product_id,pv.barcode,pv.promotional_price,pv.wholesale_price,p.store_id,p.status product_status,p.description,p.short_description,p.brand_id,p.weight,p.width,p.height,p.length FROM product_variants pv JOIN products p ON p.id=pv.product_id WHERE pv.sku=? LIMIT 1 FOR UPDATE');
        $existing->execute([$sku]);
        $current = $existing->fetch() ?: null;
        if ($current && (int) $current['store_id'] !== (int) $store['id']) throw new RuntimeException("o SKU {$sku} já pertence a outra loja.");
        if ($current && $conflict === 'skip') return 'skipped';

        $brandId = $this->mapped($mapping, 'brand') ? $this->brandId($this->value($row, $mapping, 'brand')) : ($current['brand_id'] ?? null);
        $dimensions = [];
        foreach (['weight', 'width', 'height', 'length'] as $field) $dimensions[$field] = $this->mapped($mapping, $field) ? $this->decimal($this->value($row, $mapping, $field)) : ($current[$field] ?? null);
        $description = $this->mapped($mapping, 'description') ? $this->nullableText($this->value($row, $mapping, 'description'), 65_000) : ($current['description'] ?? null);
        $shortDescription = $this->mapped($mapping, 'short_description') ? $this->nullableText($this->value($row, $mapping, 'short_description'), 500) : ($current['short_description'] ?? null);

        if ($current) {
            $productId = (int) $current['product_id'];
            $pdo->prepare('UPDATE products SET name=?,sku=?,description=?,short_description=?,brand_id=?,weight=?,width=?,height=?,length=? WHERE id=? AND store_id=?')->execute([$name, $sku, $description, $shortDescription, $brandId, $dimensions['weight'], $dimensions['width'], $dimensions['height'], $dimensions['length'], $productId, $store['id']]);
            $variantId = (int) $current['variant_id'];
            $pdo->prepare('UPDATE product_variants SET sku=?,barcode=?,price=?,promotional_price=?,wholesale_price=?,weight=?,width=?,height=?,length=?,status=\'active\' WHERE id=?')->execute([
                $sku, $this->mapped($mapping, 'barcode') ? $this->optional($row, $mapping, 'barcode', 100) : $current['barcode'], $price,
                $this->mapped($mapping, 'promotional_price') ? $this->optionalDecimal($row, $mapping, 'promotional_price') : $current['promotional_price'],
                $this->mapped($mapping, 'wholesale_price') ? $this->optionalDecimal($row, $mapping, 'wholesale_price') : $current['wholesale_price'],
                $dimensions['weight'], $dimensions['width'], $dimensions['height'], $dimensions['length'], $variantId,
            ]);
            $action = 'updated';
        } else {
            $slug = $this->uniqueSlug($name, $sku);
            $pdo->prepare("INSERT INTO products(seller_id,store_id,brand_id,name,slug,sku,description,short_description,product_type,status,weight,width,height,length) VALUES(?,?,?,?,?,?,?,?,'simple',?,?,?,?,?)")->execute([
                $store['seller_id'], $store['id'], $brandId, $name, $slug, $sku, $description, $shortDescription, $newStatus,
                $dimensions['weight'], $dimensions['width'], $dimensions['height'], $dimensions['length'],
            ]);
            $productId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO product_variants(product_id,name,sku,barcode,price,promotional_price,wholesale_price,weight,width,height,length,status) VALUES(?,'Padrão',?,?,?,?,?,?,?,?,?,'active')")->execute([
                $productId, $sku, $this->optional($row, $mapping, 'barcode', 100), $price,
                $this->optionalDecimal($row, $mapping, 'promotional_price'), $this->optionalDecimal($row, $mapping, 'wholesale_price'),
                $dimensions['weight'], $dimensions['width'], $dimensions['height'], $dimensions['length'],
            ]);
            $variantId = (int) $pdo->lastInsertId();
            $action = 'created';
        }

        if ($this->mapped($mapping, 'stock') || $action === 'created') {
            $quantity = max(0, (int) round($this->decimal($this->value($row, $mapping, 'stock')) ?? 0));
            $this->stock($warehouseId, $variantId, $quantity);
        }
        if ($this->mapped($mapping, 'category')) $this->categories($productId, $this->value($row, $mapping, 'category'));
        return $action;
    }

    private function stock(int $warehouseId, int $variantId, int $quantity): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT id,quantity,reserved_quantity FROM stocks WHERE warehouse_id=? AND product_variant_id=? FOR UPDATE');
        $statement->execute([$warehouseId, $variantId]);
        $current = $statement->fetch();
        $safeQuantity = max($quantity, (int) ($current['reserved_quantity'] ?? 0));
        $pdo->prepare('INSERT INTO stocks(warehouse_id,product_variant_id,quantity,reserved_quantity,minimum_quantity) VALUES(?,?,?,0,0) ON DUPLICATE KEY UPDATE quantity=GREATEST(VALUES(quantity),reserved_quantity)')->execute([$warehouseId, $variantId, $safeQuantity]);
        $stockId = $current ? (int) $current['id'] : (int) $pdo->lastInsertId();
        $difference = $safeQuantity - (int) ($current['quantity'] ?? 0);
        if ($difference !== 0) $pdo->prepare("INSERT INTO stock_movements(stock_id,user_id,type,quantity,reference_type,notes) VALUES(?,?,'adjustment',?,'product_import','Estoque definido por importação de produtos')")->execute([$stockId, Auth::id(), $difference]);
    }

    private function categories(int $productId, string $value): void
    {
        $names = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;|]+/', $value) ?: []))));
        if (!$names) return;
        $pdo = Database::connection();
        $find = $pdo->prepare("SELECT id FROM categories WHERE status='active' AND (LOWER(name)=LOWER(?) OR slug=?) LIMIT 1");
        $ids = [];
        foreach ($names as $name) { $find->execute([$name, Str::slug($name)]); $id = (int) $find->fetchColumn(); if ($id) $ids[] = $id; }
        if (!$ids) return;
        $pdo->prepare('DELETE FROM product_categories WHERE product_id=?')->execute([$productId]);
        $insert = $pdo->prepare('INSERT INTO product_categories(product_id,category_id) VALUES(?,?)');
        foreach (array_unique($ids) as $id) $insert->execute([$productId, $id]);
        $pdo->prepare('UPDATE products SET primary_category_id=? WHERE id=?')->execute([$ids[0], $productId]);
    }

    private function brandId(string $value): ?int
    {
        if ($value === '') return null;
        $statement = Database::connection()->prepare("SELECT id FROM brands WHERE status='active' AND (LOWER(name)=LOWER(?) OR slug=?) LIMIT 1");
        $statement->execute([$value, Str::slug($value)]);
        return ($id = (int) $statement->fetchColumn()) > 0 ? $id : null;
    }

    private function warehouse(int $sellerId): int
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare("SELECT id FROM warehouses WHERE seller_id=? AND status='active' ORDER BY id LIMIT 1");
        $statement->execute([$sellerId]);
        $id = (int) $statement->fetchColumn();
        if ($id) return $id;
        $pdo->prepare("INSERT INTO warehouses(seller_id,name,postal_code,city,state,status) VALUES(?,'Estoque principal','00000000','Não informado','SP','active')")->execute([$sellerId]);
        return (int) $pdo->lastInsertId();
    }

    private function uniqueSlug(string $name, string $sku): string
    {
        $base = Str::slug($name) ?: 'produto';
        $candidate = $base;
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM products WHERE slug=?');
        $suffix = Str::slug($sku);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $statement->execute([$candidate]);
            if ((int) $statement->fetchColumn() === 0) return mb_substr($candidate, 0, 210);
            $candidate = mb_substr($base, 0, 180) . '-' . ($suffix ?: ($attempt + 2)) . ($attempt ? '-' . ($attempt + 2) : '');
        }
        return mb_substr($base, 0, 175) . '-' . bin2hex(random_bytes(6));
    }

    /** @param array<string,mixed> $row @param array<string,string> $mapping */
    private function value(array $row, array $mapping, string $field): string
    {
        return trim((string) ($row[$mapping[$field] ?? ''] ?? ''));
    }

    /** @param array<string,string> $mapping */
    private function mapped(array $mapping, string $field): bool { return isset($mapping[$field]) && $mapping[$field] !== ''; }

    /** @param array<string,mixed> $row @param array<string,string> $mapping */
    private function optional(array $row, array $mapping, string $field, int $limit): ?string
    {
        if (!$this->mapped($mapping, $field)) return null;
        return $this->nullableText($this->value($row, $mapping, $field), $limit);
    }

    /** @param array<string,mixed> $row @param array<string,string> $mapping */
    private function optionalDecimal(array $row, array $mapping, string $field): ?float
    {
        return $this->mapped($mapping, $field) ? $this->decimal($this->value($row, $mapping, $field)) : null;
    }

    private function nullableText(string $value, int $limit): ?string { return $value === '' ? null : mb_substr($value, 0, $limit); }

    private function decimal(string $value): ?float
    {
        $value = trim(str_replace(["R$", "\xc2\xa0", ' '], '', $value));
        if ($value === '') return null;
        if (str_contains($value, ',') && str_contains($value, '.')) $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value) ?? '';
        return is_numeric($value) ? round((float) $value, 3) : null;
    }
}
