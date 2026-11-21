<?php
$currentRequest = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$currentPath = (string) (parse_url($currentRequest, PHP_URL_PATH) ?: '/');
$currentQuery = (string) (parse_url($currentRequest, PHP_URL_QUERY) ?? '');
$currentReturn = $currentPath . ($currentQuery !== '' ? '?' . $currentQuery : '');
$guestLoginUrl = url('/entrar?redirect=' . rawurlencode($currentReturn));
$addressLoginUrl = url('/entrar?redirect=' . rawurlencode('/minha-conta/enderecos'));
$hasDeliveryAddress = ($authUser['type'] ?? null) === 'customer' && is_array($deliveryAddress ?? null);
$deliveryPrimary = $hasDeliveryAddress
    ? trim((string) (($deliveryAddress['label'] ?? '') ?: 'Entregar em'))
    : 'Cadastrar endereço para entrega';
$deliverySecondary = $hasDeliveryAddress
    ? trim((string) ($deliveryAddress['street'] ?? '') . ', ' . (string) ($deliveryAddress['number'] ?? '') . ' · ' . (string) ($deliveryAddress['city'] ?? '') . '/' . (string) ($deliveryAddress['state'] ?? ''))
    : '';
?>
<header class="site-header">
    <div class="header-main container">
        <button class="public-menu-toggle" type="button" data-public-menu-open aria-expanded="false" aria-controls="public-mobile-menu" aria-label="Abrir menu">
            <span></span><span></span><span></span>
        </button>
        <a class="brand" href="<?= e(url('/')) ?>" aria-label="Tuffer, página inicial"><img src="<?= e(upload_asset($platformSettings['logo_path'] ?? 'platform/logos/tuffer-logo.svg')) ?>" alt="<?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>"></a>
        <?php require __DIR__ . '/search-bar.php'; ?>
        <nav class="header-actions" aria-label="Acesso rápido">
            <?php if (($authUser['type'] ?? null) === 'customer'): ?>
              <div class="header-menu" data-header-menu>
                <button class="icon-action header-menu__trigger" type="button" data-header-trigger aria-expanded="false" aria-haspopup="true" aria-controls="header-account-panel" aria-label="Abrir menu Minha conta">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                </button>
                <div class="header-menu__panel header-menu__panel--account" id="header-account-panel" data-header-panel hidden>
                  <div class="header-menu__intro"><span class="header-menu__avatar"><?=e(mb_strtoupper(mb_substr((string) ($authUser['name'] ?? 'C'),0,1)))?></span><span><strong><?=e((string) ($authUser['name'] ?? 'Minha conta'))?></strong><small><?=e((string) ($authUser['email'] ?? ''))?></small></span></div>
                  <div class="header-menu__links">
                    <a href="<?=e(url('/minha-conta/pedidos'))?>"><span>Ver meus pedidos</span><span aria-hidden="true">→</span></a>
                    <a href="<?=e(url('/minha-conta/enderecos'))?>"><span>Meus endereços</span><span aria-hidden="true">→</span></a>
                    <a href="<?=e(url('/minha-conta/perfil'))?>"><span>Meus dados</span><span aria-hidden="true">→</span></a>
                  </div>
                  <form method="post" action="<?=e(url('/sair'))?>" class="header-menu__logout"><?=csrf_field()?><button type="submit">Sair da conta <span aria-hidden="true">↗</span></button></form>
                </div>
              </div>
              <?php
              $headerLatestNotifications = [];
              try {
                  $headerNotificationStmt = \App\Core\Database::connection()->prepare('SELECT title,message,action_url,created_at,read_at FROM user_notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 4');
                  $headerNotificationStmt->execute([(int)$authUser['id']]);
                  $headerLatestNotifications = $headerNotificationStmt->fetchAll(\PDO::FETCH_ASSOC);
              } catch (\Throwable $exception) {
                  \App\Core\Logger::exception($exception,['area'=>'header_notifications'],'database');
              }
              ?>
              <div class="header-menu" data-header-menu>
                <button class="icon-action icon-action--notice header-menu__trigger" type="button" data-header-trigger aria-expanded="false" aria-haspopup="true" aria-controls="header-notifications-panel" aria-label="Abrir notificações<?=($unreadNotifications ?? 0)>0?' não lidas: '.(int)$unreadNotifications:''?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                  <?php if (($unreadNotifications ?? 0)>0): ?><b><?= (int)min(99,$unreadNotifications)?></b><?php endif;?>
                </button>
                <div class="header-menu__panel header-menu__panel--notifications" id="header-notifications-panel" data-header-panel hidden>
                  <div class="header-menu__panel-heading"><strong>Notificações</strong><span><?= (int)($unreadNotifications ?? 0) ?> não lidas</span></div>
                  <?php if (!$headerLatestNotifications): ?>
                    <div class="header-menu__empty"><span aria-hidden="true">✓</span><strong>Tudo em dia!</strong><small>Você não tem notificações recentes.</small></div>
                  <?php else: ?>
                    <div class="header-menu__notifications">
                      <?php foreach ($headerLatestNotifications as $notice):
                          $noticePath = (string)($notice['action_url'] ?: '/minha-conta/notificacoes');
                          if ($noticePath === '' || !str_starts_with($noticePath,'/') || str_starts_with($noticePath,'//') || str_contains($noticePath,'\\')) $noticePath='/minha-conta/notificacoes';
                      ?>
                        <a class="header-menu__notification <?=empty($notice['read_at'])?'is-unread':''?>" href="<?=e(url($noticePath))?>">
                          <span class="header-menu__dot" aria-hidden="true"></span>
                          <span><strong><?=e((string)$notice['title'])?></strong><small><?=e((string)$notice['message'])?></small><time><?=date('d/m H:i',strtotime((string)$notice['created_at']))?></time></span>
                        </a>
                      <?php endforeach;?>
                    </div>
                  <?php endif;?>
                  <a class="header-menu__all" href="<?=e(url('/minha-conta/notificacoes'))?>">Ver todas as notificações <span aria-hidden="true">→</span></a>
                </div>
              </div>
            <?php else: ?>
              <a class="icon-action" href="<?= e($authUser ? url(match ($authUser['type']) {'admin'=>'/admin','seller','operator'=>'/vendedor',default=>'/minha-conta'}) : $guestLoginUrl) ?>" aria-label="<?= $authUser ? 'Minha conta' : 'Entrar' ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
              </a>
            <?php endif; ?>
            <a class="icon-action icon-action--cart" href="<?=e(url('/carrinho'))?>" aria-label="Carrinho">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14l-1 13H6L5 7Z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/></svg>
              <?php if (($cartCount ?? 0)>0):?><b class="cart-count"><?= (int)min(99,$cartCount)?></b><?php endif;?>
            </a>
        </nav>
    </div>
    <nav class="category-nav container" aria-label="Categorias principais">
        <a href="<?= e(url('/ofertas')) ?>">Ofertas</a>
        <a href="<?= e(url('/produtos')) ?>">Lançamentos</a>
        <?php if (!empty($menuCategories)): foreach ($menuCategories as $menuCategory): ?><a href="<?= e(url('/categoria/' . $menuCategory['slug'])) ?>"><?= e($menuCategory['name']) ?></a><?php endforeach; else: ?>
        <a href="<?= e(url('/categoria/moda-masculina')) ?>">Moda Masculina</a><a href="<?= e(url('/categoria/moda-feminina')) ?>">Moda Feminina</a><a href="<?= e(url('/categoria/kits')) ?>">Kits</a>
        <?php endif; ?>
    </nav>
    <a class="delivery-bar<?= $hasDeliveryAddress ? ' has-address' : '' ?>" href="<?= e($authUser ? url('/minha-conta/enderecos') : $addressLoginUrl) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2"/></svg>
        <span class="delivery-bar__copy"><strong><?= e($deliveryPrimary) ?></strong><?php if ($deliverySecondary !== ''): ?><small><?= e($deliverySecondary) ?></small><?php endif; ?></span>
    </a>
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
            </nav>
            <footer>
                <a href="<?= e($authUser ? url(match ($authUser['type']) {'admin'=>'/admin','seller','operator'=>'/vendedor',default=>'/minha-conta'}) : $guestLoginUrl) ?>"><?= $authUser ? 'Minha conta' : 'Entrar' ?></a>
                <a href="<?= e(url('/carrinho')) ?>">Carrinho<?= ($cartCount ?? 0) > 0 ? ' (' . (int) $cartCount . ')' : '' ?></a>
                <?php if ($authUser): ?><form class="public-mobile-menu__logout" method="post" action="<?= e(url('/sair')) ?>"><?= csrf_field() ?><button type="submit">Sair da conta</button></form><?php endif; ?>
            </footer>
        </aside>
    </div>
</header>
