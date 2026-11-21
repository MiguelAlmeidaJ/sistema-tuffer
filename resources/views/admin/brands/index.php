<div class="dashboard-heading brand-admin-heading">
  <div><span class="eyebrow">CATÁLOGO · IDENTIDADE</span><h2>Marcas</h2><p>Gerencie as marcas e as capas exibidas na página inicial.</p></div>
  <a class="button button--primary" href="<?=e(url('/admin/marcas/nova'))?>">+ Nova marca</a>
</div>
<div class="brand-admin-grid">
<?php foreach($brands as $brand):?>
  <article class="brand-admin-card">
    <div class="brand-admin-card__cover">
      <?php if(!empty($brand['cover_image_path'])):?><img src="<?=e(upload_asset($brand['cover_image_path']))?>" alt="" loading="lazy">
      <?php else:?><span>Sem capa</span><?php endif;?>
    </div>
    <div class="brand-admin-card__body">
      <div><h3><?=e((string)$brand['name'])?></h3><small><?=e((string)$brand['slug'])?></small></div>
      <span class="brand-admin-status <?=$brand['status']==='active'?'is-active':''?>"><?=$brand['status']==='active'?'Ativa':'Inativa'?></span>
      <p><?= (int)$brand['products']?> <?= (int)$brand['products']===1?'produto vinculado':'produtos vinculados'?></p>
      <a href="<?=e(url('/admin/marcas/'.$brand['id'].'/editar'))?>" class="button button--secondary">Editar marca →</a>
    </div>
  </article>
<?php endforeach;?>
<?php if(!$brands):?><div class="brand-admin-empty">Nenhuma marca cadastrada. <a href="<?=e(url('/admin/marcas/nova'))?>">Criar a primeira</a>.</div><?php endif;?>
</div>