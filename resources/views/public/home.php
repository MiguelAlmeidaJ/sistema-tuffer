<?php
$bannerLink = static function(mixed $configured, string $fallback): array {
    $href = platform_link_href($configured, $fallback);
    return [$href, platform_link_is_external($href)];
};
[$mainBannerHref,$mainBannerExternal] = $bannerLink($platformSettings['home_main_banner_link'] ?? '', '/produtos');
[$discountBannerHref,$discountBannerExternal] = $bannerLink($platformSettings['home_discount_banner_link'] ?? '', '/ofertas');
[$officialBannerHref,$officialBannerExternal] = $bannerLink($platformSettings['home_official_banner_link'] ?? '', '/produtos');
[$wideBannerHref,$wideBannerExternal] = $bannerLink($platformSettings['official_wide_banner_link'] ?? '', '/produtos');
?>
<section class="home-carousel home-carousel--campaigns container" data-home-carousel data-carousel-autoplay="5500" aria-label="Destaques Tuffer">
    <div class="campaigns home-carousel__track" data-carousel-track>
        <a class="campaign campaign--main campaign--asset" href="<?=e($mainBannerHref)?>"<?=$mainBannerExternal?' target="_blank" rel="noopener noreferrer"':''?>><img src="<?= e(upload_asset($platformSettings['home_main_banner'] ?? 'platform/banners/home-main.svg')) ?>" alt="Conforto e segurança Tuffer"></a>
        <a class="campaign campaign--discount campaign--asset" href="<?=e($discountBannerHref)?>"<?=$discountBannerExternal?' target="_blank" rel="noopener noreferrer"':''?>><img src="<?= e(upload_asset($platformSettings['home_discount_banner'] ?? 'platform/banners/home-discount.svg')) ?>" alt="Aproveite descontos exclusivos"></a>
        <a class="campaign campaign--official campaign--asset" href="<?=e($officialBannerHref)?>"<?=$officialBannerExternal?' target="_blank" rel="noopener noreferrer"':''?>><img src="<?= e(upload_asset($platformSettings['home_official_banner'] ?? 'platform/banners/home-official.svg')) ?>" alt="Loja oficial Tuffer"></a>
    </div>
    <div class="home-carousel__controls" data-carousel-controls><div class="home-carousel__dots" data-carousel-dots aria-label="Selecionar destaque"></div><div class="home-carousel__arrows"><button type="button" data-carousel-previous aria-label="Destaque anterior">←</button><button type="button" data-carousel-next aria-label="Próximo destaque">→</button></div></div>
</section>


<section class="home-section home-launches container" data-product-rail aria-labelledby="home-launches-title">
    <header class="home-heading home-launches__heading">
        <div><span>LANÇAMENTOS</span><h2 id="home-launches-title">Novidades para você</h2><p>Descubra os últimos produtos do nosso catálogo.</p></div>
        <div class="home-launches__heading-actions">
            <a class="outline-link" href="<?=e(url('/produtos'))?>">Ver todos os produtos <span aria-hidden="true">→</span></a>
            <?php if(count($products)>1): ?><div class="home-launches__navigation" aria-label="Navegação dos lançamentos">
                <button type="button" data-product-previous aria-label="Ver produtos anteriores">←</button>
                <button type="button" data-product-next aria-label="Ver próximos produtos">→</button>
            </div><?php endif; ?>
        </div>
    </header>
    <?php if($products): ?>
    <div class="home-launches__track" data-product-track tabindex="0" aria-label="Carrossel de lançamentos">
        <?php $homeProductCard=true;foreach($products as $product): require dirname(__DIR__).'/components/public/product-card.php';endforeach;unset($homeProductCard); ?>
    </div>
    <div class="home-launches__progress" role="presentation"><span data-product-progress></span></div>
    <?php else: ?><div class="home-launches__empty">Novidades chegando. <a href="<?=e(url('/produtos'))?>">Explore o catálogo</a>.</div><?php endif; ?>
</section>

<?php if($categories): ?>
<section class="home-categories container" id="categorias" aria-labelledby="home-categories-title">
    <header class="home-categories__heading">
        <div><span>ENCONTRE SEU ESTILO</span><h2 id="home-categories-title">Explore por categoria</h2><p>Escolha o que procura e descubra sua próxima peça favorita.</p></div>
        <a class="outline-link" href="<?=e(url('/produtos'))?>">Todas as peças <span aria-hidden="true">→</span></a>
    </header>
    <div class="home-categories__grid">
        <?php foreach($categories as $index=>$category): ?>
        <a href="<?=e(url('/categoria/'.$category['slug']))?>" class="home-categories__item">
            <span class="home-categories__media">
                <?php if(!empty($category['image_path'])): ?><img src="<?=e(upload_asset($category['image_path']))?>" alt="" loading="lazy">
                <?php else: ?><span class="home-categories__empty-media" aria-hidden="true"></span><?php endif;?>
            </span>
            <span class="home-categories__info"><strong><?=e($category['name'])?></strong><small><?= (int)$category['products_count'] ?> <?= (int)$category['products_count']===1?'produto':'produtos' ?></small></span>
            <span class="home-categories__arrow" aria-hidden="true">↗</span>
        </a>
        <?php endforeach;?>
    </div>
</section>
<?php endif; ?>

<section class="home-brands container" aria-labelledby="home-brands-title">
    <header class="home-brands__heading"><div><span>NOSSAS MARCAS</span><h2 id="home-brands-title">Duas marcas. Seu estilo.</h2><p>Tuffer e Linda Flor, juntas para acompanhar cada momento.</p></div></header>
    <div class="home-brands__grid">
        <?php
            $brandRows=[];
            foreach(($brands??[]) as $brand){$brandRows[mb_strtolower(trim((string)$brand['name']))]=$brand;}
            $tufferBrand=$brandRows['tuffer']??null;
            $lindaBrand=$brandRows['linda flor']??null;
            $tufferBrandHref=$tufferBrand?'/produtos?brand='.rawurlencode($tufferBrand['slug']):'/produtos?q=Tuffer';
            $lindaBrandHref=$lindaBrand?'/produtos?brand='.rawurlencode($lindaBrand['slug']):'/produtos?q=Linda%20Flor';
        ?>
        <a class="home-brand home-brand--tuffer" href="<?=e(url($tufferBrandHref))?>">
            <span class="home-brand__visual home-brand__visual--tuffer"><?php if(!empty($tufferBrand['cover_image_path'])):?><img class="home-brand__cover" src="<?=e(upload_asset($tufferBrand['cover_image_path']))?>" alt="" loading="lazy"><?php else:?><img class="home-brand__logo" src="<?=e(upload_asset('platform/logos/tuffer-logo.svg'))?>" alt="" loading="lazy"><?php endif;?></span>
            <span class="home-brand__content"><span class="home-brand__eyebrow">ESSENCIAIS</span><strong>Tuffer</strong><span>Conforto e atitude em peças para todos os dias.</span><b>Conhecer a marca <span aria-hidden="true">→</span></b></span>
        </a>
        <a class="home-brand home-brand--linda" href="<?=e(url($lindaBrandHref))?>">
            <span class="home-brand__visual home-brand__visual--linda"><?php if(!empty($lindaBrand['cover_image_path'])):?><img class="home-brand__cover" src="<?=e(upload_asset($lindaBrand['cover_image_path']))?>" alt="" loading="lazy"><?php else:?><span class="home-brand__flower" aria-hidden="true">✿</span><span class="home-brand__wordmark">Linda Flor</span><?php endif;?></span>
            <span class="home-brand__content"><span class="home-brand__eyebrow">DELICADEZA</span><strong>Linda Flor</strong><span>Peças femininas que valorizam seu conforto e seu estilo.</span><b>Conhecer a marca <span aria-hidden="true">→</span></b></span>
        </a>
    </div>
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

