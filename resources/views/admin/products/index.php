<?php
$query = array_filter([
    'view' => $filters['view'] !== 'all' ? $filters['view'] : null,
    'q' => $filters['q'] ?: null,
    'status' => $filters['status'] ?: null,
    'category_id' => $filters['category_id'] ?: null,
    'store_id' => $filters['store_id'] ?: null,
    'brand_id' => $filters['brand_id'] ?: null,
    'sale_type' => $filters['sale_type'] ?: null,
    'stock' => $filters['stock'] ?: null,
    'quality' => $filters['quality'] ?: null,
    'updated_from' => $filters['updated_from'] ?: null,
    'per_page' => $pagination['perPage'],
], static fn($value): bool => $value !== null && $value !== '');
$catalogUrl = static fn(array $changes = []): string => url('/admin/produtos?' . http_build_query(array_merge($query, $changes)));
$quality = static function (int $score): array {
    if ($score >= 85) return ['Excelente', 'excellent'];
    if ($score >= 65) return ['Bom', 'good'];
    if ($score >= 40) return ['Incompleto', 'incomplete'];
    return ['Crítico', 'critical'];
};
$moderationLabels = ['pending'=>'Pendente','under_review'=>'Em análise','approved'=>'Aprovado','changes_requested'=>'Correção solicitada','rejected'=>'Rejeitado'];
$tabs = ['all'=>'Todos','pending'=>'Pendentes','published'=>'Publicados','problems'=>'Com problemas','paused'=>'Pausados','rejected'=>'Rejeitados','out_of_stock'=>'Sem estoque','reported'=>'Denunciados'];
$tabCounts = ['all'=>'total','pending'=>'pending','published'=>'published','problems'=>'problems','paused'=>'paused','rejected'=>'rejected','out_of_stock'=>'out_of_stock','reported'=>'reported'];
$pages = $pagination['lastPage'] <= 7 ? range(1, $pagination['lastPage']) : array_values(array_unique(array_filter([1,$pagination['page']-1,$pagination['page'],$pagination['page']+1,$pagination['lastPage']], static fn(int $number): bool => $number >= 1 && $number <= $pagination['lastPage'])));
sort($pages);
?>

<div class="dashboard-heading catalog-heading">
    <div><span class="eyebrow">CATÁLOGO GLOBAL</span><h2>Produtos</h2><p>Acompanhe, revise e modere os anúncios publicados pelas lojas.</p></div>
    <div class="catalog-heading__actions">
        <?php if (!empty($permissions['catalog.export'])): ?><a class="button button--secondary" href="<?= e(url('/admin/produtos/exportar?' . http_build_query($query))) ?>">Exportar</a><?php endif; ?>
        <a class="button button--secondary" href="<?= e($catalogUrl(['view'=>'pending','page'=>null])) ?>">Fila de revisão</a>
        <a class="button button--primary" href="<?= e(url('/admin/configuracoes?secao=gerais')) ?>">Configurações do catálogo</a>
    </div>
</div>

<section class="catalog-metrics" aria-label="Resumo do catálogo">
    <a href="<?= e($catalogUrl(['view'=>'all','page'=>null])) ?>"><span>Total de produtos</span><strong><?= number_format((int)($summary['total']??0),0,',','.') ?></strong><small>Em todas as lojas</small></a>
    <a href="<?= e($catalogUrl(['view'=>'pending','page'=>null])) ?>"><span>Pendentes</span><strong><?= number_format((int)($summary['pending']??0),0,',','.') ?></strong><small>Aguardando revisão</small></a>
    <a href="<?= e($catalogUrl(['view'=>'published','page'=>null])) ?>"><span>Publicados</span><strong><?= number_format((int)($summary['published']??0),0,',','.') ?></strong><small>Disponíveis na vitrine</small></a>
    <a href="<?= e($catalogUrl(['view'=>'problems','page'=>null])) ?>"><span>Com problemas</span><strong><?= number_format((int)($summary['problems']??0),0,',','.') ?></strong><small>Precisam de atenção</small></a>
    <a href="<?= e($catalogUrl(['view'=>'out_of_stock','page'=>null])) ?>"><span>Sem estoque</span><strong><?= number_format((int)($summary['out_of_stock']??0),0,',','.') ?></strong><small>Indisponíveis</small></a>
</section>

<nav class="catalog-tabs" aria-label="Filas do catálogo">
    <?php foreach ($tabs as $key=>$label): ?><a class="<?= $filters['view']===$key?'is-active':'' ?>" href="<?= e($catalogUrl(['view'=>$key==='all'?null:$key,'page'=>null])) ?>"><?= e($label) ?><span><?= (int)($summary[$tabCounts[$key]]??0) ?></span></a><?php endforeach; ?>
</nav>

<form class="catalog-filters panel" method="get" action="<?= e(url('/admin/produtos')) ?>">
    <input type="hidden" name="view" value="<?= e($filters['view']) ?>">
    <label class="catalog-search"><span>Buscar</span><input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nome, SKU, EAN, loja ou vendedor"></label>
    <label><span>Status</span><select name="status"><option value="">Todos</option><?php foreach (['active'=>'Publicado pela loja','draft'=>'Rascunho','paused'=>'Pausado pela loja','platform_paused'=>'Pausado pela plataforma','pending'=>'Pendente de análise','under_review'=>'Em análise','approved'=>'Aprovado','changes_requested'=>'Correção solicitada','rejected'=>'Rejeitado','archived'=>'Arquivado'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $filters['status']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label><span>Categoria</span><select name="category_id"><option value="0">Todas</option><?php foreach($categories as $category):?><option value="<?= (int)$category['id'] ?>" <?= $filters['category_id']==(int)$category['id']?'selected':'' ?>><?= e($category['name']) ?></option><?php endforeach;?></select></label>
    <label><span>Loja</span><select name="store_id"><option value="0">Todas</option><?php foreach($stores as $store):?><option value="<?= (int)$store['id'] ?>" <?= $filters['store_id']==(int)$store['id']?'selected':'' ?>><?= e($store['name']) ?></option><?php endforeach;?></select></label>
    <label><span>Marca</span><select name="brand_id"><option value="0">Todas</option><?php foreach($brands as $brand):?><option value="<?= (int)$brand['id'] ?>" <?= $filters['brand_id']==(int)$brand['id']?'selected':'' ?>><?= e($brand['name']) ?></option><?php endforeach;?></select></label>
    <label><span>Tipo de venda</span><select name="sale_type"><option value="">Todos</option><option value="retail" <?= $filters['sale_type']==='retail'?'selected':'' ?>>Varejo</option><option value="wholesale" <?= $filters['sale_type']==='wholesale'?'selected':'' ?>>Atacado</option><option value="both" <?= $filters['sale_type']==='both'?'selected':'' ?>>Varejo e atacado</option></select></label>
    <label><span>Estoque</span><select name="stock"><option value="">Todos</option><option value="available" <?= $filters['stock']==='available'?'selected':'' ?>>Disponível</option><option value="out" <?= $filters['stock']==='out'?'selected':'' ?>>Sem estoque</option></select></label>
    <label><span>Qualidade</span><select name="quality"><option value="">Todas</option><option value="excellent" <?= $filters['quality']==='excellent'?'selected':'' ?>>Excelente</option><option value="good" <?= $filters['quality']==='good'?'selected':'' ?>>Bom</option><option value="incomplete" <?= $filters['quality']==='incomplete'?'selected':'' ?>>Incompleto</option><option value="problem" <?= $filters['quality']==='problem'?'selected':'' ?>>Crítico</option></select></label>
    <label><span>Atualizado desde</span><input type="date" name="updated_from" value="<?= e($filters['updated_from']) ?>"></label>
    <div class="catalog-filter-actions"><a href="<?= e(url('/admin/produtos')) ?>">Limpar filtros</a><button class="button button--primary" type="submit">Filtrar catálogo</button></div>
</form>

<section class="panel catalog-table-panel">
    <header class="catalog-table-toolbar"><p>Exibindo <strong><?= (int)$pagination['from'] ?>–<?= (int)$pagination['to'] ?></strong> de <strong><?= number_format((int)$pagination['total'],0,',','.') ?></strong> produtos</p><form method="get"><?php foreach($query as $key=>$value):if($key==='per_page')continue;?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach;?><label>Por página <select name="per_page" onchange="this.form.submit()"><?php foreach($pageSizes as $size):?><option value="<?= $size ?>" <?= $pagination['perPage']===$size?'selected':'' ?>><?= $size ?></option><?php endforeach;?></select></label></form></header>
    <div class="table-wrap catalog-table"><table><thead><tr><th>Produto</th><th>Loja</th><th>Preço</th><th>Estoque</th><th>Venda</th><th>Qualidade</th><th>Status</th><th>Alertas</th><th>Atualizado</th><th>Ações</th></tr></thead><tbody>
    <?php foreach($products as $product): [$qualityLabel,$qualityClass]=$quality((int)$product['quality_score']); $saleLabel=$product['retail_enabled']&&$product['wholesale_enabled']?'Ambos':($product['wholesale_enabled']?'Atacado':'Varejo'); ?>
        <tr>
            <td><div class="admin-product-cell"><span class="admin-product-thumb"><?php if($product['image_url']):?><img src="<?= e($product['image_url']) ?>" alt="" loading="lazy"><?php else:?><b>Sem foto</b><?php endif;?></span><div><a class="admin-product-name" title="<?= e($product['product_name']) ?>" href="<?= e(url('/admin/produtos/'.$product['product_id'])) ?>"><?= e($product['product_name']) ?></a><small>SKU: <?= e($product['product_sku']?:'Não informado') ?></small><small><?= e($product['category_name']?:'Sem categoria') ?> · <?= (int)$product['variant_count'] ?> variação(ões)</small></div></div></td>
            <td><a class="catalog-store-link" href="<?= e(url('/admin/lojas/'.$product['store_id'].'/editar')) ?>"><?= e($product['store_name']) ?></a><small><?= e($product['seller_document']?:$product['seller_name']) ?></small><small class="store-state store-state--<?= e($product['store_status']) ?>"><?= $product['store_status']==='active'?'Loja aprovada':'Loja suspensa' ?></small></td>
            <td><strong>R$ <?= number_format((float)$product['price'],2,',','.') ?></strong><small>Somente consulta</small></td>
            <td><strong class="stock-value <?= (int)$product['stock']<=0?'is-empty':'' ?>"><?= (int)$product['stock'] ?></strong><small><?= (int)$product['stock']<=0?'Sem estoque':'Disponível' ?></small></td>
            <td><span class="sale-chip"><?= e($saleLabel) ?></span></td>
            <td><a class="quality-score quality-score--<?= e($qualityClass) ?>" href="<?= e(url('/admin/produtos/'.$product['product_id'].'#qualidade')) ?>"><strong><?= (int)$product['quality_score'] ?>%</strong><span><?= e($qualityLabel) ?></span></a></td>
            <td><span class="moderation-status moderation-status--<?= e($product['moderation_status']) ?>"><?= e($moderationLabels[$product['moderation_status']]??$product['moderation_status']) ?></span><small><?= $product['platform_paused']?'Pausado pela administração':(['active'=>'Publicado pela loja','paused'=>'Pausado pela loja','draft'=>'Rascunho','archived'=>'Arquivado'][$product['commercial_status']]??$product['commercial_status']) ?></small></td>
            <td><?php if((int)$product['alert_count']>0):?><a class="alert-count" href="<?= e(url('/admin/produtos/'.$product['product_id'].'#qualidade')) ?>">⚠ <?= (int)$product['alert_count'] ?> alerta(s)</a><?php else:?><span class="no-alerts">✓ Sem alertas</span><?php endif;?><?php if((int)$product['report_count']>0):?><small><?= (int)$product['report_count'] ?> denúncia(s)</small><?php endif;?></td>
            <td><time datetime="<?= e($product['updated_at']) ?>"><?= date('d/m/Y',strtotime($product['updated_at'])) ?></time><small><?= date('H:i',strtotime($product['updated_at'])) ?></small></td>
            <td><div class="catalog-row-actions"><a href="<?= e(url('/admin/produtos/'.$product['product_id'])) ?>">Revisar</a><?php if($product['commercial_status']==='active'&&!$product['platform_paused']):?><a href="<?= e(url('/produto/'.$product['product_slug'])) ?>" target="_blank" rel="noopener">Ver na loja</a><?php endif;?><a href="<?= e(url('/admin/lojas/'.$product['store_id'].'/editar')) ?>">Abrir loja</a></div></td>
        </tr>
    <?php endforeach;?>
    <?php if(!$products):?><tr><td colspan="10"><div class="catalog-empty"><strong>Nenhum produto encontrado</strong><p>Ajuste os filtros ou consulte outra fila do catálogo.</p><a class="button button--secondary" href="<?= e(url('/admin/produtos')) ?>">Limpar filtros</a></div></td></tr><?php endif;?>
    </tbody></table></div>
    <?php if($pagination['lastPage']>1):?><nav class="product-pagination" aria-label="Paginação do catálogo"><?php if($pagination['page']>1):?><a class="product-pagination__direction" href="<?= e($catalogUrl(['page'=>$pagination['page']-1])) ?>">← Anterior</a><?php else:?><span class="product-pagination__direction is-disabled">← Anterior</span><?php endif;?><div><?php $previous=0;foreach($pages as $number):?><?php if($previous&&$number>$previous+1):?><span class="product-pagination__ellipsis">…</span><?php endif;?><a class="<?= $number===$pagination['page']?'is-active':'' ?>" href="<?= e($catalogUrl(['page'=>$number])) ?>"><?= $number ?></a><?php $previous=$number;endforeach;?></div><?php if($pagination['page']<$pagination['lastPage']):?><a class="product-pagination__direction" href="<?= e($catalogUrl(['page'=>$pagination['page']+1])) ?>">Próxima →</a><?php else:?><span class="product-pagination__direction is-disabled">Próxima →</span><?php endif;?></nav><?php endif;?>
</section>
