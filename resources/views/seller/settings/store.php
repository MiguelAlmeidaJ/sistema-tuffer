<header class="commerce-hero commerce-hero--settings">
    <div><span class="eyebrow">CENTRAL DA LOJA</span><h2>Configuração da loja</h2><p>Cuide da identidade que os clientes veem e das integrações usadas na operação.</p></div>
    <div class="commerce-hero__actions"><a class="button button--secondary" href="<?= e(url('/loja/' . $currentStore['slug'])) ?>" target="_blank">Visualizar loja ↗</a><span class="status-pill status-pill--<?= e($currentStore['status']) ?>"><?= $currentStore['status'] === 'active' ? 'Loja ativa' : ucfirst($currentStore['status']) ?></span></div>
</header>

<nav class="settings-navigation" aria-label="Configurações">
    <a class="is-active" href="<?= e(url('/vendedor/configuracoes/loja')) ?>"><span>▣</span><strong>Loja</strong><small>Identidade e integrações</small></a>
    <?php if (($authUser['type'] ?? '') === 'seller'): ?><a href="<?= e(url('/vendedor/configuracoes/vendedor')) ?>"><span>◎</span><strong>Vendedor</strong><small>Dados pessoais e empresa</small></a><a href="<?= e(url('/vendedor/configuracoes/recebimentos')) ?>"><span>◇</span><strong>Recebimentos</strong><small>Recebedor e validação</small></a><?php endif; ?>
</nav>

<div class="settings-layout">
    <form class="settings-form" method="post" enctype="multipart/form-data" action="<?= e(url('/vendedor/configuracoes/loja')) ?>" data-store-settings>
        <?= csrf_field() ?><input type="hidden" name="_method" value="PUT">
        <section class="settings-card">
            <header><span>01</span><div><h3>Identidade da loja</h3><p>Informações públicas usadas na vitrine e nas páginas de produto.</p></div></header>
            <div class="settings-grid">
                <label class="settings-field">Nome da loja<input name="name" required minlength="3" value="<?= e($currentStore['name']) ?>" data-store-name><small>Use o nome pelo qual seus clientes reconhecem a marca.</small></label>
                <label class="settings-field">Endereço da loja<div class="locked-input"><span><?= e(url('/loja/' . $currentStore['slug'])) ?></span><b>Bloqueado</b></div><small>O endereço permanente protege seus links e posicionamento.</small></label>
                <label class="settings-field settings-field--full">Descrição pública<textarea name="description" rows="5" maxlength="1000" data-store-description><?= e($currentStore['description'] ?? '') ?></textarea><small><span data-store-description-count><?= mb_strlen((string) ($currentStore['description'] ?? '')) ?></span>/1000 caracteres</small></label>
            </div>
        </section>

        <section class="settings-card">
            <header><span>02</span><div><h3>Identidade visual</h3><p>Envie a logo e o banner; os arquivos serão organizados automaticamente na pasta desta loja.</p></div></header>
            <div class="store-visual-uploads">
                <div class="store-upload-card" data-store-upload="logo">
                    <div class="store-upload-card__preview store-upload-card__preview--logo" data-logo-upload-preview><?php if (!empty($currentStore['logo_url'])): ?><img src="<?= e(upload_asset($currentStore['logo_url'])) ?>" alt="Logo atual"><?php else: ?><b><?= e(mb_substr($currentStore['name'], 0, 1)) ?></b><?php endif; ?></div>
                    <div><strong>Logo da loja</strong><p>PNG, JPG ou WebP · até 5 MB</p><small>Recomendado: imagem quadrada, mínimo 500 × 500 px.</small><div class="store-upload-actions"><label class="button button--secondary">Escolher logo<input type="file" name="logo" accept="image/jpeg,image/png,image/webp" hidden data-store-logo-input></label><?php if (!empty($currentStore['logo_url'])): ?><label class="remove-upload"><input type="checkbox" name="remove_logo" value="1" data-remove-store-logo> Remover atual</label><?php endif; ?></div></div>
                </div>
                <div class="store-upload-card" data-store-upload="banner">
                    <div class="store-upload-card__preview store-upload-card__preview--banner" data-banner-upload-preview><?php if (!empty($currentStore['banner_url'])): ?><img src="<?= e(upload_asset($currentStore['banner_url'])) ?>" alt="Banner atual"><?php else: ?><span>Banner da loja</span><?php endif; ?></div>
                    <div><strong>Banner da vitrine</strong><p>PNG, JPG ou WebP · até 10 MB</p><small>Recomendado: formato horizontal, mínimo 1600 × 500 px.</small><div class="store-upload-actions"><label class="button button--secondary">Escolher banner<input type="file" name="banner" accept="image/jpeg,image/png,image/webp" hidden data-store-banner-input></label><?php if (!empty($currentStore['banner_url'])): ?><label class="remove-upload"><input type="checkbox" name="remove_banner" value="1" data-remove-store-banner> Remover atual</label><?php endif; ?></div></div>
                </div>
            </div>
        </section>

        <section class="settings-card">
            <header><span>03</span><div><h3>Condições do atacado</h3><p>Defina os mínimos gerais exigidos no carrinho empresarial desta loja.</p></div></header>
            <div class="settings-grid">
                <label class="settings-field">Quantidade mínima por pedido<input type="number" min="0" name="wholesale_min_quantity" value="<?= e($currentStore['wholesale_min_quantity'] ?? '') ?>" placeholder="Ex.: 10"><small>Somatório de unidades de todos os produtos desta loja.</small></label>
                <label class="settings-field">Valor mínimo por pedido (R$)<input type="number" min="0" step="0.01" name="wholesale_min_total" value="<?= e($currentStore['wholesale_min_total'] ?? '') ?>" placeholder="Ex.: 500,00"><small>O checkout atacadista valida quantidade e valor.</small></label>
            </div>
        </section>

        <section class="settings-card">
            <header><span>04</span><div><h3>Origem das entregas</h3><p>Informe de onde os pedidos serão enviados para calcular valores e prazos reais.</p></div></header>
            <div class="settings-grid">
                <label class="settings-field">CEP de origem<input name="origin_postal_code" inputmode="numeric" maxlength="9" required data-mask="cep" value="<?= e($shippingOrigin['postal_code'] ?? '') ?>" placeholder="00000-000"><small>Utilizado nas cotações do Melhor Envio.</small></label>
                <label class="settings-field">Rua<input name="origin_street" maxlength="190" required value="<?= e($shippingOrigin['street'] ?? '') ?>" placeholder="Ex.: Avenida Paulista"></label>
                <label class="settings-field">Número<input name="origin_number" maxlength="30" required value="<?= e($shippingOrigin['number'] ?? '') ?>" placeholder="Ex.: 1000"></label>
                <label class="settings-field">Complemento<input name="origin_complement" maxlength="120" value="<?= e($shippingOrigin['complement'] ?? '') ?>" placeholder="Sala, bloco..."></label>
                <label class="settings-field">Bairro<input name="origin_neighborhood" maxlength="120" required value="<?= e($shippingOrigin['neighborhood'] ?? '') ?>" placeholder="Ex.: Bela Vista"></label>
                <label class="settings-field">Cidade<input name="origin_city" maxlength="120" required value="<?= e($shippingOrigin['city'] ?? '') ?>" placeholder="Ex.: São Paulo"></label>
                <label class="settings-field">UF<input name="origin_state" maxlength="2" required value="<?= e($shippingOrigin['state'] ?? '') ?>" placeholder="SP" style="text-transform:uppercase"><small>Use a sigla do estado com duas letras.</small></label>
            </div>
        </section>

        <section class="settings-card">
            <header><span>05</span><div><h3>Frete compartilhado</h3><p>Reaproveite a origem e o contrato de frete de outra loja do mesmo vendedor.</p></div></header>
            <div class="settings-grid">
                <label class="settings-field">Origem e contrato de frete<select name="shipping_source_store_id"><option value="">Configuração própria desta loja</option><?php foreach ($stores as $store): if ((int) $store['id'] === (int) $currentStore['id']) continue; ?><option value="<?= $store['id'] ?>" <?= (int) ($currentStore['shipping_source_store_id'] ?? 0) === (int) $store['id'] ? 'selected' : '' ?>>Usar configuração de <?= e($store['name']) ?></option><?php endforeach; ?></select><small>Compartilha credenciais e regras de envio com outra loja.</small></label>
            </div>
            <?php if (count($stores) === 1): ?><div class="settings-note"><span>i</span><p>Você possui uma única loja. As opções de compartilhamento ficarão disponíveis quando outra loja for adicionada.</p></div><?php endif; ?>
        </section>
        <footer class="settings-savebar"><span><i></i> Revise a prévia antes de salvar</span><button class="button button--primary">Salvar configurações</button></footer>
    </form>

    <aside class="settings-aside">
        <section class="store-live-preview">
            <div class="store-live-preview__banner" data-store-banner-preview <?= !empty($currentStore['banner_url']) ? 'style="background-image:url(\'' . e(upload_asset($currentStore['banner_url'])) . '\')"' : '' ?>><span>PRÉVIA DA VITRINE</span></div>
            <div class="store-live-preview__body"><div class="store-live-preview__logo" data-store-logo-preview><?php if (!empty($currentStore['logo_url'])): ?><img src="<?= e(upload_asset($currentStore['logo_url'])) ?>" alt=""><?php else: ?><b><?= e(mb_substr($currentStore['name'], 0, 1)) ?></b><?php endif; ?></div><span class="verified-dot">✓</span><h3 data-store-name-preview><?= e($currentStore['name']) ?></h3><p data-store-description-preview><?= e($currentStore['description'] ?: 'Adicione uma descrição para apresentar sua loja.') ?></p><small>tuffer.com.br/loja/<?= e($currentStore['slug']) ?></small></div>
        </section>
        <section class="settings-summary"><h3>Resumo da loja</h3><dl><div><dt>Produtos cadastrados</dt><dd><?= (int) $stats['products'] ?></dd></div><div><dt>Produtos publicados</dt><dd><?= (int) $stats['active_products'] ?></dd></div><div><dt>Cupons ativos</dt><dd><?= (int) $stats['coupons'] ?></dd></div><div><dt>Status</dt><dd><?= e(ucfirst($currentStore['status'])) ?></dd></div></dl></section>
        <section class="settings-help"><span>?</span><div><strong>Precisa de ajuda?</strong><p>Confira os formatos recomendados antes de atualizar logo e banner.</p></div></section>
    </aside>
</div>
