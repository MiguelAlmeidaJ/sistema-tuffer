<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use PDO;
use Throwable;

final class ProductController extends Controller
{
    private const VIEWS = ['all', 'pending', 'published', 'problems', 'paused', 'rejected', 'out_of_stock', 'reported'];
    private const PAGE_SIZES = [25, 50, 100];

    public function index(): string
    {
        $pdo = Database::connection();
        $filters = $this->filters();
        [$whereSql, $params] = $this->where($filters);
        $dataset = $this->datasetSql();

        $count = $pdo->prepare("SELECT COUNT(*) FROM ({$dataset}) catalog WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $lastPage = max(1, (int) ceil($total / $filters['per_page']));
        $page = min($lastPage, max(1, (int) ($_GET['page'] ?? 1)));
        $offset = ($page - 1) * $filters['per_page'];

        $statement = $pdo->prepare("SELECT * FROM ({$dataset}) catalog WHERE {$whereSql} ORDER BY updated_at DESC,product_id DESC LIMIT ? OFFSET ?");
        foreach ($params as $index => $value) $statement->bindValue($index + 1, $value);
        $statement->bindValue(count($params) + 1, $filters['per_page'], PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $statement->execute();

        $summary = $pdo->query("SELECT COUNT(*) total,
            COALESCE(SUM(moderation_status='pending'),0) pending,
            COALESCE(SUM(commercial_status='active' AND platform_paused=0),0) published,
            COALESCE(SUM(quality_score<65 OR alert_count>0 OR moderation_status='changes_requested'),0) problems,
            COALESCE(SUM(commercial_status='paused' OR platform_paused=1),0) paused,
            COALESCE(SUM(moderation_status='rejected'),0) rejected,
            COALESCE(SUM(stock<=0),0) out_of_stock,
            COALESCE(SUM(report_count>0),0) reported
            FROM ({$dataset}) catalog")->fetch() ?: [];

        return $this->page('admin/products/index', 'layouts/admin', [
            'pageTitle' => 'Catálogo global',
            'products' => $statement->fetchAll(),
            'filters' => $filters,
            'summary' => $summary,
            'pagination' => [
                'page' => $page,
                'perPage' => $filters['per_page'],
                'total' => $total,
                'lastPage' => $lastPage,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $filters['per_page'], $total),
            ],
            'pageSizes' => self::PAGE_SIZES,
            'categories' => $pdo->query("SELECT id,name FROM categories WHERE status='active' ORDER BY name")->fetchAll(),
            'stores' => $pdo->query('SELECT id,name FROM stores ORDER BY name')->fetchAll(),
            'brands' => $pdo->query("SELECT id,name FROM brands WHERE status='active' ORDER BY name")->fetchAll(),
            'permissions' => $this->permissions(),
        ]);
    }

    public function show(string $id): string
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM (' . $this->datasetSql() . ') catalog WHERE product_id=?');
        $statement->execute([(int) $id]);
        $product = $statement->fetch();
        if (!$product) return $this->notFound();

        $queries = [
            'categories' => 'SELECT c.name FROM product_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.product_id=? ORDER BY c.name',
            'tags' => 'SELECT t.name FROM product_tags pt JOIN tags t ON t.id=pt.tag_id WHERE pt.product_id=? ORDER BY t.name',
            'variants' => 'SELECT pv.*,COALESCE(SUM(sk.quantity-sk.reserved_quantity),0) stock FROM product_variants pv LEFT JOIN stocks sk ON sk.product_variant_id=pv.id WHERE pv.product_id=? GROUP BY pv.id ORDER BY pv.id',
            'media' => 'SELECT * FROM product_media WHERE product_id=? ORDER BY resource_type,sort_order,id',
            'reports' => "SELECT pr.*,u.name reporter_name FROM product_reports pr LEFT JOIN users u ON u.id=pr.user_id WHERE pr.product_id=? ORDER BY FIELD(pr.status,'open','under_review','resolved','dismissed'),pr.created_at DESC",
            'history' => 'SELECT h.*,u.name admin_name FROM product_moderation_history h LEFT JOIN users u ON u.id=h.admin_user_id WHERE h.product_id=? ORDER BY h.created_at DESC,h.id DESC',
        ];
        $data = [];
        foreach ($queries as $key => $sql) {
            $query = $pdo->prepare($sql);
            $query->execute([(int) $id]);
            $data[$key] = $query->fetchAll();
        }

        return $this->page('admin/products/show', 'layouts/admin', $data + [
            'pageTitle' => 'Revisar produto',
            'product' => $product,
            'permissions' => $this->permissions(),
        ]);
    }

    public function startReview(string $id): string
    {
        return $this->transition((int) $id, 'under_review', 'analysis_started', 'Análise iniciada.');
    }

    public function approve(string $id): string
    {
        return $this->transition((int) $id, 'approved', 'approved', 'Anúncio aprovado.', null, false);
    }

    public function requestChanges(string $id): string
    {
        $reason = $this->requiredReason((int) $id);
        if ($reason === null) return '';
        return $this->transition((int) $id, 'changes_requested', 'changes_requested', 'Correções solicitadas ao vendedor.', $reason, true);
    }

    public function reject(string $id): string
    {
        $reason = $this->requiredReason((int) $id);
        if ($reason === null) return '';
        return $this->transition((int) $id, 'rejected', 'rejected', 'Anúncio rejeitado.', $reason, true);
    }

    public function pause(string $id): string
    {
        $reason = $this->requiredReason((int) $id);
        if ($reason === null) return '';
        return $this->platformPause((int) $id, true, $reason);
    }

    public function resume(string $id): string
    {
        return $this->platformPause((int) $id, false, null);
    }

    public function feature(string $id): string
    {
        $featured = ($_POST['featured'] ?? '0') === '1';
        $pdo = Database::connection();
        $product = $pdo->prepare('SELECT featured,moderation_status FROM products WHERE id=?');
        $product->execute([(int) $id]);
        $current = $product->fetch();
        if (!$current) return $this->notFound();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE products SET featured=? WHERE id=?')->execute([$featured ? 1 : 0, (int) $id]);
            $pdo->prepare('INSERT INTO product_moderation_history(product_id,admin_user_id,action,previous_status,new_status,reason) VALUES(?,?,?,?,?,?)')->execute([(int) $id, Auth::id(), $featured ? 'featured' : 'unfeatured', $current['moderation_status'], $current['moderation_status'], null]);
            $pdo->commit();
            Session::flash('success', $featured ? 'Produto destacado.' : 'Destaque removido.');
        } catch (Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Session::flash('error', 'Não foi possível alterar o destaque.');
        }
        return Response::redirect('/admin/produtos/' . (int) $id);
    }

    public function export(): string
    {
        $pdo = Database::connection();
        $filters = $this->filters();
        [$whereSql, $params] = $this->where($filters);
        $statement = $pdo->prepare('SELECT * FROM (' . $this->datasetSql() . ") catalog WHERE {$whereSql} ORDER BY updated_at DESC");
        $statement->execute($params);

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) return '';
        fwrite($stream, "\xEF\xBB\xBF");
        $safeCell = static function (mixed $value): string {
            $cell = (string) $value;
            return preg_match('/^\s*[=+\-@]/u', $cell) === 1 ? "'" . $cell : $cell;
        };
        fputcsv($stream, ['Produto', 'SKU', 'Loja', 'Vendedor', 'Preço', 'Estoque', 'Venda', 'Qualidade', 'Moderação', 'Status comercial', 'Atualizado'], ';');
        foreach ($statement->fetchAll() as $product) {
            $sale = $product['retail_enabled'] && $product['wholesale_enabled'] ? 'Varejo e atacado' : ($product['wholesale_enabled'] ? 'Atacado' : 'Varejo');
            fputcsv($stream, array_map($safeCell, [$product['product_name'], $product['product_sku'], $product['store_name'], $product['seller_name'], number_format((float) $product['price'], 2, ',', '.'), $product['stock'], $sale, $product['quality_score'] . '%', $product['moderation_status'], $product['commercial_status'], $product['updated_at']]), ';');
        }
        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="catalogo-global-' . date('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        return $csv;
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        $view = in_array($_GET['view'] ?? '', self::VIEWS, true) ? (string) $_GET['view'] : 'all';
        $perPage = (int) ($_GET['per_page'] ?? 25);
        if (!in_array($perPage, self::PAGE_SIZES, true)) $perPage = 25;
        $quality = in_array($_GET['quality'] ?? '', ['excellent', 'good', 'incomplete', 'problem'], true) ? (string) $_GET['quality'] : '';
        $saleType = in_array($_GET['sale_type'] ?? '', ['retail', 'wholesale', 'both'], true) ? (string) $_GET['sale_type'] : '';
        $stock = in_array($_GET['stock'] ?? '', ['available', 'out'], true) ? (string) $_GET['stock'] : '';
        $status = in_array($_GET['status'] ?? '', ['draft', 'active', 'paused', 'archived', 'pending', 'under_review', 'approved', 'changes_requested', 'rejected', 'platform_paused'], true) ? (string) $_GET['status'] : '';
        return [
            'view' => $view,
            'q' => trim((string) ($_GET['q'] ?? '')),
            'status' => $status,
            'category_id' => max(0, (int) ($_GET['category_id'] ?? 0)),
            'store_id' => max(0, (int) ($_GET['store_id'] ?? 0)),
            'brand_id' => max(0, (int) ($_GET['brand_id'] ?? 0)),
            'sale_type' => $saleType,
            'stock' => $stock,
            'quality' => $quality,
            'updated_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['updated_from'] ?? '')) ? (string) $_GET['updated_from'] : '',
            'per_page' => $perPage,
        ];
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<int,mixed>} */
    private function where(array $filters): array
    {
        $where = ['1=1'];
        $params = [];
        if ($filters['q'] !== '') {
            $where[] = '(catalog.product_name LIKE ? OR catalog.product_sku LIKE ? OR catalog.store_name LIKE ? OR catalog.seller_name LIKE ? OR catalog.barcodes LIKE ?)';
            $search = '%' . $filters['q'] . '%';
            array_push($params, $search, $search, $search, $search, $search);
        }
        foreach (['category_id' => 'primary_category_id', 'store_id' => 'store_id', 'brand_id' => 'brand_id'] as $filter => $column) {
            if ($filters[$filter]) { $where[] = "catalog.{$column}=?"; $params[] = $filters[$filter]; }
        }
        if ($filters['sale_type'] === 'retail') $where[] = 'catalog.retail_enabled=1 AND catalog.wholesale_enabled=0';
        if ($filters['sale_type'] === 'wholesale') $where[] = 'catalog.retail_enabled=0 AND catalog.wholesale_enabled=1';
        if ($filters['sale_type'] === 'both') $where[] = 'catalog.retail_enabled=1 AND catalog.wholesale_enabled=1';
        if ($filters['stock'] === 'available') $where[] = 'catalog.stock>0';
        if ($filters['stock'] === 'out') $where[] = 'catalog.stock<=0';
        if ($filters['quality'] === 'excellent') $where[] = 'catalog.quality_score>=85';
        if ($filters['quality'] === 'good') $where[] = 'catalog.quality_score BETWEEN 65 AND 84';
        if ($filters['quality'] === 'incomplete') $where[] = 'catalog.quality_score BETWEEN 40 AND 64';
        if ($filters['quality'] === 'problem') $where[] = 'catalog.quality_score<40';
        if ($filters['updated_from'] !== '') { $where[] = 'catalog.updated_at>=?'; $params[] = $filters['updated_from'] . ' 00:00:00'; }

        if (in_array($filters['status'], ['draft', 'active', 'paused', 'archived'], true)) { $where[] = 'catalog.commercial_status=?'; $params[] = $filters['status']; }
        elseif (in_array($filters['status'], ['pending', 'under_review', 'approved', 'changes_requested', 'rejected'], true)) { $where[] = 'catalog.moderation_status=?'; $params[] = $filters['status']; }
        elseif ($filters['status'] === 'platform_paused') $where[] = 'catalog.platform_paused=1';

        $viewConditions = [
            'pending' => "catalog.moderation_status='pending'",
            'published' => "catalog.commercial_status='active' AND catalog.platform_paused=0",
            'problems' => "(catalog.quality_score<65 OR catalog.alert_count>0 OR catalog.moderation_status='changes_requested')",
            'paused' => "(catalog.commercial_status='paused' OR catalog.platform_paused=1)",
            'rejected' => "catalog.moderation_status='rejected'",
            'out_of_stock' => 'catalog.stock<=0',
            'reported' => 'catalog.report_count>0',
        ];
        if (isset($viewConditions[$filters['view']])) $where[] = $viewConditions[$filters['view']];
        return [implode(' AND ', $where), $params];
    }

    private function datasetSql(): string
    {
        $facts = "SELECT p.id product_id,p.name product_name,p.slug product_slug,p.sku product_sku,p.description,p.short_description,p.product_type,p.status commercial_status,p.moderation_status,p.platform_paused,p.moderation_reason,p.featured,p.retail_enabled,p.wholesale_enabled,p.allow_backorder,p.primary_category_id,p.brand_id,p.store_id,p.updated_at,
            st.name store_name,st.slug store_slug,st.status store_status,s.trade_name seller_name,s.document seller_document,b.name brand_name,c.name category_name,
            COALESCE((SELECT MIN(COALESCE(pv.promotional_price,pv.price)) FROM product_variants pv WHERE pv.product_id=p.id AND pv.status='active'),0) price,
            COALESCE((SELECT SUM(GREATEST(sk.quantity-sk.reserved_quantity,0)) FROM product_variants pv JOIN stocks sk ON sk.product_variant_id=pv.id WHERE pv.product_id=p.id),0) stock,
            (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id=p.id) variant_count,
            (SELECT GROUP_CONCAT(DISTINCT pv.barcode SEPARATOR ' ') FROM product_variants pv WHERE pv.product_id=p.id AND pv.barcode IS NOT NULL) barcodes,
            (SELECT pm.secure_url FROM product_media pm WHERE pm.product_id=p.id AND pm.resource_type='image' ORDER BY pm.is_cover DESC,pm.sort_order,pm.id LIMIT 1) image_url,
            (SELECT COUNT(*) FROM product_media pm WHERE pm.product_id=p.id AND pm.resource_type='image') image_count,
            (SELECT COUNT(*) FROM product_media pm WHERE pm.product_id=p.id AND pm.resource_type='image' AND (COALESCE(pm.width,0)<1080 OR COALESCE(pm.height,0)<1080)) low_resolution_images,
            (SELECT COUNT(*) FROM product_media pm WHERE pm.product_id=p.id AND pm.resource_type='video') video_count,
            (SELECT COUNT(*) FROM product_specifications ps WHERE ps.product_id=p.id) specification_count,
            (SELECT COUNT(*) FROM product_reports pr WHERE pr.product_id=p.id AND pr.status IN ('open','under_review')) report_count,
            CASE WHEN p.weight>0 AND p.width>0 AND p.height>0 AND p.length>0 OR EXISTS(SELECT 1 FROM product_variants pv WHERE pv.product_id=p.id AND pv.weight>0 AND pv.width>0 AND pv.height>0 AND pv.length>0) THEN 1 ELSE 0 END has_dimensions
            FROM products p JOIN stores st ON st.id=p.store_id JOIN sellers s ON s.id=p.seller_id LEFT JOIN brands b ON b.id=p.brand_id LEFT JOIN categories c ON c.id=p.primary_category_id";

        return "SELECT facts.*,
            LEAST(100,
                CASE WHEN CHAR_LENGTH(TRIM(facts.product_name))>=20 THEN 10 WHEN CHAR_LENGTH(TRIM(facts.product_name))>0 THEN 5 ELSE 0 END +
                CASE WHEN CHAR_LENGTH(TRIM(COALESCE(facts.description,'')))>=120 THEN 15 WHEN CHAR_LENGTH(TRIM(COALESCE(facts.description,'')))>=60 THEN 8 ELSE 0 END +
                CASE WHEN facts.primary_category_id IS NOT NULL THEN 10 ELSE 0 END +
                CASE WHEN facts.price>0 THEN 15 ELSE 0 END +
                CASE WHEN facts.stock>0 OR facts.allow_backorder=1 THEN 10 ELSE 0 END +
                CASE WHEN facts.has_dimensions=1 THEN 10 ELSE 0 END +
                CASE WHEN facts.image_count>=3 THEN 15 WHEN facts.image_count>0 THEN 7 ELSE 0 END +
                CASE WHEN facts.video_count>0 THEN 5 ELSE 0 END +
                CASE WHEN facts.specification_count>0 THEN 5 ELSE 0 END +
                CASE WHEN facts.variant_count>0 THEN 5 ELSE 0 END
            ) quality_score,
            ((facts.image_count=0) + (facts.low_resolution_images>0) + (facts.primary_category_id IS NULL) + (facts.stock<=0 AND facts.allow_backorder=0) + (CHAR_LENGTH(TRIM(COALESCE(facts.description,'')))<80) + (facts.has_dimensions=0) + (facts.report_count>0)) alert_count
            FROM ({$facts}) facts";
    }

    /** @return array<string,bool> */
    private function permissions(): array
    {
        $statement = Database::connection()->prepare("SELECT p.slug FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=? AND p.slug LIKE 'catalog.%'");
        $statement->execute([Auth::id()]);
        return array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);
    }

    private function requiredReason(int $id): ?string
    {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (mb_strlen($reason) >= 10) return $reason;
        Session::flash('error', 'Informe um motivo claro, com pelo menos 10 caracteres.');
        Response::redirect('/admin/produtos/' . $id);
        return null;
    }

    private function transition(int $id, string $to, string $action, string $message, ?string $reason = null, ?bool $paused = null): string
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT moderation_status FROM products WHERE id=? FOR UPDATE');
            $statement->execute([$id]);
            $previous = $statement->fetchColumn();
            if ($previous === false) { $pdo->rollBack(); return $this->notFound(); }
            $sets = ['moderation_status=?', 'moderation_reason=?', 'moderated_by=?', 'moderated_at=NOW()'];
            $params = [$to, $reason, Auth::id()];
            if ($paused !== null) { $sets[] = 'platform_paused=?'; $params[] = $paused ? 1 : 0; }
            $params[] = $id;
            $pdo->prepare('UPDATE products SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
            $pdo->prepare('INSERT INTO product_moderation_history(product_id,admin_user_id,action,previous_status,new_status,reason) VALUES(?,?,?,?,?,?)')->execute([$id, Auth::id(), $action, $previous, $to, $reason]);
            $pdo->commit();
            Session::flash('success', $message);
        } catch (Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Session::flash('error', 'Não foi possível atualizar a moderação do anúncio.');
        }
        return Response::redirect('/admin/produtos/' . $id);
    }

    private function platformPause(int $id, bool $paused, ?string $reason): string
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT moderation_status,platform_paused FROM products WHERE id=? FOR UPDATE');
            $statement->execute([$id]);
            $product = $statement->fetch();
            if (!$product) { $pdo->rollBack(); return $this->notFound(); }
            $pdo->prepare('UPDATE products SET platform_paused=?,moderation_reason=?,moderated_by=?,moderated_at=NOW() WHERE id=?')->execute([$paused ? 1 : 0, $reason, Auth::id(), $id]);
            $pdo->prepare('INSERT INTO product_moderation_history(product_id,admin_user_id,action,previous_status,new_status,reason) VALUES(?,?,?,?,?,?)')->execute([$id, Auth::id(), $paused ? 'platform_paused' : 'platform_resumed', $product['moderation_status'], $product['moderation_status'], $reason]);
            $pdo->commit();
            Session::flash('success', $paused ? 'Anúncio pausado pela plataforma.' : 'Pausa administrativa removida.');
        } catch (Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Session::flash('error', 'Não foi possível alterar a disponibilidade do anúncio.');
        }
        return Response::redirect('/admin/produtos/' . $id);
    }

    private function notFound(): string
    {
        http_response_code(404);
        return $this->page('errors/404', 'layouts/admin', ['pageTitle' => 'Produto não encontrado', 'path' => 'produto']);
    }
}
