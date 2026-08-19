<?php
$catalogAction = $filters['offers'] ? '/ofertas' : '/produtos';
$pageUrl = static function (int $target) use ($catalogAction): string {
    $query = $_GET;
    $query['page'] = $target;
    return url($catalogAction) . '?' . http_build_query($query);
};
?>
<nav class="commerce-breadcrumb container" aria-label="Navegação estrutural">
    <a href="<?= e(url('/')) ?>">Início</a><span>›</span><strong><?= e($heading) ?></strong>
</nav>

<?php if ($fixedCategory): ?><header class="category-page-intro container"><span>CATEGORIA</span><h1><?= e($heading) ?></h1><?php if (!empty($categorySupport)): ?><p><?= e($categorySupport) ?></p><?php endif; ?></header><?php endif; ?>

<?php if ($categories): ?>
    <nav class="category-chips container" aria-label="Categorias">
        <a class="<?= $filters['category'] === '' ? 'is-active' : '' ?>" href="<?= e(url('/produtos')) ?>">Todos</a>
        <?php foreach (array_slice($categories, 0, 10) as $category): ?><a
                class="<?= $filters['category'] === $category['slug'] ? 'is-active' : '' ?>"
                href="<?= e(url('/categoria/' . $category['slug'])) ?>"><?= e($category['name']) ?></a><?php endforeach; ?>
    </nav><?php endif; ?>

<div class="catalog-layout container">
    <aside class="filters" id="catalog-filters">
        <div class="filters__heading"><strong>Filtrar produtos</strong><span class="filters__actions"><a
                href="<?= e(url($catalogAction)) ?>">Limpar</a><a class="filters__close"
                href="#catalog-results">Fechar</a></span></div>
        <form action="<?= e(url($catalogAction)) ?>" method="get">
            <?php if ($fixedCategory): ?><input type="hidden" name="category"
                    value="<?= e($fixedCategory) ?>"><?php endif; ?>
            <label>Buscar<input type="search" name="q" value="<?= e($filters['q']) ?>"
                    placeholder="Nome ou código"></label>
            <?php if (!$fixedCategory): ?><label>Categoria<select name="category">
                        <option value="">Todas as categorias</option><?php foreach ($categories as $category): ?>
                            <option value="<?= e($category['slug']) ?>" <?= $filters['category'] === $category['slug'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?>
                    </select></label><?php endif; ?>
            <label>Loja<select name="store">
                    <option value="">Todas as lojas</option><?php foreach ($stores as $store): ?>
                        <option value="<?= e($store['slug']) ?>" <?= $filters['store'] === $store['slug'] ? 'selected' : '' ?>>
                            <?= e($store['name']) ?></option><?php endforeach; ?>
                </select></label>
            <?php if ($brands): ?><label>Marca<select name="brand">
                        <option value="">Todas as marcas</option><?php foreach ($brands as $brand): ?>
                            <option value="<?= e($brand['slug']) ?>" <?= $filters['brand'] === $brand['slug'] ? 'selected' : '' ?>>
                                <?= e($brand['name']) ?></option><?php endforeach; ?>
                    </select></label><?php endif; ?>
            <fieldset>
                <legend>Faixa de preço</legend>
                <div class="price-range"><label>De<input type="number" name="min_price" min="0" step="1"
                            value="<?= $filters['min_price'] > 0 ? e($filters['min_price']) : '' ?>"
                            placeholder="R$ 0"></label><label>Até<input type="number" name="max_price" min="0" step="1"
                            value="<?= $filters['max_price'] > 0 ? e($filters['max_price']) : '' ?>"
                            placeholder="R$ 500"></label></div>
            </fieldset>
            <input type="hidden" name="sort" value="<?= e($filters['sort']) ?>">
            <button class="button button--dark button--block" type="submit">Aplicar filtros</button>
        </form>
        <div class="filters__trust"><b>Compra protegida</b><span>Ambiente seguro e suporte Tuffer.</span></div>
    </aside>

    <section class="catalog-results" id="catalog-results">
        <div class="results-head">
            <span>Exibindo
                <?= $pagination['total'] ? (($pagination['page'] - 1) * $pagination['perPage']) + 1 : 0 ?>–<?= min($pagination['total'], $pagination['page'] * $pagination['perPage']) ?>
                de <?= (int) $pagination['total'] ?></span>
            <a class="mobile-filters button button--secondary" href="#catalog-filters">Filtros</a>
            <form action="" method="get" class="sort-form">
                <?php foreach ($_GET as $key => $value):
                    if ($key === 'sort' || $key === 'page' || is_array($value))
                        continue; ?><input
                        type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
                <label>Ordenar por<select name="sort" onchange="this.form.submit()">
                        <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Mais recentes</option>
                        <option value="featured" <?= $filters['sort'] === 'featured' ? 'selected' : '' ?>>Destaques</option>
                        <option value="price_asc" <?= $filters['sort'] === 'price_asc' ? 'selected' : '' ?>>Menor preço
                        </option>
                        <option value="price_desc" <?= $filters['sort'] === 'price_desc' ? 'selected' : '' ?>>Maior preço
                        </option>
                        <option value="name" <?= $filters['sort'] === 'name' ? 'selected' : '' ?>>Nome A–Z</option>
                    </select></label>
            </form>
        </div>
        <div class="product-grid catalog-grid">
            <?php if ($products):
                foreach ($products as $product)
                    require dirname(__DIR__, 2) . '/components/public/product-card.php'; else: ?>
                <div class="catalog-empty"><strong>Nenhum produto encontrado.</strong>
                    <p>Altere os filtros ou veja todos os produtos disponíveis.</p><a class="button button--primary"
                        href="<?= e(url('/produtos')) ?>">Ver catálogo completo</a>
                </div><?php endif; ?>
        </div>
        <?php if ($pagination['lastPage'] > 1): ?>
            <nav class="pagination" aria-label="Paginação">
                <?php if ($pagination['page'] > 1): ?><a href="<?= e($pageUrl($pagination['page'] - 1)) ?>">←
                        Anterior</a><?php endif; ?>
                <?php for ($number = max(1, $pagination['page'] - 2); $number <= min($pagination['lastPage'], $pagination['page'] + 2); $number++): ?><a
                        class="<?= $number === $pagination['page'] ? 'is-current' : '' ?>"
                        href="<?= e($pageUrl($number)) ?>"><?= $number ?></a><?php endfor; ?>
                <?php if ($pagination['page'] < $pagination['lastPage']): ?><a
                        href="<?= e($pageUrl($pagination['page'] + 1)) ?>">Próxima →</a><?php endif; ?>
            </nav><?php endif; ?>
    </section>
</div>
