<header class="site-header">
    <div class="header-main container">
        <button class="public-menu-toggle" type="button" data-public-menu-open aria-expanded="false" aria-controls="public-mobile-menu" aria-label="Abrir menu">
            <span></span><span></span><span></span>
        </button>
        <a class="brand" href="<?= e(url('/')) ?>" aria-label="Tuffer, página inicial"><img src="<?= e(upload_asset($platformSettings['logo_path'] ?? 'platform/logos/tuffer-logo.svg')) ?>" alt="<?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>"></a>
        <?php require __DIR__ . '/search-bar.php'; ?>
        <nav class="header-actions" aria-label="Acesso rápido">
            <a class="icon-action" href="<?= e($authUser ? url(match ($authUser['type']) {'admin'=>'/admin','seller','operator'=>'/vendedor',default=>'/minha-conta'}) : url('/entrar')) ?>" aria-label="Minha conta">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
            </a>
            <?php if (($authUser['type'] ?? null) === 'customer'): ?>
            <a class="icon-action icon-action--notice" href="<?= e(url('/minha-conta/notificacoes')) ?>" aria-label="Notificações<?= $unreadNotifications > 0 ? ' não lidas: ' . (int) $unreadNotifications : '' ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg><?php if ($unreadNotifications > 0): ?><b><?= (int) min(99, $unreadNotifications) ?></b><?php endif; ?>
            </a>
            <?php endif; ?>
            <a class="icon-action icon-action--cart" href="<?= e(url('/carrinho')) ?>" aria-label="Carrinho">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14l-1 13H6L5 7Z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/></svg>
                <?php if (($cartCount ?? 0) > 0): ?><b class="cart-count"><?= (int) min(99, $cartCount) ?></b><?php endif; ?>
            </a>
        </nav>
    </div>
    <nav class="category-nav container" aria-label="Categorias principais">
        <a href="<?= e(url('/ofertas')) ?>">Ofertas</a>
        <a href="<?= e(url('/produtos')) ?>">Lançamentos</a>
        <?php if (!empty($menuCategories)): foreach ($menuCategories as $menuCategory): ?><a href="<?= e(url('/categoria/' . $menuCategory['slug'])) ?>"><?= e($menuCategory['name']) ?></a><?php endforeach; else: ?>
        <a href="<?= e(url('/categoria/moda-masculina')) ?>">Moda Masculina</a><a href="<?= e(url('/categoria/moda-feminina')) ?>">Moda Feminina</a><a href="<?= e(url('/categoria/kits')) ?>">Kits</a>
        <?php endif; ?>
        <a href="<?= e(url('/lojas')) ?>">Loja Oficial</a>
    </nav>
    <a class="delivery-bar" href="<?= e($authUser ? url('/minha-conta') : url('/entrar')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2"/></svg> Cadastrar endereço para entrega</a>
    <div class="public-mobile-menu" id="public-mobile-menu" data-public-menu aria-hidden="true">
        <button class="public-mobile-menu__backdrop" type="button" data-public-menu-close tabindex="-1" aria-label="Fechar menu"></button>
        <aside class="public-mobile-menu__panel" aria-label="Menu principal">
            <header><a class="brand" href="<?= e(url('/')) ?>"><img src="<?= e(upload_asset($platformSettings['logo_path'] ?? 'platform/logos/tuffer-logo.svg')) ?>" alt="<?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>"></a><button type="button" data-public-menu-close aria-label="Fechar menu">×</button></header>
            <nav>
                <a href="<?= e(url('/ofertas')) ?>"><span>Ofertas</span><b>→</b></a>
                <a href="<?= e(url('/produtos')) ?>"><span>Lançamentos</span><b>→</b></a>
                <?php if (!empty($menuCategories)): foreach ($menuCategories as $menuCategory): ?><a href="<?= e(url('/categoria/' . $menuCategory['slug'])) ?>"><span><?= e($menuCategory['name']) ?></span><b>→</b></a><?php endforeach; else: ?>
                <a href="<?= e(url('/categoria/moda-masculina')) ?>"><span>Moda Masculina</span><b>→</b></a>
                <a href="<?= e(url('/categoria/moda-feminina')) ?>"><span>Moda Feminina</span><b>→</b></a>
                <a href="<?= e(url('/categoria/kits')) ?>"><span>Kits</span><b>→</b></a>
                <?php endif; ?>
                <a href="<?= e(url('/lojas')) ?>"><span>Lojas oficiais</span><b>→</b></a>
            </nav>
            <footer>
                <a href="<?= e($authUser ? url(match ($authUser['type']) {'admin'=>'/admin','seller','operator'=>'/vendedor',default=>'/minha-conta'}) : url('/entrar')) ?>">Minha conta</a>
                <a href="<?= e(url('/carrinho')) ?>">Carrinho<?= ($cartCount ?? 0) > 0 ? ' (' . (int) $cartCount . ')' : '' ?></a>
            </footer>
        </aside>
    </div>
</header>
