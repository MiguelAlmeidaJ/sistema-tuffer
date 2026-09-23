<?php
$selected = $variants[0];
$currentPrice = (float) ($selected['promotional_price'] ?: $selected['price']);
$regularPrice = (float) $selected['price'];
$discount = $regularPrice > $currentPrice ? (int) round((1 - $currentPrice / $regularPrice) * 100) : 0;
$cover = $media[0] ?? null;
$galleryPreview = array_slice($media, 0, 6);
$hiddenMediaCount = max(0, count($media) - count($galleryPreview));
$wholesalePrice = $selected['wholesale_price'] !== null ? (float) $selected['wholesale_price'] : null;
$wholesaleAvailable = !empty($product['wholesale_enabled']) && $wholesalePrice !== null;
$totalAvailable = array_sum(array_map(static fn(array $variant): int => max(0, (int) $variant['available']), $variants));
$technicalFacts = [
    ['label' => 'SKU', 'value' => (string) ($product['sku'] ?: $selected['sku'])],
    ['label' => 'Tipo', 'value' => ucfirst((string) $product['product_type'])],
];
if (!empty($product['weight'])) {
    $technicalFacts[] = ['label' => 'Peso', 'value' => (string) $product['weight'] . ' kg'];
}
if (!empty($product['width']) && !empty($product['height']) && !empty($product['length'])) {
    $technicalFacts[] = [
        'label' => 'Dimensões',
        'value' => (string) $product['width'] . ' × ' . (string) $product['height'] . ' × ' . (string) $product['length'] . ' cm',
    ];
}
?>
<nav class="commerce-breadcrumb container product-breadcrumb" aria-label="Navegação estrutural">
    <a href="<?= e(url('/')) ?>">Início</a><span>›</span>
    <a href="<?= e(url('/produtos')) ?>">Produtos</a>
    <?php if ($categories): ?><span>›</span><a href="<?= e(url('/categoria/'.$categories[0]['slug'])) ?>"><?= e($categories[0]['name']) ?></a><?php endif; ?>
    <span>›</span><strong><?= e($product['name']) ?></strong>
</nav>

<section class="product-detail product-detail--v2 container">
    <div class="product-gallery product-gallery--v2">
        <div class="product-gallery__thumbs" aria-label="Mídias do produto">
            <?php foreach ($galleryPreview as $index => $item): ?>
                <button type="button" class="<?= $index===0?'is-active':'' ?>" data-product-thumb data-type="<?= e($item['resource_type']) ?>" data-src="<?= e($item['secure_url']) ?>" aria-label="Abrir mídia <?= $index+1 ?>">
                    <?php if ($item['resource_type']==='video'): ?><span class="product-gallery__play">▶</span><?php else: ?><img src="<?= e($item['thumbnail_url'] ?: $item['secure_url']) ?>" alt="Visual <?= $index+1 ?> de <?= e($product['name']) ?>" loading="<?= $index < 3 ? 'eager' : 'lazy' ?>"><?php endif; ?>
                </button>
            <?php endforeach; ?>
            <?php if ($hiddenMediaCount > 0): ?><button type="button" class="product-gallery__more" data-open-product-gallery aria-label="Ver todas as <?= count($media) ?> mídias"><b>+</b><small><?= $hiddenMediaCount ?></small></button><?php endif; ?>
        </div>

        <div class="product-gallery__stage-wrap">
            <div class="product-gallery__main" data-product-stage>
                <?php if ($cover && $cover['resource_type']==='video'): ?>
                    <video src="<?= e($cover['secure_url']) ?>" controls autoplay muted playsinline preload="metadata"></video>
                <?php elseif ($cover): ?>
                    <img src="<?= e($cover['secure_url']) ?>" alt="<?= e($product['name']) ?>" fetchpriority="high">
                <?php else: ?>
                    <span class="product-placeholder product-placeholder--large"><i></i><b>TUFFER</b></span>
                <?php endif; ?>
            </div>
            <?php if (count($media) > 1): ?><button type="button" class="product-gallery__expand" data-open-product-gallery><span>↗</span> Ver galeria</button><?php endif; ?>
            <span class="product-gallery__counter"><?= count($media) ?> <?= count($media)===1?'mídia':'mídias' ?></span>
        </div>
        <span class="gallery-hint"><?= $cover && $cover['resource_type']==='video' ? 'O vídeo começa automaticamente sem som' : 'Use as miniaturas para explorar o produto' ?></span>
    </div>

    <aside class="product-buybox product-buybox--v2" id="comprar">
        <div class="product-buybox__intro">
            <div class="product-meta">
                <a href="<?= e(url('/loja/'.$product['store_slug'])) ?>"><?= e($product['store_name']) ?></a>
                <?php if ($product['brand_name']): ?><span><?= e($product['brand_name']) ?></span><?php endif; ?>
                <span>Cód. <?= e($selected['sku']) ?></span>
            </div>
            <h1><?= e($product['name']) ?></h1>
            <?php if ($product['short_description']): ?><p class="product-lead"><?= e($product['short_description']) ?></p><?php endif; ?>
            <div class="product-rating"><span>Produto novo</span><a href="#detalhes">Ver detalhes e especificações</a></div>
        </div>

        <div class="product-purchase-card">
            <div class="product-price" data-price-box>
                <div data-price-discount-row <?= $discount ? '' : 'hidden' ?>>
                    <del data-price-regular>R$ <?= number_format($regularPrice,2,',','.') ?></del>
                    <b data-price-discount>-<?= $discount ?>%</b>
                </div>
                <strong data-price-current>R$ <?= number_format($currentPrice,2,',','.') ?></strong>
                <span data-price-installment>ou 6x de R$ <?= number_format($currentPrice/6,2,',','.') ?> sem juros</span>
            </div>

            <?php if ($wholesaleAvailable && $wholesaleApproved): ?>
                <div class="wholesale-price-box"><span>PREÇO ATACADISTA</span><strong>A partir de R$ <?=number_format($wholesalePrice,2,',','.')?></strong><small>Mínimo: <?=max(1,(int)$product['wholesale_min_quantity'])?> unidades<?=!empty($product['allow_variant_mix'])?' · pode misturar variações':''?></small><?php if($cartMode!=='wholesale'):?><form method="post" action="<?=e(url('/carrinho/modo'))?>"><?=csrf_field()?><input type="hidden" name="mode" value="wholesale"><input type="hidden" name="return" value="<?=e('/produto/'.$product['slug'])?>"><button type="submit">Comprar no modo atacado →</button></form><?php else:?><b>✓ Você está comprando no atacado</b><?php endif;?></div>
            <?php elseif($wholesaleAvailable): ?>
                <div class="wholesale-price-box wholesale-price-box--locked"><span>ATACADO DISPONÍVEL</span><strong>Preços especiais para empresas</strong><a href="<?=e(Auth::check()?url('/minha-conta/atacado'):url('/entrar'))?>">Solicitar acesso →</a></div>
            <?php endif; ?>

            <form class="buy-form buy-form--v2" action="<?= e(url('/carrinho/adicionar')) ?>" method="post" data-product-buy-form>
                <?= csrf_field() ?>
                <label class="product-field">Escolha a opção
                    <select name="variant_id" data-variant-select>
                        <?php foreach ($variants as $variant):
                            $price=(float)($variant['promotional_price']?:$variant['price']);
                            $variantRegular=(float)$variant['price'];
                            $variantDiscount=$variantRegular>$price?(int)round((1-$price/$variantRegular)*100):0;
                        ?>
                            <option value="<?= (int)$variant['id'] ?>"
                                data-price="<?= e(number_format($price,2,'.','')) ?>"
                                data-regular-price="<?= e(number_format($variantRegular,2,'.','')) ?>"
                                data-discount="<?= $variantDiscount ?>"
                                data-stock="<?= (int)$variant['available'] ?>">
                                <?= e($variant['name'] ?: $variant['sku']) ?> — R$ <?= number_format($price,2,',','.') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="stock-status <?= (int)$selected['available'] > 0 ? 'is-available' : 'is-unavailable' ?>" data-stock-status>
                    <span></span><?= (int)$selected['available'] > 0 ? 'Em estoque · envio disponível' : 'Produto temporariamente sem estoque' ?>
                </div>

                <div class="buy-form__actions buy-form__actions--v2">
                    <label>Quantidade
                        <div class="product-quantity-control">
                            <button type="button" data-product-quantity-decrease aria-label="Diminuir quantidade">−</button>
                            <input type="number" name="quantity" min="<?= $cartMode==='wholesale'?max(1,(int)$product['wholesale_min_quantity']):1 ?>" max="<?= max(1,(int)$selected['available']) ?>" value="<?= $cartMode==='wholesale'?max(1,(int)$product['wholesale_min_quantity']):1 ?>" data-product-quantity>
                            <button type="button" data-product-quantity-increase aria-label="Aumentar quantidade">+</button>
                        </div>
                    </label>
                    <button class="button button--primary product-add-button" type="submit" <?= (int)$selected['available'] < 1 ? 'disabled' : '' ?> data-add-button>Adicionar ao carrinho</button>
                </div>
            </form>

            <div class="product-purchase-benefits" aria-label="Informações da compra">
                <div><i>✓</i><span><strong><?= $totalAvailable > 0 ? 'Disponível para compra' : 'Indisponível no momento' ?></strong><small>Estoque atualizado por variação</small></span></div>
                <div><i>↗</i><span><strong>Entrega calculada pelo CEP</strong><small>Veja transportadoras e prazos reais abaixo</small></span></div>
                <div><i>◈</i><span><strong>Vendido por <?= e($product['store_name']) ?></strong><small>Loja ativa no marketplace Tuffer</small></span></div>
            </div>
        </div>

        <div class="shipping-calculator shipping-calculator--v2" data-shipping-endpoint="<?= e(url('/produto/'.$product['slug'].'/frete')) ?>" data-shipping-configured="<?= $shippingConfigured?'1':'0' ?>">
            <div class="shipping-calculator__heading"><span>⌖</span><div><strong>Frete e prazo de entrega</strong><small>Informe o CEP para consultar as opções disponíveis para a quantidade escolhida.</small></div></div>
            <form data-shipping-calculator><?= csrf_field() ?><input name="postal_code" inputmode="numeric" maxlength="9" placeholder="00000-000" aria-label="CEP para cálculo do frete" required><button type="submit">Calcular</button></form>
            <small class="shipping-calculator__result" data-shipping-result><?= $shippingConfigured?'Informe seu CEP para consultar as transportadoras.':'Cálculo temporariamente indisponível.' ?></small>
            <div class="shipping-quote-list" data-shipping-options></div>
        </div>
    </aside>
</section>

<div class="product-mobile-purchase" data-mobile-product-bar>
    <div><small>A partir de</small><strong data-mobile-product-price>R$ <?= number_format($currentPrice,2,',','.') ?></strong></div>
    <button type="button" class="button button--primary" data-mobile-add <?= (int)$selected['available'] < 1 ? 'disabled' : '' ?>>Adicionar ao carrinho</button>
</div>

<?php if (count($media) > 1): ?>
<div class="product-gallery-modal" hidden data-product-gallery-modal>
    <div class="product-gallery-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="product-gallery-modal-title">
        <header><div><small>GALERIA DO PRODUTO</small><strong id="product-gallery-modal-title"><?= e($product['name']) ?></strong></div><span data-product-gallery-counter>1 / <?= count($media) ?></span><button type="button" data-close-product-gallery aria-label="Fechar galeria">×</button></header>
        <div class="product-gallery-modal__content">
            <div class="product-gallery-modal__viewer">
                <button type="button" class="product-gallery-modal__nav is-previous" data-product-gallery-previous aria-label="Mídia anterior">‹</button>
                <div class="product-gallery-modal__stage" data-product-gallery-modal-stage></div>
                <button type="button" class="product-gallery-modal__nav is-next" data-product-gallery-next aria-label="Próxima mídia">›</button>
            </div>
            <div class="product-gallery-modal__thumbs" aria-label="Todas as mídias do produto"><?php foreach ($media as $index => $item): ?><button type="button" class="<?= $index===0?'is-active':'' ?>" data-product-gallery-thumb data-type="<?= e($item['resource_type']) ?>" data-src="<?= e($item['secure_url']) ?>"><?php if ($item['resource_type']==='video'): ?><span class="product-gallery__play">▶</span><?php else: ?><img src="<?= e($item['thumbnail_url'] ?: $item['secure_url']) ?>" alt="Visual <?= $index+1 ?> de <?= e($product['name']) ?>" loading="lazy"><?php endif; ?></button><?php endforeach; ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<section class="seller-strip seller-strip--v2 container">
    <div class="seller-strip__logo"><?php if ($product['store_logo']): ?><img src="<?= e(upload_asset($product['store_logo'])) ?>" alt="<?= e($product['store_name']) ?>"><?php else: ?><?= e(mb_substr($product['store_name'],0,1)) ?><?php endif; ?></div>
    <div class="seller-strip__content"><small>VENDIDO E ENVIADO POR</small><h2><?= e($product['store_name']) ?></h2><p><?= e($product['store_description'] ?: 'Loja ativa no marketplace Tuffer.') ?></p><span>✓ Loja disponível para vendas</span></div>
    <div class="seller-strip__actions"><a class="button button--secondary" href="<?= e(url('/loja/'.$product['store_slug'])) ?>">Ver loja</a><a class="button button--dark" href="<?= e($canChat ? url('/minha-conta/mensagens/nova/'.$product['store_id']) : url('/entrar')) ?>">Falar com vendedor</a></div>
</section>

<section class="product-information product-information--v2 container" id="detalhes">
    <div class="product-information__intro">
        <span class="eyebrow">SOBRE O PRODUTO</span>
        <h2>Detalhes e especificações</h2>
        <p>Confira as informações principais antes de escolher sua variação e finalizar a compra.</p>
    </div>
    <div class="product-information__content product-information__content--v2">
        <article class="product-description product-information-card">
            <div class="product-information-card__heading"><span>01</span><div><small>DESCRIÇÃO</small><h3>Conheça o produto</h3></div></div>
            <div class="product-description__body"><?= rich_text_html($product['description'] ?: $product['short_description'] ?: 'Consulte as opções disponíveis e escolha a melhor para você.') ?></div>
        </article>
        <aside class="product-information-card product-information__specs">
            <div class="product-information-card__heading"><span>02</span><div><small>FICHA TÉCNICA</small><h3>Informações do produto</h3></div></div>
            <dl><?php foreach ($technicalFacts as $fact): ?><div><dt><?= e($fact['label']) ?></dt><dd><?= e($fact['value']) ?></dd></div><?php endforeach; ?></dl>
            <a href="#comprar">Escolher opção e comprar ↑</a>
        </aside>
    </div>
</section>

<?php if ($relatedProducts): ?>
<section class="related-products related-products--v2 container">
    <div class="home-heading"><div><span>VOCÊ TAMBÉM PODE GOSTAR</span><h2>Produtos relacionados</h2><p>Outras opções selecionadas para continuar sua compra.</p></div><a class="outline-link" href="<?= e(url('/produtos')) ?>">Ver todos →</a></div>
    <div class="product-grid"><?php foreach($relatedProducts as $product) require dirname(__DIR__,2).'/components/public/product-card.php'; ?></div>
</section>
<?php endif; ?>
