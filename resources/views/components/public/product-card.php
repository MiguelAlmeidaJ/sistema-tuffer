<?php $isHomeProductCard = !empty($homeProductCard); ?>
<article class="product-card<?= $isHomeProductCard ? ' product-card--home' : '' ?>">
    <div class="product-card__media">
        <a class="product-card__image" href="<?= e(url('/produto/' . $product['slug'])) ?>">
            <?php if (!empty($product['image_url'])): ?><img src="<?= e($product['image_url']) ?>" alt="<?= e($product['name']) ?>" loading="lazy"><?php else: ?><span class="product-placeholder"><i></i><b>TUFFER</b></span><?php endif; ?>
        </a>
        <?php if ($isHomeProductCard): ?>
            <span class="product-card__store-logo" title="<?= e($product['store_name'] ?? 'Loja') ?>">
                <?php if (!empty($product['store_logo'])): ?><img src="<?= e(upload_asset($product['store_logo'])) ?>" alt="Logo <?= e($product['store_name']) ?>"><?php else: ?><?= e(mb_substr((string) ($product['store_name'] ?? 'T'), 0, 1)) ?><?php endif; ?>
            </span>
            <?php if (!empty($product['variant_id'])): ?>
                <button class="product-card__cart" type="button"
                    data-quick-cart
                    data-product-name="<?= e($product['name']) ?>"
                    data-product-url="<?= e(url('/produto/' . $product['slug'])) ?>"
                    data-product-image="<?= e($product['image_url'] ?? '') ?>"
                    data-product-price="<?= e(number_format((float) $product['price'], 2, ',', '.')) ?>"
                    data-product-store="<?= e($product['store_name'] ?? '') ?>"
                    data-product-store-logo="<?= e(!empty($product['store_logo']) ? upload_asset($product['store_logo']) : '') ?>"
                    data-variant-id="<?= (int) $product['variant_id'] ?>"
                    data-max-quantity="<?= max(0, (int) ($product['available'] ?? 0)) ?>"
                    aria-haspopup="dialog"
                    aria-label="Escolher quantidade de <?= e($product['name']) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20.3 8H6.1M10 20h.01M17 20h.01"/></svg>
                </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="product-card__body">
        <?php if (!empty($product['store_name'])): ?><small><?= e($product['store_name']) ?></small><?php endif; ?>
        <h3><a href="<?= e(url('/produto/' . $product['slug'])) ?>"><?= e($product['name']) ?></a></h3>
        <?php if (!empty($product['regular_price']) && (float) $product['regular_price'] > (float) $product['price']): ?><del>R$ <?= number_format((float) $product['regular_price'], 2, ',', '.') ?></del><?php endif; ?>
        <strong>R$ <?= number_format((float) $product['price'], 2, ',', '.') ?></strong>
        <?php if (!$isHomeProductCard && !empty($product['variant_id'])): ?>
            <form action="<?= e(url('/carrinho/adicionar')) ?>" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="variant_id" value="<?= (int) $product['variant_id'] ?>">
                <input type="hidden" name="quantity" value="1">
                <button class="product-card__cart" type="submit" aria-label="Adicionar <?= e($product['name']) ?> ao carrinho">+</button>
            </form>
        <?php endif; ?>
    </div>
</article>
