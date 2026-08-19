<?php
$editing = !empty($category);
$hasOld = !empty($oldInput);
$value = static fn(string $key, mixed $default = ''): mixed => $hasOld ? ($oldInput[$key] ?? $default) : ($category[$key] ?? $default);
$checked = static fn(string $key, bool $default = false): bool => $hasOld ? !empty($oldInput[$key]) : (isset($category[$key]) ? (bool) $category[$key] : $default);
$name = (string) $value('name', 'Nova categoria');
$slug = (string) $value('slug', 'nova-categoria');
$imagePath = (string) ($category['image_path'] ?? '');
$status = (string) $value('status', 'active');
$parentPreview = 'Categoria principal';
foreach ($parents as $parent) if ((int) $value('parent_id', 0) === (int) $parent['id']) $parentPreview = (string) $parent['path'];
?>
<div class="category-editor" data-category-editor>
    <nav class="category-editor__breadcrumb" aria-label="Navegação estrutural"><a href="<?= e(url('/admin')) ?>">Administração</a><span>›</span><a href="<?= e(url('/admin/categorias')) ?>">Categorias</a><span>›</span><strong><?= $editing ? 'Editar categoria' : 'Nova categoria' ?></strong></nav>
    <header class="category-editor__header">
        <div><span class="eyebrow">CATÁLOGO</span><h2><?= $editing ? 'Editar categoria' : 'Criar categoria' ?></h2><p>Organize a navegação, a apresentação comercial e as regras de uso desta categoria.</p></div>
        <div class="category-editor__actions"><a class="button button--secondary" href="<?= e(url('/admin/categorias')) ?>">Cancelar</a><button class="button button--primary" type="submit" form="category-form">Salvar categoria</button></div>
    </header>

    <form id="category-form" class="category-editor__layout" method="post" enctype="multipart/form-data" action="<?= e($editing ? url('/admin/categorias/' . $category['id']) : url('/admin/categorias')) ?>">
        <?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="_method" value="PUT"><?php endif; ?>
        <div class="category-editor__main">
            <section class="category-form-card">
                <header><span>01</span><div><h3>Informações principais</h3><p>Identificação e posição dentro da hierarquia do catálogo.</p></div></header>
                <div class="category-form-grid">
                    <label class="category-field"><span>Nome</span><input name="name" required maxlength="150" value="<?= e($value('name')) ?>" placeholder="Ex.: Tomara que caia" data-category-name><small>Use um nome direto e fácil de reconhecer.</small></label>
                    <label class="category-field"><span>Slug</span><div class="category-slug-field"><span>/categoria/</span><input name="slug" maxlength="170" value="<?= e($value('slug')) ?>" placeholder="tomara-que-caia" data-category-slug></div><small>Preenchido automaticamente. Pode ser ajustado antes de salvar.</small></label>
                    <label class="category-field"><span>Categoria pai</span><select name="parent_id" data-category-parent><option value="" data-path="Categoria principal">Categoria principal</option><?php foreach ($parents as $parent): ?><option value="<?= (int) $parent['id'] ?>" data-path="<?= e($parent['path']) ?>" <?= (int) $value('parent_id', 0) === (int) $parent['id'] ? 'selected' : '' ?>><?= e($parent['path']) ?></option><?php endforeach; ?></select><small>Defina uma categoria superior ou deixe vazio para categoria principal.</small></label>
                    <label class="category-field"><span>Ordem</span><input type="number" name="sort_order" min="-9999" max="999999" value="<?= e($value('sort_order', 0)) ?>" data-category-order><small>Categorias com menor número aparecem primeiro.</small></label>
                    <label class="category-field"><span>Status</span><select name="status" data-category-status><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ativa</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inativa</option></select><small>Uma categoria inativa deixa de aceitar novas exposições públicas.</small></label>
                </div>
            </section>

            <section class="category-form-card">
                <header><span>02</span><div><h3>Mídia da categoria</h3><p>Imagem comercial usada nos destaques e no carrossel da home.</p></div></header>
                <div class="category-upload" data-category-upload>
                    <div class="category-upload__preview" data-category-image-preview><?php if ($imagePath !== ''): ?><img src="<?= e(upload_asset($imagePath)) ?>" alt="Imagem atual de <?= e($name) ?>"><?php else: ?><span><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><?php endif; ?></div>
                    <div class="category-upload__content"><strong data-category-upload-title><?= $imagePath !== '' ? 'Imagem cadastrada' : 'Adicione uma imagem quadrada' ?></strong><p>JPG, PNG ou WebP de até 2 MB. A imagem será recortada e convertida para WebP em 300 × 300.</p><div><label class="button button--secondary" for="category-image"><?= $imagePath !== '' ? 'Trocar imagem' : 'Enviar imagem' ?></label><input id="category-image" type="file" name="image" accept="image/jpeg,image/png,image/webp" data-category-image-input><button class="category-upload__remove" type="button" data-remove-category-image <?= $imagePath === '' ? 'hidden' : '' ?>>Remover imagem</button></div><small data-category-image-name><?= $imagePath !== '' ? 'Imagem atual da categoria' : 'Nenhum arquivo selecionado' ?></small></div>
                    <input type="hidden" name="remove_image" value="0" data-remove-category-image-value>
                </div>
            </section>

            <section class="category-form-card">
                <header><span>03</span><div><h3>Conteúdo complementar</h3><p>Textos para contextualizar a categoria e melhorar sua apresentação nos buscadores.</p></div></header>
                <div class="category-form-grid">
                    <label class="category-field category-field--full"><span>Descrição</span><textarea name="description" rows="5" maxlength="10000" placeholder="Descreva os produtos e a proposta desta categoria."><?= e($value('description')) ?></textarea></label>
                    <label class="category-field category-field--full"><span>Texto curto de apoio</span><input name="support_text" maxlength="300" value="<?= e($value('support_text')) ?>" placeholder="Ex.: Modelagens que unem sustentação e conforto." data-category-support><small>Texto opcional para vitrines e páginas públicas.</small></label>
                    <label class="category-field"><span>Meta title</span><input name="meta_title" maxlength="190" value="<?= e($value('meta_title')) ?>" placeholder="<?= e($name) ?> | Tuffer"><small>Se vazio, será usado o nome da categoria.</small></label>
                    <label class="category-field"><span>Meta description</span><textarea name="meta_description" rows="3" maxlength="500" placeholder="Resumo para mecanismos de busca."><?= e($value('meta_description')) ?></textarea></label>
                </div>
            </section>

            <section class="category-form-card">
                <header><span>04</span><div><h3>Configurações de exibição</h3><p>Controle onde a categoria aparece e como pode ser utilizada.</p></div></header>
                <div class="category-toggle-grid">
                    <?php foreach ([
                        ['show_in_menu', 'Exibir no menu', 'Inclui a categoria principal na navegação pública.', true],
                        ['show_in_home', 'Exibir na home', 'Prioriza a categoria no carrossel quando ela tiver imagem e produtos.', false],
                        ['is_featured', 'Categoria em destaque', 'Dá prioridade comercial dentro da seleção da home.', false],
                        ['allow_products', 'Permitir produtos', 'Autoriza vendedores a vincular novos produtos a esta categoria.', true],
                        ['customer_visible', 'Visível para clientes', 'Libera a categoria nas buscas e páginas públicas.', true],
                    ] as [$key, $label, $help, $default]): ?>
                        <label class="category-toggle"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= $checked($key, $default) ? 'checked' : '' ?>><span><i></i></span><div><strong><?= e($label) ?></strong><small><?= e($help) ?></small></div></label>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <aside class="category-summary">
            <span class="eyebrow">PRÉVIA DA CATEGORIA</span>
            <div class="category-summary__image" data-category-summary-image><?php if ($imagePath !== ''): ?><img src="<?= e(upload_asset($imagePath)) ?>" alt=""><?php else: ?><span><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><?php endif; ?></div>
            <div class="category-summary__body"><span class="category-summary__status <?= $status === 'active' ? 'is-active' : '' ?>" data-category-status-preview><?= $status === 'active' ? 'Ativa' : 'Inativa' ?></span><h3 data-category-name-preview><?= e($name) ?></h3><p data-category-support-preview><?= e((string) $value('support_text', 'Adicione um texto curto para apresentar esta categoria.')) ?></p><code data-category-slug-preview>/categoria/<?= e($slug) ?></code></div>
            <dl><div><dt>Categoria pai</dt><dd data-category-parent-preview><?= e($parentPreview) ?></dd></div><div><dt>Produtos</dt><dd><?= (int) ($category['products_count'] ?? 0) ?></dd></div><div><dt>Subcategorias</dt><dd><?= (int) ($category['subcategories_count'] ?? 0) ?></dd></div><div><dt>Ordem</dt><dd data-category-order-preview><?= (int) $value('sort_order', 0) ?></dd></div></dl>
            <?php if ($editing && $status === 'active' && (bool) ($category['customer_visible'] ?? true)): ?><a class="category-summary__view" href="<?= e(url('/categoria/' . $category['slug'])) ?>" target="_blank" rel="noopener">Visualizar categoria ↗</a><?php endif; ?>
        </aside>
    </form>

    <?php if ($editing): ?><form class="category-danger" method="post" action="<?= e(url('/admin/categorias/' . $category['id'])) ?>" data-confirm="Excluir esta categoria? Esta ação só será concluída se ela estiver vazia."><?= csrf_field() ?><input type="hidden" name="_method" value="DELETE"><div><strong>Excluir categoria</strong><p>A exclusão só é permitida quando não existem produtos ou subcategorias vinculados.</p></div><button class="button button--danger" type="submit">Excluir categoria</button></form><?php endif; ?>
</div>
