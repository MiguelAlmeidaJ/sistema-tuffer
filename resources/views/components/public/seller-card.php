<article class="seller-card">
    <div class="seller-card__cover" <?= !empty($store['banner_url']) ? ' style="background-image:url(\'' . e(upload_asset($store['banner_url'])) . '\')"' : '' ?>><span>TUFFER</span></div>
    <div class="seller-card__body">
        <div class="seller-mark"><?php if (!empty($store['logo_url'])): ?><img
                    src="<?= e(upload_asset($store['logo_url'])) ?>"
                    alt=""><?php else: ?><?= e(mb_substr($store['name'], 0, 1)) ?><?php endif; ?></div><span
            class="verified">✓</span>
        <h3><?= e($store['name']) ?></h3>
        <p><?= e($store['description'] ?: 'Loja oficial na Tuffer.') ?></p><a
            href="<?= e(!empty($store['slug']) ? url('/loja/' . $store['slug']) : url('/lojas')) ?>">Explorar produtos
            →</a>
    </div>
</article>