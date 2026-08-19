<?php
$editing = !empty($product);
$variant = $variants[0] ?? [];
$value = static fn(string $key, mixed $default = ''): mixed => $product[$key] ?? $default;
$variantValue = static fn(string $key, mixed $default = ''): mixed => $variant[$key] ?? $default;
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$status = (string) $value('status', 'draft');
$imageMedia = array_values(array_filter($media, static fn(array $item): bool => $item['resource_type'] === 'image'));
$initialMediaOrder = array_map(static fn(array $item): string => 'existing:' . $item['id'], $imageMedia);
$coverMedia = current(array_filter($imageMedia, static fn(array $item): bool => (bool) $item['is_cover'])) ?: ($imageMedia[0] ?? null);
$initialMediaCover = $coverMedia ? 'existing:' . $coverMedia['id'] : '';
$tagTypeLabels = ['audience'=>'Público','material'=>'Material','feature'=>'Características','occasion'=>'Ocasião de uso','style'=>'Estilo','collection'=>'Coleções e cores','commercial'=>'Condição comercial'];
$tagGroups = [];
foreach ($tags as $availableTag) $tagGroups[$availableTag['type']][] = $availableTag;
$storedPrimaryCategoryId = (int) $value('primary_category_id', 0);
$categoryById = array_column($categories, null, 'id');
$categoryParentIds = array_values(array_unique(array_map('intval', array_filter(array_column($categories, 'parent_id'), static fn(mixed $id): bool => $id !== null))));
$primaryCategoryPath = $categoryById[$storedPrimaryCategoryId]['path'] ?? '';
$primaryCategoryIsSelectable = $storedPrimaryCategoryId > 0 && $primaryCategoryPath !== '' && !in_array($storedPrimaryCategoryId, $categoryParentIds, true) && (bool) ($categoryById[$storedPrimaryCategoryId]['allow_products'] ?? true);
$primaryCategoryId = $primaryCategoryIsSelectable ? $storedPrimaryCategoryId : 0;
$primaryCategoryNeedsCompletion = $storedPrimaryCategoryId > 0 && $primaryCategoryPath !== '' && !$primaryCategoryIsSelectable;
$additionalCategoryIds = array_values(array_slice(array_filter($selectedCategories, static fn(int $id): bool => $id !== $storedPrimaryCategoryId), 0, 3));
$categoryJsonFlags = $jsonFlags | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$descriptionHtml = rich_text_html($value('description'));
$seoTitleValue = trim((string) ($seo['title'] ?? '')) ?: trim((string) $value('name'));
$seoDescriptionValue = trim((string) ($seo['description'] ?? '')) ?: trim((string) $value('short_description'));
$seoKeywordsValue = trim((string) ($seo['keywords'] ?? ''));
if ($seoKeywordsValue === '' && trim((string) $value('name')) !== '') {
    $seoKeywordsValue = implode(', ', array_slice(array_filter(explode('-', \App\Support\Str::slug((string) $value('name'))), static fn(string $token): bool => mb_strlen($token) >= 2), 0, 10));
}
?>

<form
    class="product-editor"
    method="post"
    action="<?= e($editing ? url('/vendedor/produtos/' . $product['id']) : url('/vendedor/produtos')) ?>"
    data-product-editor
    data-cloud-name="<?= e($cloudinary['cloudName']) ?>"
    data-upload-preset="<?= e($cloudinary['uploadPreset']) ?>"
    data-cloud-folder="tuffer/products"
>
    <?= csrf_field() ?>
    <?php if ($editing): ?>
        <input type="hidden" name="_method" value="PUT">
        <input type="hidden" name="variant_id" value="<?= (int) $variantValue('id', 0) ?>">
    <?php endif; ?>
    <input type="hidden" name="variants_json" value="<?= e(json_encode($variants, $jsonFlags)) ?>" data-variants-json>
    <input type="hidden" name="wholesale_tiers_json" value="<?= e(json_encode($tiers, $jsonFlags)) ?>" data-wholesale-json>
    <input type="hidden" name="specifications_json" value="<?= e(json_encode($specifications, $jsonFlags)) ?>" data-specifications-json>
    <input type="hidden" name="shipping_rules_json" value="<?= e(json_encode($shippingRules, $jsonFlags)) ?>" data-shipping-json>
    <input type="hidden" name="media_payload" value="[]" data-media-payload>
    <input type="hidden" name="media_order" value="<?= e(json_encode($initialMediaOrder, $jsonFlags)) ?>" data-media-order>
    <input type="hidden" name="media_cover" value="<?= e($initialMediaCover) ?>" data-media-cover>

    <?php if ($editing && (in_array($product['moderation_status'] ?? '', ['changes_requested','rejected'], true) || !empty($product['platform_paused']))): ?>
        <div class="product-moderation-notice"><strong><?= ($product['moderation_status'] ?? '') === 'rejected' ? 'Anúncio rejeitado pela moderação' : 'Este anúncio precisa de atenção' ?></strong><p><?= e($product['moderation_reason'] ?: 'A publicação foi pausada pela administração. Revise o cadastro antes de solicitar uma nova análise.') ?></p><small>Corrija os pontos indicados e salve o produto. A equipe administrativa fará uma nova revisão.</small></div>
    <?php endif; ?>

    <header class="product-editor__header">
        <div>
            <span class="eyebrow"><?= e($currentStore['name']) ?> · CATÁLOGO</span>
            <h2><?= $editing ? 'Editar produto' : 'Novo produto' ?></h2>
            <p><?= $editing ? 'Última atualização em ' . date('d/m/Y \à\s H:i', strtotime((string) $product['updated_at'])) : 'Crie um anúncio completo para varejo e atacado.' ?></p>
        </div>
        <div class="product-editor__completion"><strong data-quality-percent>0%</strong><span>preenchido</span></div>
        <div class="product-editor__top-actions">
            <?php if ($editing): ?><a class="button button--secondary" href="<?= e(url('/produto/' . $product['slug'] . '?preview=1')) ?>" target="_blank">Visualizar</a><?php endif; ?>
            <button class="button button--secondary" type="submit" name="save_action" value="draft">Salvar rascunho</button>
            <button class="button button--primary" type="submit" name="save_action" value="publish">Publicar produto</button>
        </div>
    </header>

    <div class="product-editor__layout">
        <nav class="product-editor__steps" aria-label="Etapas do produto">
            <?php foreach ([
                1 => ['Informações básicas', 'Nome, tipo e descrição'],
                2 => ['Preços e venda', 'Varejo e atacado'],
                3 => ['Variações e estoque', 'SKUs e disponibilidade'],
                4 => ['Imagens e vídeo', 'Galeria do produto'],
                5 => ['Frete e dimensões', 'Embalagem e faixas'],
                6 => ['Organização e SEO', 'Busca e especificações'],
                7 => ['Revisão e publicação', 'Confira e publique'],
            ] as $number => [$label, $caption]): ?>
                <button type="button" class="<?= $number === 1 ? 'is-active' : '' ?>" data-product-step-button="<?= $number ?>">
                    <span><?= $number ?></span><span><strong><?= e($label) ?></strong><small><?= e($caption) ?></small></span><i aria-hidden="true">✓</i>
                </button>
            <?php endforeach; ?>
        </nav>

        <main class="product-editor__content">
            <section class="product-form-section is-active" data-product-step="1">
                <header class="product-form-section__header"><span>1</span><div><h3>Informações básicas</h3><p>Identifique o produto com clareza e ajude o cliente a encontrá-lo.</p></div></header>
                <div class="product-form-grid">
                    <label class="product-field product-field--full">Nome do produto <span class="field-hint">Use tipo, material e quantidade.</span><input name="name" maxlength="120" required value="<?= e($value('name')) ?>" placeholder="Ex.: Kit com 10 Cuecas Boxer em Microfibra" data-product-name><small><span data-name-count><?= mb_strlen((string) $value('name')) ?></span>/120 caracteres</small></label>
                    <fieldset class="product-field product-field--full product-type-options"><legend>Tipo do produto</legend>
                        <?php foreach (['simple' => ['Produto simples', 'Um único SKU'], 'variable' => ['Com variações', 'Cores e tamanhos'], 'kit' => ['Kit de produtos', 'Conjunto de unidades']] as $type => [$label, $caption]): ?>
                            <label><input type="radio" name="product_type" value="<?= $type ?>" <?= $value('product_type', 'simple') === $type ? 'checked' : '' ?>><span><strong><?= e($label) ?></strong><small><?= e($caption) ?></small></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <label class="product-field">Marca<select name="brand_id"><option value="">Sem marca</option><?php foreach ($brands as $brand): ?><option value="<?= $brand['id'] ?>" <?= (int) $value('brand_id', 0) === (int) $brand['id'] ? 'selected' : '' ?>><?= e($brand['name']) ?></option><?php endforeach; ?></select></label>
                    <div class="product-field product-field--full category-field"><span>Categoria principal</span><div class="selected-category <?= $primaryCategoryPath===''?'is-empty':'' ?> <?= $primaryCategoryNeedsCompletion?'is-invalid':'' ?>" data-selected-primary-category><div><span class="selected-category__label"><?= $primaryCategoryNeedsCompletion?'Seleção incompleta':($primaryCategoryPath===''?'Nenhuma categoria selecionada':'Categoria principal') ?></span><strong data-primary-category-path><?= e($primaryCategoryNeedsCompletion?$primaryCategoryPath.' › escolha uma categoria final':($primaryCategoryPath?:'Selecione a categoria mais específica para o produto')) ?></strong></div><button type="button" data-open-category-picker="primary" data-category-parent-hint="<?= $primaryCategoryNeedsCompletion?$storedPrimaryCategoryId:'' ?>"><?= $primaryCategoryNeedsCompletion?'Completar categoria':($primaryCategoryPath===''?'Selecionar categoria':'Alterar') ?></button></div><input type="hidden" name="primary_category_id" value="<?= $primaryCategoryId?:'' ?>" data-quality-category data-primary-category-id></div>
                    <div class="product-field product-field--full additional-categories-field"><span>Categorias adicionais <small>Opcional · até 3</small></span><div class="additional-category-list" data-additional-category-list><?php foreach($additionalCategoryIds as $categoryId):if(!isset($categoryById[$categoryId]))continue;?><span class="additional-category-chip" data-additional-category="<?= (int)$categoryId ?>"><?= e($categoryById[$categoryId]['path']) ?><button type="button" data-remove-additional-category="<?= (int)$categoryId ?>" aria-label="Remover categoria">×</button><input type="hidden" name="categories[]" value="<?= (int)$categoryId ?>"></span><?php endforeach;?><button class="add-category-button" type="button" data-open-category-picker="additional" <?= count($additionalCategoryIds)>=3?'hidden':'' ?>>+ Adicionar categoria</button></div></div>
                    <label class="product-field product-field--full">Descrição curta<input name="short_description" maxlength="500" value="<?= e($value('short_description')) ?>" placeholder="Resumo que aparece nos cards e resultados de busca." data-product-summary></label>
                    <div class="product-field product-field--full"><span>Descrição completa</span><div class="rich-text-editor" data-rich-text-editor><div class="rich-text-toolbar" role="toolbar" aria-label="Formatação da descrição"><button type="button" data-rich-command="bold" title="Negrito"><strong>B</strong></button><button type="button" data-rich-command="italic" title="Itálico"><em>I</em></button><button type="button" data-rich-command="underline" title="Sublinhado"><u>U</u></button><button type="button" data-rich-block="h2" title="Título">Título</button><button type="button" data-rich-block="h3" title="Subtítulo">Subtítulo</button><button type="button" data-rich-command="insertUnorderedList" title="Lista com marcadores">• Lista</button><button type="button" data-rich-command="insertOrderedList" title="Lista numerada">1. Lista</button><button type="button" data-rich-link title="Adicionar link">Link</button><button type="button" data-rich-command="removeFormat" title="Limpar formatação">Limpar</button></div><div class="rich-text-content" contenteditable="true" role="textbox" aria-multiline="true" data-rich-text-content data-placeholder="Apresente benefícios, composição, cuidados e diferenciais."><?= $descriptionHtml ?></div><textarea name="description" hidden data-quality-description data-rich-text-input><?= e($value('description')) ?></textarea></div><small>Use títulos, listas e destaques para facilitar a leitura.</small></div>
                    <details class="product-advanced product-field--full"><summary>Configurações avançadas</summary><div class="product-advanced__grid"><label>URL do produto <span data-slug-preview><?= e(url('/produto/' . ($value('slug') ?: 'nome-do-produto'))) ?></span><input name="slug" value="<?= e($value('slug')) ?>" placeholder="Gerada automaticamente"></label><label>SKU principal <span>Gerado automaticamente; altere somente se necessário.</span><input name="sku" value="<?= e($value('sku')) ?>" placeholder="Gerado ao salvar" data-product-sku></label><label>Código de barras / EAN <span>Opcional.</span><input name="barcode" value="<?= e($variantValue('barcode')) ?>" inputmode="numeric" placeholder="7890000000000"></label></div></details>
                </div>
                <footer class="product-step-actions"><span></span><button type="button" class="button button--primary" data-next-product-step>Continuar para preços →</button></footer>
            </section>

            <section class="product-form-section" data-product-step="2" hidden>
                <header class="product-form-section__header"><span>2</span><div><h3>Preços e modalidades de venda</h3><p>Ative varejo, atacado ou os dois formatos.</p></div></header>
                <div class="sale-mode-grid">
                    <label class="sale-mode-card"><input type="checkbox" name="retail_enabled" value="1" <?= $value('retail_enabled', 1) ? 'checked' : '' ?> data-sale-mode="retail"><span><b>Varejo</b><small>Venda por unidade</small></span></label>
                    <label class="sale-mode-card"><input type="checkbox" name="wholesale_enabled" value="1" <?= $value('wholesale_enabled', 0) ? 'checked' : '' ?> data-sale-mode="wholesale"><span><b>Atacado</b><small>Faixas por quantidade</small></span></label>
                </div>
                <div class="product-subsection" data-retail-fields><h4>Preço de varejo</h4><div class="product-form-grid product-form-grid--three">
                    <label class="product-field">Preço normal <span class="money-input"><b>R$</b><input type="number" step="0.01" min="0.01" name="price" value="<?= e($variantValue('price')) ?>" required data-retail-price></span></label>
                    <label class="product-field">Preço promocional <span class="money-input"><b>R$</b><input type="number" step="0.01" min="0" name="promotional_price" value="<?= e($variantValue('promotional_price')) ?>" data-promo-price></span></label>
                    <label class="product-field">Custo do produto <span class="money-input"><b>R$</b><input type="number" step="0.01" min="0" name="cost_price" value="<?= e($variantValue('cost_price')) ?>" data-cost-price></span><small>Visível somente para sua loja.</small></label>
                </div><div class="price-insights"><span>Margem estimada <strong data-margin>—</strong></span><span>Desconto aplicado <strong data-discount>—</strong></span></div></div>
                <div class="product-subsection" data-wholesale-fields><div class="subsection-heading"><div><h4>Preços por quantidade</h4><p>Quanto maior o pedido, menor pode ser o preço unitário.</p></div><button type="button" class="text-button" data-add-wholesale>+ Adicionar faixa</button></div><div class="dynamic-rows" data-wholesale-rows></div>
                    <div class="product-form-grid product-form-grid--three"><label class="product-field">Mínimo para atacado<input type="number" min="1" name="wholesale_min_quantity" value="<?= e($value('wholesale_min_quantity', 10)) ?>"></label><label class="product-field">Máximo por pedido<input type="number" min="1" name="maximum_order_quantity" value="<?= e($value('maximum_order_quantity')) ?>" placeholder="Sem limite"></label><label class="product-field">Preço atacadista base <span class="money-input"><b>R$</b><input type="number" step="0.01" min="0" name="wholesale_price" value="<?= e($variantValue('wholesale_price')) ?>"></span></label></div>
                    <label class="product-check"><input type="checkbox" name="allow_variant_mix" value="1" <?= $value('allow_variant_mix', 1) ? 'checked' : '' ?>><span><strong>Permitir misturar cores e tamanhos</strong><small>As variações contam juntas para atingir a faixa.</small></span></label>
                </div>
                <footer class="product-step-actions"><button type="button" class="text-button" data-previous-product-step>← Voltar</button><button type="button" class="button button--primary" data-next-product-step>Continuar para estoque →</button></footer>
            </section>

            <section class="product-form-section" data-product-step="3" hidden>
                <header class="product-form-section__header"><span>3</span><div><h3>Variações e estoque</h3><p>Controle disponibilidade geral ou por combinação.</p></div></header>
                <fieldset class="stock-control"><legend>Controle de estoque</legend><label><input type="radio" name="stock_control" value="shared" <?= $value('stock_control', 'shared') === 'shared' ? 'checked' : '' ?>><span><strong>Estoque compartilhado</strong><small>Varejo e atacado usam o mesmo saldo.</small></span></label><label><input type="radio" name="stock_control" value="separate" <?= $value('stock_control') === 'separate' ? 'checked' : '' ?>><span><strong>Estoques separados</strong><small>Reserve quantidades por canal.</small></span></label></fieldset>
                <div data-simple-stock><div class="product-form-grid product-form-grid--three"><label class="product-field">Estoque disponível<input type="number" min="0" name="stock" value="<?= e($variantValue('stock', 0)) ?>" data-quality-stock></label><label class="product-field">Estoque mínimo<input type="number" min="0" name="minimum_quantity" value="<?= e($variantValue('minimum_quantity', 5)) ?>"></label><label class="product-check product-check--compact"><input type="checkbox" name="allow_backorder" value="1" <?= $value('allow_backorder') ? 'checked' : '' ?>><span><strong>Vender sem estoque</strong><small>Aceitar encomendas.</small></span></label></div><div class="product-form-grid" data-separated-stock><label class="product-field">Reserva para varejo<input type="number" min="0" name="retail_stock" value="<?= e($variantValue('retail_stock', 0)) ?>"></label><label class="product-field">Reserva para atacado<input type="number" min="0" name="wholesale_stock" value="<?= e($variantValue('wholesale_stock', 0)) ?>"></label></div></div>
                <div class="product-subsection" data-variation-builder><div class="subsection-heading"><div><h4>Gerador de variações</h4><p>Separe valores por vírgulas e gere as combinações.</p></div><button type="button" class="button button--secondary" data-generate-variants>Gerar variações</button></div><div class="product-form-grid"><label class="product-field">Tamanhos<input data-attribute-sizes placeholder="P, M, G, GG"></label><label class="product-field">Cores<input data-attribute-colors placeholder="Preto, Azul, Cinza"></label></div><div class="variant-table-wrap"><table class="variant-table"><thead><tr><th>Variação</th><th>SKU</th><th>Varejo</th><th>Atacado</th><th>Estoque</th><th>Status</th><th></th></tr></thead><tbody data-variant-rows></tbody></table></div></div>
                <footer class="product-step-actions"><button type="button" class="text-button" data-previous-product-step>← Voltar</button><button type="button" class="button button--primary" data-next-product-step>Continuar para mídias →</button></footer>
            </section>

            <section class="product-form-section" data-product-step="4" hidden>
                <header class="product-form-section__header"><span>4</span><div><h3>Imagens e vídeo</h3><p>Use imagens quadradas, nítidas e sem bordas artificiais.</p></div></header>
                <div class="media-dropzone" tabindex="0" data-image-dropzone><div class="media-dropzone__icon">＋</div><strong>Adicione as imagens do produto</strong><span>Arraste, selecione ou cole usando <kbd>Ctrl</kbd> + <kbd>V</kbd></span><small>Ajustadas para 1080 × 1080 px sem cortes · JPG, PNG ou WebP · Até 10 imagens</small><input type="file" accept="image/jpeg,image/png,image/webp" multiple hidden data-image-input></div>
                <div class="media-gallery" data-media-gallery>
                    <?php foreach ($imageMedia as $item): $mediaKey = 'existing:' . $item['id']; ?><article class="media-card is-uploaded <?= $initialMediaCover === $mediaKey ? 'is-cover' : '' ?>" draggable="true" data-existing-media="<?= (int) $item['id'] ?>" data-media-key="<?= e($mediaKey) ?>">
                        <button class="media-card__drag" type="button" title="Arraste para reordenar" aria-label="Arraste para reordenar">⠿</button>
                        <img src="<?= e($item['thumbnail_url'] ?: $item['secure_url']) ?>" alt="Imagem do produto">
                        <span class="media-card__cover" data-cover-badge <?= $initialMediaCover === $mediaKey ? '' : 'hidden' ?>>Capa</span>
                        <small data-media-status>Enviada</small>
                        <div class="media-card__actions"><button type="button" data-set-media-cover>Definir capa</button><button type="button" class="is-danger" data-delete-existing-media>Excluir</button></div>
                        <input type="checkbox" name="delete_media[]" value="<?= (int) $item['id'] ?>" hidden data-delete-media>
                    </article><?php endforeach; ?>
                </div>
                <div class="product-video-upload"><div><strong>Vídeo do produto</strong><p>Somente um vídeo MP4, MOV ou WebM, entre 8 segundos e 1 minuto e 20 segundos, com até 100 MB.</p></div><button type="button" class="button button--secondary" data-video-button>Adicionar vídeo</button><input type="file" accept="video/mp4,video/webm,video/quicktime" hidden data-video-input></div>
                <div class="video-preview" data-video-preview><?php foreach ($media as $item): if ($item['resource_type'] !== 'video') continue; ?><video src="<?= e($item['secure_url']) ?>" muted controls></video><div><strong>Vídeo processado</strong><small><?= number_format((float) $item['duration'], 0) ?> segundos</small><label class="media-delete"><input type="checkbox" name="delete_media[]" value="<?= $item['id'] ?>"> Excluir vídeo</label></div><?php endforeach; ?></div>
                <?php if ($cloudinary['cloudName'] === '' || $cloudinary['uploadPreset'] === ''): ?><div class="integration-notice"><strong>Cloudinary ainda não configurado</strong><p>Preencha `CLOUDINARY_CLOUD_NAME` e `CLOUDINARY_UPLOAD_PRESET` no ambiente para enviar novas mídias.</p></div><?php endif; ?>
                <footer class="product-step-actions"><button type="button" class="text-button" data-previous-product-step>← Voltar</button><button type="button" class="button button--primary" data-next-product-step>Continuar para frete →</button></footer>
            </section>

            <section class="product-form-section" data-product-step="5" hidden>
                <header class="product-form-section__header"><span>5</span><div><h3>Frete e embalagem</h3><p>Informe as dimensões do pacote pronto para envio.</p></div></header>
                <div class="shipping-layout"><div class="product-form-grid product-form-grid--three"><label class="product-field">Peso (kg)<input type="number" step="0.001" min="0" name="weight" value="<?= e($value('weight', '0.400')) ?>" data-quality-shipping></label><label class="product-field">Largura (cm)<input type="number" step="0.01" min="0" name="width" value="<?= e($value('width', 20)) ?>"></label><label class="product-field">Altura (cm)<input type="number" step="0.01" min="0" name="height" value="<?= e($value('height', 8)) ?>"></label><label class="product-field">Comprimento (cm)<input type="number" step="0.01" min="0" name="length" value="<?= e($value('length', 28)) ?>"></label><label class="product-field">Quantidade de volumes<input type="number" min="1" name="package_count" value="<?= e($value('package_count', 1)) ?>"></label></div><div class="package-guide" aria-label="Ilustração de embalagem"><span class="package-guide__box">TUFFER</span><i class="is-width">largura</i><i class="is-height">altura</i><i class="is-length">comprimento</i></div></div>
                <div class="shipping-checks"><label class="product-check"><input type="checkbox" name="original_packaging" value="1" <?= $value('original_packaging') ? 'checked' : '' ?>><span><strong>Enviado na embalagem original</strong></span></label><label class="product-check"><input type="checkbox" name="combine_shipping" value="1" <?= $value('combine_shipping', 1) ? 'checked' : '' ?>><span><strong>Permite agrupar com outros produtos</strong></span></label></div>
                <div class="product-subsection"><div class="subsection-heading"><div><h4>Dimensões por quantidade</h4><p>Opcional para kits e pedidos de atacado.</p></div><button type="button" class="text-button" data-add-shipping>+ Adicionar faixa</button></div><div class="dynamic-rows dynamic-rows--shipping" data-shipping-rows></div></div>
                <footer class="product-step-actions"><button type="button" class="text-button" data-previous-product-step>← Voltar</button><button type="button" class="button button--primary" data-next-product-step>Continuar para SEO →</button></footer>
            </section>

            <section class="product-form-section" data-product-step="6" hidden>
                <header class="product-form-section__header"><span>6</span><div><h3>Organização e SEO</h3><p>Estruture detalhes técnicos e a apresentação nos buscadores.</p></div></header>
                <div class="product-subsection"><div class="subsection-heading"><div><h4>Especificações técnicas</h4><p>Material, composição, modelagem e outros atributos.</p></div><button type="button" class="text-button" data-add-specification>+ Adicionar especificação</button></div><div class="dynamic-rows" data-specification-rows></div></div>
                <div class="product-subsection taxonomy-picker"><div class="subsection-heading"><div><h4>Tags do produto</h4><p>Escolha de 3 a 10 características padronizadas. Cores e tamanhos continuam nas variações.</p></div><span data-selected-tag-count><?= count($selectedTags) ?>/10</span></div><?php foreach($tagGroups as $type=>$group):?><div class="taxonomy-picker__section"><strong><?= e($tagTypeLabels[$type]??ucfirst($type)) ?></strong><div class="taxonomy-options"><?php foreach($group as $availableTag):?><label><input type="checkbox" name="tags[]" value="<?= (int)$availableTag['id'] ?>" <?= in_array((int)$availableTag['id'],$selectedTags,true)?'checked':'' ?> data-tag-option><span><?= e($availableTag['name']) ?></span></label><?php endforeach;?></div></div><?php endforeach;?><small>Selos administrativos, como “Loja oficial” e “Produto recomendado”, são aplicados somente pela equipe Tuffer.</small></div>
                <details class="seo-panel" open><summary>SEO e compartilhamento</summary><div class="product-form-grid"><label class="product-field product-field--full">Título para busca<input name="seo_title" maxlength="190" value="<?= e($seoTitleValue) ?>" placeholder="Gerado a partir do nome do produto" data-seo-auto="<?= trim((string) ($seo['title'] ?? '')) === '' ? 'true' : 'false' ?>"></label><label class="product-field product-field--full">Descrição para busca<textarea name="seo_description" maxlength="320" rows="3" data-quality-seo data-seo-auto="<?= trim((string) ($seo['description'] ?? '')) === '' ? 'true' : 'false' ?>"><?= e($seoDescriptionValue) ?></textarea></label><label class="product-field product-field--full">Palavras-chave internas<input name="seo_keywords" value="<?= e($seoKeywordsValue) ?>" placeholder="cueca, boxer, microfibra, plus size" data-seo-auto="<?= trim((string) ($seo['keywords'] ?? '')) === '' ? 'true' : 'false' ?>"></label></div><div class="google-preview"><span><?= e(url('/produto/' . ($value('slug') ?: 'nome-do-produto'))) ?></span><strong data-seo-preview-title><?= e($seoTitleValue ?: 'Título do seu produto') ?></strong><p data-seo-preview-description><?= e($seoDescriptionValue ?: 'A descrição do produto aparecerá aqui.') ?></p></div></details>
                <footer class="product-step-actions"><button type="button" class="text-button" data-previous-product-step>← Voltar</button><button type="button" class="button button--primary" data-next-product-step>Revisar produto →</button></footer>
            </section>

            <section class="product-form-section" data-product-step="7" hidden>
                <header class="product-form-section__header"><span>7</span><div><h3>Revisão e publicação</h3><p>Confira a qualidade do anúncio e escolha quando disponibilizá-lo.</p></div></header>
                <div class="product-review"><article><span>Produto</span><strong data-review-name>Nome não informado</strong><small data-review-type>Produto simples</small></article><article><span>Venda</span><strong data-review-price>Preço não informado</strong><small data-review-sale>Varejo</small></article><article><span>Estoque</span><strong data-review-stock>0 unidades</strong><small>Controle do anúncio</small></article><article><span>Mídias</span><strong data-review-media><?= count(array_filter($media, fn($item) => $item['resource_type'] === 'image')) ?> imagens</strong><small>Vídeo opcional</small></article></div>
                <fieldset class="publication-options"><legend>Status do produto</legend><?php foreach (['draft' => ['Rascunho', 'Ainda não aparece na loja.'], 'active' => ['Publicado', 'Disponível para compra imediatamente.'], 'paused' => ['Pausado', 'Não aparece nas buscas.']] as $valueStatus => [$label, $caption]): ?><label><input type="radio" name="status" value="<?= $valueStatus ?>" <?= $status === $valueStatus ? 'checked' : '' ?>><span><strong><?= e($label) ?></strong><small><?= e($caption) ?></small></span></label><?php endforeach; ?></fieldset>
                <div class="schedule-publication"><label class="product-field">Agendar publicação<input type="datetime-local" name="scheduled_at" value="<?= !empty($value('scheduled_at')) ? date('Y-m-d\TH:i', strtotime((string) $value('scheduled_at'))) : '' ?>"></label><small>Deixe vazio para publicar imediatamente.</small></div>
                <footer class="product-step-actions"><button type="button" class="text-button" data-previous-product-step>← Voltar</button><button type="submit" class="button button--primary" name="save_action" value="publish">Salvar e publicar</button></footer>
            </section>
        </main>

        <aside class="product-quality">
            <div class="quality-ring" data-quality-ring><strong data-quality-percent>0%</strong><span>Qualidade<br>do anúncio</span></div>
            <div class="quality-progress"><i data-quality-progress></i></div>
            <ul>
                <li data-quality-check="name"><span>✓</span> Nome completo</li><li data-quality-check="price"><span>✓</span> Preço preenchido</li><li data-quality-check="stock"><span>✓</span> Estoque informado</li><li data-quality-check="shipping"><span>✓</span> Frete configurado</li><li data-quality-check="category"><span>✓</span> Categoria escolhida</li><li data-quality-check="description"><span>✓</span> Boa descrição</li><li data-quality-check="media"><span>✓</span> Pelo menos 4 imagens</li><li data-quality-check="seo"><span>✓</span> SEO preenchido</li>
            </ul>
            <p data-quality-tip>Complete os campos para melhorar a qualidade do anúncio.</p>
        </aside>
    </div>

    <script type="application/json" data-category-data><?= json_encode($categories,$categoryJsonFlags) ?></script>
    <div class="category-modal" hidden data-category-modal role="dialog" aria-modal="true" aria-labelledby="category-modal-title"><div class="category-modal__dialog"><header class="category-modal__header"><div><span>ORGANIZAÇÃO DO PRODUTO</span><h2 id="category-modal-title">Escolha a categoria</h2><p>Selecione a opção mais específica para este produto.</p></div><button type="button" data-close-category-modal aria-label="Fechar seletor">×</button></header><div class="category-modal__search"><input type="search" placeholder="Busque por tanga, boxer, meias..." data-category-picker-search></div><nav class="category-modal__breadcrumb" data-category-breadcrumb aria-label="Caminho da categoria"></nav><section class="category-suggestions" data-category-suggestions hidden><strong>Categorias sugeridas pelo nome</strong><div></div></section><div class="category-browser" data-category-browser></div><footer class="category-modal__footer"><div data-category-preview>Nenhuma categoria selecionada</div><button type="button" data-confirm-category disabled>Confirmar categoria</button></footer></div></div>

    <footer class="product-editor__sticky-actions"><span><i></i> Alterações não salvas</span><a href="<?= e(url('/vendedor/produtos')) ?>">Descartar</a><button class="button button--secondary" type="submit" name="save_action" value="draft">Salvar rascunho</button><button class="button button--primary" type="submit" name="save_action" value="publish">Salvar e publicar</button></footer>
</form>
