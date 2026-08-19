<?php
$pageUrl = static fn(int $page): string => url('/vendedor/produtos?' . http_build_query([
    'page' => $page,
    'per_page' => $pagination['perPage'],
]));

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
            <a class="product-action-button" href="<?= e(url('/vendedor/produtos/' . $product['id'] . '/editar')) ?>" title="Editar produto" aria-label="Editar <?= e($product['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l11-11-4-4L4 16v4Zm10-14 4 4m-2-6 2-2 4 4-2 2"/></svg></a>
            <form method="post" action="<?= e(url('/vendedor/produtos/' . $product['id'])) ?>" onsubmit="return confirm('Excluir este produto?')"><?= csrf_field() ?><input type="hidden" name="_method" value="DELETE"><button class="product-action-button product-action-button--danger" title="Excluir produto" aria-label="Excluir <?= e($product['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg></button></form>
        </div></td>
    </tr><?php endforeach; ?>
    </tbody></table><?php if (!$products): $emptyTitle='Nenhum produto cadastrado'; $emptyText='Crie o primeiro produto desta loja.'; require dirname(__DIR__, 2) . '/components/dashboard/empty-state.php'; endif; ?></div>

    <?php if ($pagination['lastPage'] > 1): ?><nav class="product-pagination" aria-label="Paginação dos produtos">
        <?php if ($pagination['page'] > 1): ?><a class="product-pagination__direction" href="<?= e($pageUrl($pagination['page'] - 1)) ?>" rel="prev">← Anterior</a><?php else: ?><span class="product-pagination__direction is-disabled">← Anterior</span><?php endif; ?>
        <div><?php $previousPage = 0; foreach ($pages as $page): ?>
            <?php if ($previousPage && $page > $previousPage + 1): ?><span class="product-pagination__ellipsis">…</span><?php endif; ?>
            <a href="<?= e($pageUrl($page)) ?>" class="<?= $page === $pagination['page'] ? 'is-active' : '' ?>" <?= $page === $pagination['page'] ? 'aria-current="page"' : '' ?>><?= $page ?></a>
        <?php $previousPage = $page; endforeach; ?></div>
        <?php if ($pagination['page'] < $pagination['lastPage']): ?><a class="product-pagination__direction" href="<?= e($pageUrl($pagination['page'] + 1)) ?>" rel="next">Próxima →</a><?php else: ?><span class="product-pagination__direction is-disabled">Próxima →</span><?php endif; ?>
    </nav><?php endif; ?>
</section>
