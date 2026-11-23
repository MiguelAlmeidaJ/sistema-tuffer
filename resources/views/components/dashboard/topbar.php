<?php
$notifications = ['count'=>0,'items'=>[]];
if (($dashboardArea??'')==='seller') {
    try {
        $notifications=(new \App\Services\Messaging\SellerMessageNotificationService())->unreadForCurrentUser();
    } catch (\Throwable $e) {
        \App\Core\Logger::exception($e,[],'seller_message_notifications');
    }
}
?>
<header class="topbar">
<button class="menu-toggle" data-sidebar-open aria-label="Abrir menu"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h12M4 17h16"/></svg></button>
<button class="dashboard-desktop-toggle" type="button" data-sidebar-collapse aria-label="Recolher menu" title="Recolher menu"><svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9 4v16"/><path class="collapse-arrow" d="m16 9-3 3 3 3"/></svg></button><div><small><?=e($dashboardName)?></small><h1><?=e($pageTitle??'Dashboard')?></h1></div>
<?php if(($dashboardArea??'')==='seller'&&!empty($currentStore)): $availableStores=(new App\Services\Stores\SellerStoreContext())->stores(); if(count($availableStores)>1):?>
<form class="store-switcher" method="post" action="<?=e(url('/vendedor/selecionar-loja'))?>"><?=csrf_field()?>
<label><span>Loja atual</span><select name="store_id" onchange="this.form.submit()" aria-label="Selecionar loja">
<?php foreach($availableStores as $availableStore):?><option value="<?=$availableStore['id']?>" <?=(int)$availableStore['id']===(int)$currentStore['id']?'selected':''?>><?=e($availableStore['name'])?></option><?php endforeach;?>
</select></label></form><?php endif; endif;?>
<?php if(($dashboardArea??'')==='seller'):?>
<details class="seller-message-bell"><summary aria-label="Mensagens novas de todas as lojas" title="Mensagens não lidas">
<svg viewBox="0 0 24 24" aria-hidden="true" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
<?php if($notifications['count']>0):?><b class="seller-message-bell__count"><?=min(99,$notifications['count'])?></b><?php endif;?>
</summary><div class="seller-message-bell__panel">
<strong>Mensagens de todas as lojas</strong>
<?php if(!$notifications['items']):?><p>Nenhuma mensagem não lida.</p><?php endif;?>
<?php foreach($notifications['items'] as $item):?><a href="<?=e(url('/vendedor/mensagens/'.(int)$item['conversation_id']))?>">
<b><?=e($item['customer_name'])?></b><small><?=e($item['store_name'])?> · <?=e($item['subject'])?></small><em><?= (int)$item['unread'] ?> nova(s)</em></a><?php endforeach;?>
<a class="seller-message-bell__all" href="<?=e(url('/vendedor/mensagens'))?>">Ver todas as conversas →</a>
</div></details>
<?php endif;?>
<?php if(in_array(($dashboardArea??''),['seller','admin','customer'],true)):?><details class="user-dropdown"><summary class="user-chip" aria-label="Opções da conta"><span><?=e(mb_strtoupper(mb_substr((string)($authUser['name']??'U'),0,1)))?></span><div><strong><?=e($authUser['name']??'')?></strong><small><?=e($authUser['email']??'')?></small></div><svg class="user-dropdown__chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary><div class="user-dropdown__menu"><div class="user-dropdown__label">Minha conta</div><a href="<?=e(url(($dashboardArea??'')==='admin'?'/admin/minha-conta':(($dashboardArea??'')==='customer'?'/minha-conta/perfil':'/vendedor/minha-conta')))?>"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>Meu perfil</a><a <?=($dashboardArea??'')==='customer'?'':'data-password-modal-open'?> href="<?=e(url(($dashboardArea??'')==='admin'?'/admin/minha-conta/senha':(($dashboardArea??'')==='customer'?'/minha-conta/perfil#senha':'/vendedor/minha-conta/senha')) )?>"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Alterar minha senha</a><div class="user-dropdown__divider"></div><form action="<?=e(url('/sair'))?>" method="post"><?=csrf_field()?><button type="submit"><svg viewBox="0 0 24 24"><path d="M10 5H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h4m4-11 4 4-4 4M9 12h11"/></svg>Sair da conta</button></form></div></details><?php else:?><div class="user-chip"><span><?=e(mb_substr($authUser['name']??'U',0,1))?></span><div><strong><?=e($authUser['name']??'')?></strong><small><?=e($authUser['email']??'')?></small></div></div><?php endif;?>
</header>
