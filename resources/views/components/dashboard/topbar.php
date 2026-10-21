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
<button class="menu-toggle" data-sidebar-open aria-label="Abrir menu">☰</button>
<div><small><?=e($dashboardName)?></small><h1><?=e($pageTitle??'Dashboard')?></h1></div>
<?php if(($dashboardArea??'')==='seller'&&!empty($currentStore)): $availableStores=(new App\Services\Stores\SellerStoreContext())->stores();?>
<form class="store-switcher" method="post" action="<?=e(url('/vendedor/selecionar-loja'))?>"><?=csrf_field()?>
<label><span>Loja atual</span><select name="store_id" onchange="this.form.submit()" aria-label="Selecionar loja">
<?php foreach($availableStores as $availableStore):?><option value="<?=$availableStore['id']?>" <?=(int)$availableStore['id']===(int)$currentStore['id']?'selected':''?>><?=e($availableStore['name'])?></option><?php endforeach;?>
</select></label></form><?php endif;?>
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
<div class="user-chip"><span><?=e(mb_substr($authUser['name']??'U',0,1))?></span><div><strong><?=e($authUser['name']??'')?></strong><small><?=e($authUser['email']??'')?></small></div></div>
</header>
