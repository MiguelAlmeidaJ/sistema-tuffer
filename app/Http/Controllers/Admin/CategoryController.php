<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Media\SiteUploadService;
use App\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

final class CategoryController extends Controller
{
    public function index(): string
    {
        $sql = "WITH RECURSIVE category_tree AS (
            SELECT id,parent_id,name,slug,description,image_path,support_text,status,sort_order,show_in_menu,show_in_home,is_featured,allow_products,customer_visible,created_at,updated_at,0 depth,CAST(name AS CHAR(1000)) path,CAST(LPAD(sort_order,6,'0') AS CHAR(1000)) sort_path
            FROM categories WHERE parent_id IS NULL
            UNION ALL
            SELECT c.id,c.parent_id,c.name,c.slug,c.description,c.image_path,c.support_text,c.status,c.sort_order,c.show_in_menu,c.show_in_home,c.is_featured,c.allow_products,c.customer_visible,c.created_at,c.updated_at,ct.depth+1,CONCAT(ct.path,' › ',c.name),CONCAT(ct.sort_path,'/',LPAD(c.sort_order,6,'0'))
            FROM categories c JOIN category_tree ct ON ct.id=c.parent_id
        )
        SELECT ct.*,
            (SELECT COUNT(DISTINCT pc.product_id) FROM product_categories pc WHERE pc.category_id=ct.id) products,
            (SELECT COUNT(*) FROM categories child WHERE child.parent_id=ct.id) subcategories
        FROM category_tree ct ORDER BY ct.sort_path,ct.name";
        $categories = Database::connection()->query($sql)->fetchAll();
        $metrics = [
            'total' => count($categories),
            'active' => count(array_filter($categories, static fn(array $category): bool => $category['status'] === 'active')),
            'home' => count(array_filter($categories, static fn(array $category): bool => (bool) $category['show_in_home'])),
            'with_image' => count(array_filter($categories, static fn(array $category): bool => trim((string) $category['image_path']) !== '')),
        ];
        return $this->page('admin/categories/index', 'layouts/admin', [
            'pageTitle' => 'Categorias',
            'categories' => $categories,
            'metrics' => $metrics,
        ]);
    }

    public function create(): string
    {
        return $this->form();
    }

    public function store(): string
    {
        $pdo = Database::connection();
        $uploads = new SiteUploadService();
        $newImage = '';
        try {
            $data = $this->validatedInput();
            $this->assertParentExists($data['parent_id']);
            $this->assertUniqueSlug($data['slug']);
            $pdo->beginTransaction();
            $statement = $pdo->prepare('INSERT INTO categories(parent_id,name,slug,description,image_path,support_text,meta_title,meta_description,sort_order,show_in_menu,show_in_home,is_featured,allow_products,customer_visible,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute([
                $data['parent_id'], $data['name'], $data['slug'], $data['description'], null,
                $data['support_text'], $data['meta_title'], $data['meta_description'], $data['sort_order'],
                $data['show_in_menu'], $data['show_in_home'], $data['is_featured'], $data['allow_products'],
                $data['customer_visible'], $data['status'],
            ]);
            $categoryId = (int) $pdo->lastInsertId();
            if ($this->hasImageUpload()) {
                $newImage = $uploads->storeCategorySquare($_FILES['image']);
                $pdo->prepare('UPDATE categories SET image_path=? WHERE id=?')->execute([$newImage, $categoryId]);
            }
            $pdo->commit();
            Session::flash('success', 'Categoria criada com sucesso.');
            return Response::redirect('/admin/categorias/' . $categoryId . '/editar');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($newImage !== '') $uploads->deleteManaged($newImage);
            Session::flash('old', $_POST);
            Session::flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível criar a categoria.');
            return Response::redirect('/admin/categorias/nova');
        }
    }

    public function edit(string $id): string
    {
        $statement = Database::connection()->prepare('SELECT * FROM categories WHERE id=?');
        $statement->execute([(int) $id]);
        return $this->form($statement->fetch() ?: null);
    }

    public function update(string $id): string
    {
        $categoryId = (int) $id;
        $pdo = Database::connection();
        $uploads = new SiteUploadService();
        $statement = $pdo->prepare('SELECT * FROM categories WHERE id=?');
        $statement->execute([$categoryId]);
        $current = $statement->fetch();
        if (!$current) {
            Session::flash('error', 'Categoria não encontrada.');
            return Response::redirect('/admin/categorias');
        }

        $newImage = '';
        try {
            $data = $this->validatedInput();
            $this->assertParentExists($data['parent_id']);
            if ($data['parent_id'] !== null && $this->isDescendant($categoryId, $data['parent_id'])) {
                throw new RuntimeException('Uma categoria não pode ser filha dela mesma ou de uma descendente.');
            }
            $this->assertUniqueSlug($data['slug'], $categoryId);
            $imagePath = !empty($_POST['remove_image']) ? '' : (string) ($current['image_path'] ?? '');
            if ($this->hasImageUpload()) {
                $newImage = $uploads->storeCategorySquare($_FILES['image']);
                $imagePath = $newImage;
            }

            $pdo->beginTransaction();
            $statement = $pdo->prepare('UPDATE categories SET parent_id=?,name=?,slug=?,description=?,image_path=?,support_text=?,meta_title=?,meta_description=?,sort_order=?,show_in_menu=?,show_in_home=?,is_featured=?,allow_products=?,customer_visible=?,status=? WHERE id=?');
            $statement->execute([
                $data['parent_id'], $data['name'], $data['slug'], $data['description'], $imagePath ?: null,
                $data['support_text'], $data['meta_title'], $data['meta_description'], $data['sort_order'],
                $data['show_in_menu'], $data['show_in_home'], $data['is_featured'], $data['allow_products'],
                $data['customer_visible'], $data['status'], $categoryId,
            ]);
            $pdo->commit();
            $oldImage = (string) ($current['image_path'] ?? '');
            if ($oldImage !== '' && $oldImage !== $imagePath) $uploads->deleteManaged($oldImage);
            Session::flash('success', 'Categoria atualizada com sucesso.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($newImage !== '') $uploads->deleteManaged($newImage);
            Session::flash('old', $_POST);
            Session::flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível atualizar a categoria.');
        }
        return Response::redirect('/admin/categorias/' . $categoryId . '/editar');
    }

    public function reorder(string $id): string
    {
        $categoryId = (int) $id;
        $direction = (string) ($_POST['direction'] ?? '');
        if (!in_array($direction, ['up', 'down'], true)) return Response::redirect('/admin/categorias');
        $pdo = Database::connection();
        try {
            $current = $pdo->prepare('SELECT parent_id FROM categories WHERE id=?');
            $current->execute([$categoryId]);
            $parentId = $current->fetchColumn();
            if ($parentId === false) throw new RuntimeException('Categoria não encontrada.');
            $siblings = $pdo->prepare('SELECT id FROM categories WHERE parent_id <=> ? ORDER BY sort_order,name,id');
            $siblings->execute([$parentId === null ? null : (int) $parentId]);
            $ids = array_map('intval', $siblings->fetchAll(PDO::FETCH_COLUMN));
            $position = array_search($categoryId, $ids, true);
            $target = $direction === 'up' ? (int) $position - 1 : (int) $position + 1;
            if ($position === false || !isset($ids[$target])) return Response::redirect('/admin/categorias');
            [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE categories SET sort_order=? WHERE id=?');
            foreach ($ids as $index => $siblingId) $update->execute([($index + 1) * 10, $siblingId]);
            $pdo->commit();
            Session::flash('success', 'Ordem das categorias atualizada.');
        } catch (Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Session::flash('error', 'Não foi possível alterar a ordem.');
        }
        return Response::redirect('/admin/categorias');
    }

    public function destroy(string $id): string
    {
        $categoryId = (int) $id;
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT image_path,(SELECT COUNT(*) FROM categories child WHERE child.parent_id=categories.id) subcategories,(SELECT COUNT(*) FROM product_categories pc WHERE pc.category_id=categories.id) products FROM categories WHERE id=?');
        $statement->execute([$categoryId]);
        $category = $statement->fetch();
        if (!$category) {
            Session::flash('error', 'Categoria não encontrada.');
        } elseif ((int) $category['subcategories'] > 0 || (int) $category['products'] > 0) {
            Session::flash('error', 'Remova ou transfira os produtos e subcategorias antes de excluir esta categoria.');
        } else {
            try {
                $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$categoryId]);
                $imagePath = (string) ($category['image_path'] ?? '');
                if ($imagePath !== '') (new SiteUploadService())->deleteManaged($imagePath);
                Session::flash('success', 'Categoria excluída.');
            } catch (Throwable) {
                Session::flash('error', 'Não foi possível excluir a categoria.');
            }
        }
        return Response::redirect('/admin/categorias');
    }

    private function form(?array $category = null): string
    {
        if ($category === null && func_num_args() > 0) {
            http_response_code(404);
            return $this->page('errors/404', 'layouts/admin', ['pageTitle' => 'Categoria não encontrada', 'path' => 'categoria']);
        }
        $pdo = Database::connection();
        $parents = $pdo->query("WITH RECURSIVE category_tree AS (SELECT id,parent_id,name,0 depth,CAST(name AS CHAR(1000)) path FROM categories WHERE parent_id IS NULL UNION ALL SELECT c.id,c.parent_id,c.name,ct.depth+1,CONCAT(ct.path,' › ',c.name) FROM categories c JOIN category_tree ct ON ct.id=c.parent_id) SELECT * FROM category_tree ORDER BY path")->fetchAll();
        if ($category) {
            $descendants = $pdo->prepare("WITH RECURSIVE blocked AS (SELECT id FROM categories WHERE id=? UNION ALL SELECT c.id FROM categories c JOIN blocked b ON b.id=c.parent_id) SELECT id FROM blocked");
            $descendants->execute([(int) $category['id']]);
            $blocked = array_map('intval', $descendants->fetchAll(PDO::FETCH_COLUMN));
            $parents = array_values(array_filter($parents, static fn(array $parent): bool => !in_array((int) $parent['id'], $blocked, true)));
            $counts = $pdo->prepare('SELECT (SELECT COUNT(DISTINCT product_id) FROM product_categories WHERE category_id=?) products_count,(SELECT COUNT(*) FROM categories WHERE parent_id=?) subcategories_count');
            $counts->execute([(int) $category['id'], (int) $category['id']]);
            $category = array_merge($category, $counts->fetch() ?: []);
            $parentPath = '';
            foreach ($parents as $parent) if ((int) $parent['id'] === (int) ($category['parent_id'] ?? 0)) $parentPath = (string) $parent['path'];
            $category['path'] = $parentPath !== '' ? $parentPath . ' › ' . $category['name'] : $category['name'];
        }
        return $this->page('admin/categories/form', 'layouts/admin', [
            'pageTitle' => $category ? 'Editar categoria' : 'Criar categoria',
            'category' => $category,
            'parents' => $parents,
        ]);
    }

    /** @return array<string,mixed> */
    private function validatedInput(): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('Informe um nome com até 150 caracteres.');
        $slug = Str::slug((string) ($_POST['slug'] ?? '') ?: $name);
        if ($slug === '' || mb_strlen($slug) > 170) throw new RuntimeException('Não foi possível gerar um slug válido para a categoria.');
        $status = (string) ($_POST['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive'], true)) throw new RuntimeException('Selecione um status válido.');
        $limit = static fn(string $field, int $maximum): ?string => (($value = trim((string) ($_POST[$field] ?? ''))) === '' ? null : mb_substr($value, 0, $maximum));
        return [
            'parent_id' => (int) ($_POST['parent_id'] ?? 0) ?: null,
            'name' => $name,
            'slug' => $slug,
            'description' => $limit('description', 10000),
            'support_text' => $limit('support_text', 300),
            'meta_title' => $limit('meta_title', 190),
            'meta_description' => $limit('meta_description', 500),
            'sort_order' => max(-9999, min(999999, (int) ($_POST['sort_order'] ?? 0))),
            'show_in_menu' => !empty($_POST['show_in_menu']) ? 1 : 0,
            'show_in_home' => !empty($_POST['show_in_home']) ? 1 : 0,
            'is_featured' => !empty($_POST['is_featured']) ? 1 : 0,
            'allow_products' => !empty($_POST['allow_products']) ? 1 : 0,
            'customer_visible' => !empty($_POST['customer_visible']) ? 1 : 0,
            'status' => $status,
        ];
    }

    private function assertParentExists(?int $parentId): void
    {
        if ($parentId === null) return;
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM categories WHERE id=?');
        $statement->execute([$parentId]);
        if ((int) $statement->fetchColumn() === 0) throw new RuntimeException('A categoria pai selecionada não existe.');
    }

    private function assertUniqueSlug(string $slug, ?int $exceptId = null): void
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM categories WHERE slug=? AND (? IS NULL OR id<>?)');
        $statement->execute([$slug, $exceptId, $exceptId]);
        if ((int) $statement->fetchColumn() > 0) throw new RuntimeException('Este slug já está sendo utilizado por outra categoria.');
    }

    private function hasImageUpload(): bool
    {
        return isset($_FILES['image']) && is_array($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    private function isDescendant(int $categoryId, int $candidateParentId): bool
    {
        if ($categoryId === $candidateParentId) return true;
        $statement = Database::connection()->prepare("WITH RECURSIVE descendants AS (SELECT id FROM categories WHERE parent_id=? UNION ALL SELECT c.id FROM categories c JOIN descendants d ON d.id=c.parent_id) SELECT COUNT(*) FROM descendants WHERE id=?");
        $statement->execute([$categoryId, $candidateParentId]);
        return (int) $statement->fetchColumn() > 0;
    }
}
