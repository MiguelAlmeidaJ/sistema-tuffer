<?php
$bannerLink = static function(mixed $configured, string $fallback): array {
    $href = platform_link_href($configured, $fallback);
    return [$href, platform_link_is_external($href)];
};
[$mainBannerHref,$mainBannerExternal] = $bannerLink($platformSettings['home_main_banner_link'] ?? '', '/produtos');
[$discountBannerHref,$discountBannerExternal] = $bannerLink($platformSettings['home_discount_banner_link'] ?? '', '/ofertas');
[$officialBannerHref,$officialBannerExternal] = $bannerLink($platformSettings['home_official_banner_link'] ?? '', '/lojas');
[$wideBannerHref,$wideBannerExternal] = $bannerLink($platformSettings['official_wide_banner_link'] ?? '', '/lojas');
?>
<section class="home-carousel home-carousel--campaigns container" data-home-carousel data-carousel-autoplay="5500" aria-label="Destaques Tuffer">
    <div class="campaigns home-carousel__track" data-carousel-track>
        <a class="campaign campaign--main campaign--asset" href="<?=e($mainBannerHref)?>"<?=$mainBannerExternal?' target="_blank" rel="noopener noreferrer"':''?>><img src="<?= e(upload_asset($platformSettings['home_main_banner'] ?? 'platform/banners/home-main.svg')) ?>" alt="Conforto e segurança Tuffer"></a>
        <a class="campaign campaign--discount campaign--asset" href="<?=e($discountBannerHref)?>"<?=$discountBannerExternal?' target="_blank" rel="noopener noreferrer"':''?>><img src="<?= e(upload_asset($platformSettings['home_discount_banner'] ?? 'platform/banners/home-discount.svg')) ?>" alt="Aproveite descontos exclusivos"></a>
        <a class="campaign campaign--official campaign--asset" href="<?=e($officialBannerHref)?>"<?=$officialBannerExternal?' target="_blank" rel="noopener noreferrer"':''?>><img src="<?= e(upload_asset($platformSettings['home_official_banner'] ?? 'platform/banners/home-official.svg')) ?>" alt="Loja oficial Tuffer"></a>
    </div>
    <div class="home-carousel__controls" data-carousel-controls><div class="home-carousel__dots" data-carousel-dots aria-label="Selecionar destaque"></div><div class="home-carousel__arrows"><button type="button" data-carousel-previous aria-label="Destaque anterior">←</button><button type="button" data-carousel-next aria-label="Próximo destaque">→</button></div></div>
</section>

<?php if ($categories): ?><section class="home-category-showcase container" data-category-carousel>
    <header><div><span>COMPRE POR CATEGORIA</span><h2>Encontre o que combina com você</h2><p>As categorias com maior variedade de produtos nas lojas Tuffer.</p></div><nav aria-label="Navegar pelas categorias"><button type="button" data-category-previous aria-label="Categorias anteriores">←</button><button type="button" data-category-next aria-label="Próximas categorias">→</button></nav></header>
    <div class="home-category-track" data-category-track>
        <?php foreach ($categories as $category): ?><a class="home-category-card" href="<?= e(url('/categoria/' . $category['slug'])) ?>"><span class="home-category-card__image"><img src="<?= e(upload_asset($category['image_path'])) ?>" alt="Categoria <?= e($category['name']) ?>" loading="lazy"></span><span class="home-category-card__copy"><strong><?= e($category['name']) ?></strong><small><?= (int) $category['products_count'] ?> produto<?= (int) $category['products_count'] === 1 ? '' : 's' ?></small></span><i aria-hidden="true">↗</i></a><?php endforeach; ?>
    </div>
</section><?php endif; ?>

<section class="home-section home-carousel container" data-home-carousel>
    <div class="home-heading"><div><span>Lançamentos</span><h2>Novidades das lojas</h2></div><a class="outline-link" href="<?= e(url('/produtos')) ?>">Comprar agora &nbsp; →</a></div>
    <div class="product-grid home-products home-carousel__track" data-carousel-track>
        <?php $homeProductCard = true; ?>
        <?php if ($products): foreach ($products as $product) require dirname(__DIR__).'/components/public/product-card.php'; else: ?>
            <?php foreach (['Qualidade nos detalhes','Conforto todos os dias','Modelagem que acompanha','Essenciais Tuffer','Toque macio','Cintura confortável','Ajuste perfeito','Clássicos Tuffer'] as $index=>$name): $product=['name'=>$name,'slug'=>'','price'=>[135.92,64.50,49.90,84.92,59.90,72.90,89.90,99.90][$index],'store_name'=>'Tuffer','store_logo'=>'platform/logos/tuffer-logo.svg','image_url'=>null]; require dirname(__DIR__).'/components/public/product-card.php'; endforeach; ?>
        <?php endif; ?>
        <?php unset($homeProductCard); ?>
    </div>
    <div class="home-carousel__controls" data-carousel-controls><div class="home-carousel__dots" data-carousel-dots aria-label="Selecionar produtos"></div><div class="home-carousel__arrows"><button type="button" data-carousel-previous aria-label="Produtos anteriores">←</button><button type="button" data-carousel-next aria-label="Próximos produtos">→</button></div></div>
</section>

<div class="quick-cart-modal" hidden data-quick-cart-modal>
    <button class="quick-cart-modal__backdrop" type="button" data-close-quick-cart tabindex="-1" aria-hidden="true" aria-label="Fechar compra rápida"></button>
    <section class="quick-cart-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="quick-cart-title">
        <header><div><span>COMPRA RÁPIDA</span><h2 id="quick-cart-title" data-quick-cart-title>Produto</h2></div><button type="button" data-close-quick-cart aria-label="Fechar">×</button></header>
        <div class="quick-cart-modal__content">
            <div class="quick-cart-modal__image"><img src="" alt="" data-quick-cart-image><span data-quick-cart-placeholder>TUFFER</span></div>
            <div class="quick-cart-modal__details">
                <div class="quick-cart-modal__store"><span data-quick-cart-store-logo></span><small data-quick-cart-store>Loja</small></div>
                <strong data-quick-cart-price>R$ 0,00</strong>
                <a class="outline-link" href="<?= e(url('/produtos')) ?>" data-quick-cart-link>Ir para o produto →</a>
                <form action="<?= e(url('/carrinho/adicionar')) ?>" method="post" data-quick-cart-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="variant_id" value="" data-quick-cart-variant>
                    <label>Quantidade</label>
                    <div class="quick-cart-modal__stepper">
                        <button type="button" data-quick-cart-decrease aria-label="Diminuir quantidade">−</button>
                        <input type="number" name="quantity" min="1" max="99" value="1" inputmode="numeric" data-quick-cart-quantity>
                        <button type="button" data-quick-cart-increase aria-label="Aumentar quantidade">+</button>
                    </div>
                    <button class="button button--primary" type="submit" data-quick-cart-submit>Adicionar ao carrinho</button>
                </form>
            </div>
        </div>
    </section>
</div>

<a class="official-banner official-banner--asset container" href="<?=e($wideBannerHref)?>"<?=$wideBannerExternal?' target="_blank" rel="noopener noreferrer"':''?>><img src="<?= e(upload_asset($platformSettings['official_wide_banner'] ?? 'platform/banners/official-wide.svg')) ?>" alt="Loja oficial Tuffer, para todos os estilos e todos os dias"></a>

<section class="home-section home-carousel container stores-section" data-home-carousel>
    <div class="home-heading"><div><span>Lojas oficiais</span><h2>Lojas para explorar</h2></div><a class="outline-link" href="<?= e(url('/lojas')) ?>">Explorar produtos &nbsp; →</a></div>
    <div class="seller-grid home-carousel__track" data-carousel-track>
        <?php if ($stores): foreach ($stores as $store) require dirname(__DIR__).'/components/public/seller-card.php'; else: ?>
            <?php $store=['name'=>'Tuffer','slug'=>'','description'=>'Loja oficial na Tuffer.','logo_url'=>'platform/logos/tuffer-logo.svg','banner_url'=>'platform/banners/store-card-tuffer.svg']; require dirname(__DIR__).'/components/public/seller-card.php'; ?>
            <?php $store=['name'=>'Lojas Parceiras','slug'=>'','description'=>'Novas marcas chegando em breve.','logo_url'=>null,'banner_url'=>null]; require dirname(__DIR__).'/components/public/seller-card.php'; ?>
        <?php endif; ?>
    </div>
    <div class="home-carousel__controls" data-carousel-controls><div class="home-carousel__dots" data-carousel-dots aria-label="Selecionar lojas"></div><div class="home-carousel__arrows"><button type="button" data-carousel-previous aria-label="Lojas anteriores">←</button><button type="button" data-carousel-next aria-label="Próximas lojas">→</button></div></div>
</section>
