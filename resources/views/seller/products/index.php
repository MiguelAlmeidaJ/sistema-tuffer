<?php
$filterParams = [
    'status' => $productFilters['status'],
    'q' => $productFilters['q'],
    'type' => $productFilters['type'],
    'stock' => $productFilters['stock'],
    'sort' => $productFilters['sort'],
    'per_page' => $pagination['perPage'],
];
$pageUrl = static fn(int $page): string => url('/vendedor/produtos?' . http_build_query(array_merge($filterParams, ['page' => $page])));
$statusUrl = static fn(string $status): string => url('/vendedor/produtos?' . http_build_query(array_merge($filterParams, ['status' => $status])));

$pages = $pagination['lastPage'] <= 7
    ? range(1, $pagination['lastPage'])
    : array_values(array_unique(array_filter([
        1,
        $pagination['page'] - 1,
        $pagination['page'],
        $pagination['page'] + 1,
        $pagination['lastPage'],
    ], static fn(int $page): bool => $page >= 1 && $page <= $pagination['lastPage'])));
sort($pages);
?>

<div class="dashboard-heading product-list-heading">
    <div><span class="eyebrow">CATÁLOGO · <?= e($currentStore['name']) ?></span><h2>Produtos</h2><p>Gerencie anúncios, preços e estoque desta loja.</p></div>
    <div class="commerce-hero__actions"><?php if ($paymentEnabled): ?><a class="button button--secondary" href="<?= e(url('/vendedor/produtos/exportar')) ?>">Importar / exportar</a><a class="button button--primary" href="<?= e(url('/vendedor/produtos/novo')) ?>">Novo produto</a><?php else: ?><a class="button button--primary" href="<?= e(url('/vendedor/configuracoes/recebimentos')) ?>">Configurar recebimento</a><?php endif; ?></div>
</div>
<?php if (!$paymentEnabled): ?><div class="settings-note"><span>!</span><p>O cadastro e a publicação de produtos estão bloqueados até que o recebedor esteja ativo e o KYC aprovado.</p></div><?php endif; ?>

<nav class="product-filter-tabs" aria-label="Filtrar por situação">
    <?php foreach(['active'=>'Publicados','all'=>'Todos','draft'=>'Rascunhos','paused'=>'Pausados','archived'=>'Arquivados'] as $value=>$label): ?>
        <a href="<?=e($statusUrl($value))?>" class="<?= $productFilters['status']===$value?'is-active':'' ?>" <?= $productFilters['status']===$value?'aria-current="page"':'' ?>><?=e($label)?> <span><?= (int)($statusCounts[$value]??0) ?></span></a>
    <?php endforeach; ?>
</nav>
<form class="product-filter-form" method="get" action="<?=e(url('/vendedor/produtos'))?>">
    <input type="hidden" name="status" value="<?=e($productFilters['status'])?>">
    <input type="hidden" name="per_page" value="<?= (int)$pagination['perPage'] ?>">
    <label class="product-filter-search"><span>Buscar produto ou SKU</span><input name="q" type="search" maxlength="100" value="<?=e($productFilters['q'])?>" placeholder="Digite o nome ou código do produto"></label>
    <label><span>Tipo de produto</span><select name="type">
        <?php foreach(['all'=>'Todos os tipos','simple'=>'Simples','variable'=>'Com variações','kit'=>'Kit'] as $value=>$label): ?><option value="<?=e($value)?>" <?=$productFilters['type']===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?>
    </select></label>
    <label><span>Estoque</span><select name="stock"><option value="all" <?=$productFilters['stock']==='all'?'selected':''?>>Qualquer estoque</option><option value="available" <?=$productFilters['stock']==='available'?'selected':''?>>Com estoque</option><option value="out" <?=$productFilters['stock']==='out'?'selected':''?>>Sem estoque</option></select></label>
    <label><span>Ordenar por</span><select name="sort">
    <?php foreach(['recent'=>'Atualizados recentemente','oldest'=>'Mais antigos','name'=>'Nome A–Z','price_asc'=>'Menor preço','price_desc'=>'Maior preço'] as $value=>$label): ?><option value="<?=e($value)?>" <?=$productFilters['sort']===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?>
    </select></label>
    <button class="product-filter-apply" type="submit">Filtrar</button>
    <a class="product-filter-clear" href="<?=e(url('/vendedor/produtos'))?>">Limpar filtros</a>
</form>
<?php if ($products): ?>
<form id="product-bulk-form" class="product-bulk-bar" method="post" action="<?= e(url('/vendedor/produtos/excluir-em-lote')) ?>" data-product-bulk-form hidden>
    <?= csrf_field() ?>
    <div><span data-product-selected-count>0</span><strong>produto(s) selecionado(s)</strong><button type="button" data-product-selection-clear>Limpar seleção</button></div>
    <div class="product-bulk-actions">
        <label><span>Status</span><select name="status" data-product-bulk-status><option value="">Escolher...</option><option value="active">Publicado</option><option value="paused">Pausado</option><option value="draft">Rascunho</option><option value="archived">Arquivado</option></select></label>
        <button class="button button--secondary" type="submit" formaction="<?= e(url('/vendedor/produtos/status-em-lote')) ?>" data-bulk-action="status">Aplicar status</button>
        <?php if ($transferStores): ?><label><span>Loja destino</span><select name="target_store_id" data-product-target-store><option value="">Escolher...</option><?php foreach ($transferStores as $targetStore): ?><option value="<?= (int) $targetStore['id'] ?>"><?= e($targetStore['name']) ?></option><?php endforeach; ?></select></label>
        <button class="button button--secondary" type="submit" name="transfer_action" value="duplicate" formaction="<?= e(url('/vendedor/produtos/transferir-em-lote')) ?>" data-bulk-action="duplicate">Duplicar</button>
        <button class="button button--secondary" type="submit" name="transfer_action" value="move" formaction="<?= e(url('/vendedor/produtos/transferir-em-lote')) ?>" data-bulk-action="move">Mover</button><?php endif; ?>
        <button class="button button--danger" type="submit" data-bulk-action="delete">Excluir</button>
    </div>
</form>
<?php endif; ?>

<section class="panel product-list-panel">
    <header class="product-list-toolbar">
        <p>Exibindo <strong><?= (int) $pagination['from'] ?>–<?= (int) $pagination['to'] ?></strong> de <strong><?= (int) $pagination['total'] ?></strong> produtos</p>
        <form method="get" action="<?= e(url('/vendedor/produtos')) ?>">
            <?php foreach($productFilters as $filterName=>$filterValue): ?><input type="hidden" name="<?=e($filterName)?>" value="<?=e($filterValue)?>"><?php endforeach; ?>
            <label for="products-per-page">Itens por página</label>
            <select id="products-per-page" name="per_page" onchange="this.form.submit()">
                <?php foreach ($allowedPageSizes as $size): ?><option value="<?= $size ?>" <?= $pagination['perPage'] === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
            </select>
        </form>
    </header>

    <div class="table-wrap product-list-table"><table data-product-list><thead><tr>
        <th class="product-select-cell"><input type="checkbox" data-products-select-all aria-label="Selecionar todos os produtos desta página"></th>
        <th>Produto</th><th>Preço</th><th>Estoque</th><th>Status</th><th>Ações</th>
    </tr></thead><tbody>
    <?php foreach ($products as $product): ?><tr data-product-row>
        <td class="product-select-cell" data-label="Selecionar"><input type="checkbox" name="product_ids[]" value="<?= (int) $product['id'] ?>" form="product-bulk-form" data-product-select aria-label="Selecionar <?= e($product['name']) ?>"></td>
        <td data-label="Produto"><div class="product-list-identity">
            <span class="product-list-thumb"><?php if ($product['image_url']): ?><img src="<?= e($product['image_url']) ?>" alt="" loading="lazy"><?php else: ?>T<?php endif; ?></span>
            <span class="product-list-copy"><strong title="<?= e($product['name']) ?>"><?= e($product['name']) ?></strong><small class="table-subtitle"><?= e($product['sku'] ?: '—') ?> · <?= $product['product_type'] === 'variable' ? 'Com variações' : ($product['product_type'] === 'kit' ? 'Kit' : 'Simples') ?></small></span>
        </div></td>
        <td data-label="Preço"><span class="product-list-price">A partir de <strong>R$ <?= number_format((float) $product['price'], 2, ',', '.') ?></strong></span></td>
        <td data-label="Estoque">
            <?php if ($product['product_type'] === 'simple'): ?><form class="stock-form" method="post" action="<?= e(url('/vendedor/produtos/' . $product['id'] . '/estoque')) ?>"><?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= $product['variant_id'] ?>"><input type="number" min="0" name="quantity" value="<?= (int) $product['stock'] ?>" aria-label="Quantidade em estoque"><button title="Salvar estoque" aria-label="Salvar estoque">✓</button></form>
            <?php else: ?><a class="text-link" href="<?= e(url('/vendedor/produtos/' . $product['id'] . '/editar')) ?>"><?= (int) $product['stock'] ?> unidades</a><?php endif; ?>
        </td>
        <td data-label="Status"><?php $status = $product['status']; require dirname(__DIR__, 2) . '/components/dashboard/status-badge.php'; ?><?php if (!empty($product['platform_paused'])): ?><small class="seller-moderation-state is-paused">Pausado pela plataforma</small><?php elseif (($product['moderation_status'] ?? '') === 'changes_requested'): ?><small class="seller-moderation-state">Correção solicitada</small><?php elseif (($product['moderation_status'] ?? '') === 'pending'): ?><small class="seller-moderation-state">Aguardando auditoria</small><?php endif; ?></td>
        <td data-label="Ações"><div class="product-action-buttons">
            <button type="button" class="product-action-button" data-product-quick-price
                data-quick-url="<?=e(url('/vendedor/produtos/'.(int)$product['id'].'/preco-rapido'))?>"
                data-quick-name="<?=e($product['name'])?>"
                data-quick-price="<?=e($product['regular_price']??'')?>"
                data-quick-promo="<?=e($product['promotional_price']??'')?>"
                data-quick-wholesale="<?=e($product['wholesale_price']??'')?>"
                data-quick-wholesale-enabled="<?=!empty($product['wholesale_enabled'])?'1':'0'?>"
                title="Alterar preços rapidamente" aria-label="Alterar preços de <?=e($product['name'])?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12.5 12.5 20a2 2 0 0 1-2.8 0l-6.2-6.2a2 2 0 0 1-.6-1.4V4a1 1 0 0 1 1-1h8.4a2 2 0 0 1 1.4.6l6.3 6.1a2 2 0 0 1 0 2.8Z"/><circle cx="8" cy="8" r="1.5"/></svg>
            </button>
            <button type="button" class="product-action-button" data-product-quick-dimensions
                data-quick-url="<?=e(url('/vendedor/produtos/'.(int)$product['id'].'/dimensoes-rapidas'))?>"
                data-quick-name="<?=e($product['name'])?>"
                data-quick-weight="<?=e($product['weight']??'')?>"
                data-quick-width="<?=e($product['width']??'')?>"
                data-quick-height="<?=e($product['height']??'')?>"
                data-quick-length="<?=e($product['length']??'')?>"
                title="Alterar peso e dimensões" aria-label="Alterar dimensões de <?=e($product['name'])?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8v3m4-3v5m4-5v3m4-3v5M7 17h10"/></svg>
            </button>
            <a class="product-action-button" href="<?= e(url('/vendedor/produtos/' . $product['id'] . '/editar')) ?>" title="Editar produto" aria-label="Editar <?= e($product['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l11-11-4-4L4 16v4Zm10-14 4 4m-2-6 2-2 4 4-2 2"/></svg></a>
            <form method="post" action="<?= e(url('/vendedor/produtos/' . $product['id'])) ?>" onsubmit="return confirm('Excluir este produto?')"><?= csrf_field() ?><input type="hidden" name="_method" value="DELETE"><button class="product-action-button product-action-button--danger" title="Excluir produto" aria-label="Excluir <?= e($product['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg></button></form>
        </div></td>
    </tr><?php endforeach; ?>
    </tbody></table><?php if (!$products): ?><div class="product-filter-empty"><h3>Nenhum produto encontrado</h3><p>Não há produtos para os filtros selecionados. Tente outra situação ou limpe a pesquisa.</p><a href="<?=e(url('/vendedor/produtos?status=all'))?>">Ver todos os produtos</a></div><?php endif; ?></div>

    <?php if ($pagination['lastPage'] > 1): ?><nav class="product-pagination" aria-label="Paginação dos produtos">
        <?php if ($pagination['page'] > 1): ?><a class="product-pagination__direction" href="<?= e($pageUrl($pagination['page'] - 1)) ?>" rel="prev">← Anterior</a><?php else: ?><span class="product-pagination__direction is-disabled">← Anterior</span><?php endif; ?>
        <div><?php $previousPage = 0; foreach ($pages as $page): ?>
            <?php if ($previousPage && $page > $previousPage + 1): ?><span class="product-pagination__ellipsis">…</span><?php endif; ?>
            <a href="<?= e($pageUrl($page)) ?>" class="<?= $page === $pagination['page'] ? 'is-active' : '' ?>" <?= $page === $pagination['page'] ? 'aria-current="page"' : '' ?>><?= $page ?></a>
        <?php $previousPage = $page; endforeach; ?></div>
        <?php if ($pagination['page'] < $pagination['lastPage']): ?><a class="product-pagination__direction" href="<?= e($pageUrl($pagination['page'] + 1)) ?>" rel="next">Próxima →</a><?php else: ?><span class="product-pagination__direction is-disabled">Próxima →</span><?php endif; ?>
    </nav><?php endif; ?>
</section>

<?php $productQuickReturnQuery=http_build_query(array_merge($filterParams,['page'=>$pagination['page']])); ?>
<dialog class="product-quick-dialog" data-product-quick-dialog="price" aria-labelledby="quick-price-title">
    <div class="product-quick-dialog__head"><div><span>EDIÇÃO RÁPIDA</span><h2 id="quick-price-title">Alterar preços</h2><p data-product-quick-name></p></div><button type="button" data-product-quick-close aria-label="Fechar popup">×</button></div>
    <form method="post" data-product-quick-form>
        <?=csrf_field()?>
        <input type="hidden" name="return_query" value="<?=e($productQuickReturnQuery)?>">
        <p class="product-quick-dialog__info">Os valores serão aplicados a todas as variações deste produto.</p>
        <div class="product-quick-dialog__fields">
            <label>Preço normal (R$)<input name="price" type="number" min="0.01" max="99999999.99" step="0.01" required inputmode="decimal"></label>
            <label>Preço promocional (R$)<input name="promotional_price" type="number" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" placeholder="Opcional"></label>
            <label data-product-quick-wholesale-field>Preço de atacado (R$)<input name="wholesale_price" type="number" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" placeholder="Opcional"></label>
        </div>
        <div class="product-quick-dialog__actions"><button type="button" data-product-quick-close>Cancelar</button><button type="submit">Salvar preços</button></div>
    </form>
</dialog>
<dialog class="product-quick-dialog" data-product-quick-dialog="dimensions" aria-labelledby="quick-dimensions-title">
    <div class="product-quick-dialog__head"><div><span>EDIÇÃO RÁPIDA</span><h2 id="quick-dimensions-title">Peso e dimensões</h2><p data-product-quick-name></p></div><button type="button" data-product-quick-close aria-label="Fechar popup">×</button></div>
    <form method="post" data-product-quick-form>
        <?=csrf_field()?>
        <input type="hidden" name="return_query" value="<?=e($productQuickReturnQuery)?>">
        <p class="product-quick-dialog__info">Informe as medidas de uma unidade do produto, usadas na estimativa do frete.</p>
        <div class="product-quick-dialog__fields product-quick-dialog__fields--grid">
            <label>Peso (kg)<input name="weight" type="number" min="0.001" max="30" step="0.001" required inputmode="decimal" placeholder="0,040"></label>
            <label>Largura (cm)<input name="width" type="number" min="0.001" max="100" step="0.001" required inputmode="decimal"></label>
            <label>Altura (cm)<input name="height" type="number" min="0.001" max="100" step="0.001" required inputmode="decimal"></label>
            <label>Comprimento (cm)<input name="length" type="number" min="0.001" max="100" step="0.001" required inputmode="decimal"></label>
        </div>
        <div class="product-quick-dialog__actions"><button type="button" data-product-quick-close>Cancelar</button><button type="submit">Salvar dimensões</button></div>
    </form>
</dialog>
