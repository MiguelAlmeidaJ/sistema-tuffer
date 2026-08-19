<?php
$customerMenu = [['Visão geral', '/minha-conta'], ['Meus pedidos', '/minha-conta/pedidos']];
if (($wholesaleStatus ?? null) === 'approved') $customerMenu[] = ['Pedidos de atacado', '/minha-conta/pedidos?tipo=atacado'];
$customerMenu = array_merge($customerMenu, [
    ['Endereços', '/minha-conta/enderecos'], ['Favoritos', '/minha-conta/favoritos'], ['Mensagens', '/minha-conta/mensagens'],
    ['Notificações' . (($unreadNotifications ?? 0) ? ' (' . $unreadNotifications . ')' : ''), '/minha-conta/notificacoes'],
]);
$customerMenu[] = [($wholesaleStatus ?? null) === 'approved' ? 'Minha empresa' : 'Comprar no atacado', ($wholesaleStatus ?? null) ? '/minha-conta/atacado/status' : '/minha-conta/atacado'];
if (($wholesaleStatus ?? null) === 'approved') $customerMenu[] = ['Condições de atacado', '/minha-conta/atacado/status'];
$customerMenu[] = ['Meus dados', '/minha-conta/perfil'];

$menus = [
    'customer' => $customerMenu,
    'admin' => [['Dashboard','/admin'],['Pedidos','/admin/pedidos'],['Relatório','/admin/relatorios'],['Financeiro','/admin/financeiro'],['Monitoramento','/admin/monitoramento'],['Atacadistas','/admin/atacadistas'],['Lojas','/admin/lojas'],['Produtos','/admin/produtos'],['Categorias','/admin/categorias'],['Tags','/admin/tags'],['Usuários','/admin/usuarios'],['Configurações','/admin/configuracoes']],
];
$menuIcon = static function(string $name): string {
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'activity' => '<path d="M3 12h4l2.5-6 5 12 2.5-6H21"/>',
        'report' => '<path d="M4 19V9m6 10V5m6 14v-7m4 7H2"/>',
        'orders' => '<path d="M21 8l-9-5-9 5 9 5 9-5Z"/><path d="m3 8 9 5 9-5M3 8v8l9 5 9-5V8M12 13v8"/>',
        'products' => '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 7 9 5 9-5M3 12l9 5 9-5M3 17l9 5 9-5"/>',
        'categories' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'tag' => '<path d="M20.6 13.6 11 4H4v7l9.6 9.6a2 2 0 0 0 2.8 0l4.2-4.2a2 2 0 0 0 0-2.8Z"/><circle cx="7.5" cy="7.5" r="1"/>',
        'store' => '<path d="M3 9l2-6h14l2 6M5 13v8h14v-8M9 21v-6h6v6"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/>',
        'wholesale' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
        'finance' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M16 15h2"/>',
        'wallet' => '<path d="M20 7V5a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v10a2 2 0 0 1-2 2H5a3 3 0 0 1-3-3V6"/><path d="M16 13h4"/>',
        'settlement' => '<path d="M6 2v4M18 2v4M3 10h18"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="m8 16 2 2 5-5"/>',
        'diagnostic' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'coupon' => '<path d="M3 7a2 2 0 0 0 2-2h14v5a2 2 0 0 0 0 4v5H5a2 2 0 0 0-2-2V7Z"/><path d="M13 7v2M13 11v2M13 15v2"/>',
        'messages' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v8Z"/><path d="M8 10h8M8 14h5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21H9.6v-.1A1.7 1.7 0 0 0 8.5 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3V9.6h.1A1.7 1.7 0 0 0 4.6 8.5a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.1A1.7 1.7 0 0 0 15.5 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.15.38.37.72.66 1 .3.28.68.43 1.08.43H21v4h-.1A1.7 1.7 0 0 0 19.4 15Z"/>',
        'chevron' => '<path d="m9 18 6-6-6-6"/>',
        'collapse' => '<path d="m15 18-6-6 6-6"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? $paths['dashboard']) . '</svg>';
};
$sellerMenu = [
    ['dashboard', 'Dashboard', '/vendedor'],
    ['orders', 'Pedidos', '/vendedor/pedidos'],
    ['report', 'Relatório', '/vendedor/relatorios'],
    ['finance', 'Financeiro', '/vendedor/financeiro'],
    ['products', 'Produtos', '/vendedor/produtos'],
    ['coupon', 'Cupons', '/vendedor/cupons'],
    ['messages', 'Mensagens', '/vendedor/mensagens'],
    ['settings', 'Configurações', '/vendedor/configuracoes/loja'],
];
$adminOverview = [
    ['dashboard','Dashboard','/admin'],
    ['activity','Monitoramento','/admin/monitoramento'],
    ['report','Relatórios','/admin/relatorios'],
];
$adminGroups = [
    'Operação' => [['orders','Pedidos','/admin/pedidos'],['products','Produtos','/admin/produtos'],['categories','Categorias','/admin/categorias'],['tag','Tags','/admin/tags']],
    'Marketplace' => [['store','Lojas','/admin/lojas'],['wholesale','Atacadistas','/admin/atacadistas'],['users','Usuários','/admin/usuarios']],
    'Financeiro' => [['finance','Visão financeira','/admin/financeiro'],['wallet','Carteira de fretes','/admin/financeiro/carteira-fretes'],['settlement','Fechamentos','/admin/financeiro/fechamentos'],['diagnostic','Diagnóstico Pagar.me','/admin/diagnostico/pagarme']],
    'Plataforma' => [['settings','Configurações','/admin/configuracoes']],
];
$currentPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$isActiveMenu = static function(string $href) use ($currentPath): bool {
    $menuPath = (string) parse_url(url($href), PHP_URL_PATH);
    $isRoot = in_array($menuPath, [url('/vendedor'), url('/admin'), url('/minha-conta')], true);
    return $currentPath === $menuPath || (!$isRoot && str_starts_with($currentPath, rtrim($menuPath, '/') . '/'));
};
$activeAdminHref = null;
$activeAdminLength = -1;
foreach (array_merge($adminOverview, ...array_values($adminGroups)) as $item) {
    $href = $item[2];
    $menuPath = (string) parse_url(url($href), PHP_URL_PATH);
    $matches = $currentPath === $menuPath || ($menuPath !== url('/admin') && str_starts_with($currentPath, rtrim($menuPath, '/') . '/'));
    if ($matches && strlen($menuPath) > $activeAdminLength) {
        $activeAdminHref = $href;
        $activeAdminLength = strlen($menuPath);
    }
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-head"><a class="brand brand--light" href="<?= e(url('/')) ?>" aria-label="<?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>, página inicial"><img src="<?= e(upload_asset(!empty($platformSettings['logo_dark_path']) ? $platformSettings['logo_dark_path'] : 'platform/logos/tuffer-logo-white.svg')) ?>" alt="<?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>"></a><button class="sidebar-collapse" type="button" data-sidebar-collapse aria-label="Recolher menu" title="Recolher menu"><?=$menuIcon('collapse')?></button><button class="sidebar-close" data-sidebar-close aria-label="Fechar menu">×</button></div>
    <nav class="sidebar-menu"><?php if($dashboardArea==='admin'):?>
        <div class="sidebar-primary"><p class="menu-section-title">Administração</p><?php foreach($adminOverview as [$icon,$label,$href]):$active=$activeAdminHref===$href;?><a class="menu-item <?=$active?'is-active':''?>" href="<?=e(url($href))?>" <?=$active?'aria-current="page"':''?> aria-label="<?=e($label)?>" data-sidebar-tooltip="<?=e($label)?>"><i><?=$menuIcon($icon)?></i><span><?=e($label)?></span></a><?php endforeach;?></div>
        <div class="sidebar-groups"><?php foreach($adminGroups as $group=>$items):$groupActive=(bool)array_filter($items,static fn(array $item):bool=>$activeAdminHref===$item[2]);?><details <?=$groupActive?'open':''?>><summary><span><?=e($group)?></span><i class="sidebar-section-chevron"><?=$menuIcon('chevron')?></i></summary><div><?php foreach($items as [$icon,$label,$href]):$active=$activeAdminHref===$href;?><a class="menu-item <?=$active?'is-active':''?>" href="<?=e(url($href))?>" <?=$active?'aria-current="page"':''?> aria-label="<?=e($label)?>" data-sidebar-tooltip="<?=e($label)?>"><i><?=$menuIcon($icon)?></i><span><?=e($label)?></span></a><?php endforeach;?></div></details><?php endforeach;?></div>
    <?php elseif($dashboardArea==='seller'):?><div class="sidebar-primary"><p class="menu-section-title"><?= e($dashboardName) ?></p><?php foreach($sellerMenu as [$icon,$label,$href]):$active=$icon==='settings'?str_starts_with($currentPath,(string)parse_url(url('/vendedor/configuracoes'),PHP_URL_PATH)):$isActiveMenu($href);?><a class="menu-item <?=$active?'is-active':''?>" href="<?=e(url($href))?>" <?=$active?'aria-current="page"':''?> aria-label="<?=e($label)?>" data-sidebar-tooltip="<?=e($label)?>"><i><?=$menuIcon($icon)?></i><span><?=e($label)?></span></a><?php endforeach;?></div>
    <?php else:?><p class="menu-section-title"><?= e($dashboardName) ?></p><?php foreach ($menus[$dashboardArea] as [$label,$href]):$active=$isActiveMenu($href);?><a class="menu-item <?= $active ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>" <?= $active ? 'aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?><?php endif;?></nav>
    <footer class="sidebar-profile"><div class="sidebar-profile__identity"><span><?=e(mb_strtoupper(mb_substr((string)($authUser['name']??'A'),0,1)))?></span><div><strong><?=e($authUser['name']??'Administrador')?></strong><small><?=e($authUser['email']??'')?></small></div></div><form action="<?= e(url('/sair')) ?>" method="post"><?= csrf_field() ?><button type="submit" title="Sair da conta"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h4M14 8l4 4-4 4M9 12h9"/></svg><span>Sair</span></button></form></footer>
</aside>
<div class="sidebar-backdrop" data-sidebar-close></div>
