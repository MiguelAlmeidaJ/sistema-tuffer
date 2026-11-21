<?php
$editing=!empty($brand);
$hasOld=!empty($oldInput);
$value=static fn(string $key,mixed $default=''):mixed=>$hasOld?($oldInput[$key]??$default):($brand[$key]??$default);
$cover=(string)($brand['cover_image_path']??'');
?>
<div class="brand-editor">
  <nav class="brand-editor__breadcrumb"><a href="<?=e(url('/admin'))?>">Administração</a><span>›</span><a href="<?=e(url('/admin/marcas'))?>">Marcas</a><span>›</span><strong><?=$editing?'Editar':'Nova marca'?></strong></nav>
  <div class="dashboard-heading brand-admin-heading">
    <div><span class="eyebrow">CATÁLOGO</span><h2><?=$editing?'Editar marca':'Cadastrar marca'?></h2><p>Nome, identidade visual e exibição no catálogo da Tuffer.</p></div>
    <a href="<?=e(url('/admin/marcas'))?>" class="button button--secondary">Voltar para marcas</a>
  </div>
  <form class="brand-editor__grid" id="brand-editor-form" method="post" enctype="multipart/form-data" action="<?=e($editing?url('/admin/marcas/'.$brand['id']):url('/admin/marcas'))?>">
    <?=csrf_field()?><?php if($editing):?><input type="hidden" name="_method" value="PUT"><?php endif;?>
    <section class="brand-editor__card">
      <h3>Informações da marca</h3>
      <label>Nome da marca<input type="text" name="name" maxlength="150" required placeholder="Ex.: Linda Flor" value="<?=e($value('name'))?>"></label>
      <label>Endereço amigável (slug)<input type="text" name="slug" maxlength="170" placeholder="Gerado automaticamente pelo nome" value="<?=e($value('slug'))?>"><small>Deixe vazio para gerar automaticamente. Evite alterar o slug de uma marca já divulgada.</small></label>
      <label>Status<select name="status"><option value="active" <?=$value('status','active')==='active'?'selected':''?>>Ativa</option><option value="inactive" <?=$value('status','active')==='inactive'?'selected':''?>>Inativa</option></select></label>
    </section>
    <section class="brand-editor__card">
      <h3>Capa da marca</h3><p>Imagem horizontal para a vitrine da página inicial. JPG, PNG ou WebP de até 3 MB.</p>
      <div class="brand-editor__preview">
        <?php if($cover!==''):?><img src="<?=e(upload_asset($cover))?>" alt="Capa atual da marca">
        <?php else:?><span>Nenhuma capa cadastrada</span><?php endif;?>
      </div>
      <label>Selecionar nova capa<input type="file" name="cover" accept="image/jpeg,image/png,image/webp"></label>
      <?php if($cover!==''):?><label class="brand-editor__remove"><input type="checkbox" name="remove_cover" value="1"> Remover capa atual</label><?php endif;?>
      <button type="submit" class="button button--primary">Salvar marca</button>
    </section>
  </form>
</div>