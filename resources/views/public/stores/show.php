<?php
$storeBannerStyle = !empty($store['banner_url'])
    ? "background-image:linear-gradient(90deg,rgba(10,10,10,.78),rgba(10,10,10,.28)),url('" . e(upload_asset($store['banner_url'])) . "')"
    : '';
?>
<section class="store-cover"<?= $storeBannerStyle !== '' ? ' style="' . $storeBannerStyle . '"' : '' ?>>
    <div class="container">
        <div class="seller-mark seller-mark--large">
            <?php if (!empty($store['logo_url'])): ?>
                <img src="<?= e(upload_asset($store['logo_url'])) ?>" alt="Logo da loja <?= e($store['name']) ?>">
            <?php else: ?>
                <?= e(mb_substr($store['name'], 0, 1)) ?>
            <?php endif; ?>
        </div>
        <h1><?= e($store['name']) ?></h1>
        <p><?= e($store['description'] ?: 'Conheça o catálogo desta loja.') ?></p>
        <a class="button button--primary" href="<?= e(($authUser['type'] ?? null) === 'customer' ? url('/minha-conta/mensagens/nova/' . $store['id']) : url('/entrar')) ?>">Enviar mensagem ao vendedor</a>
    </div>
</section>
<section class="section container">
    <div class="section-heading">
        <div>
            <span class="eyebrow">CATÁLOGO OFICIAL</span>
            <h2>Produtos da loja</h2>
        </div>
        <span><?= count($products) ?> produtos</span>
    </div>
    <div class="product-grid">
        <?php foreach ($products as $product) require dirname(__DIR__, 2) . '/components/public/product-card.php'; ?>
    </div>
</section>
