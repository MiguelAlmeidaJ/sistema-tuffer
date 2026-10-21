<div class="dashboard-heading"><div><span class="eyebrow">TODAS AS LOJAS</span><h2>Mensagens</h2><p>Converse com clientes de todas as lojas que você administra, sem alternar de loja.</p></div></div>
<section class="panel conversation-list">
<?php foreach($conversations as $conversation):?>
<a href="<?=e(url('/vendedor/mensagens/'.$conversation['id']))?>">
<div class="message-avatar"><?=e(mb_substr($conversation['customer_name'],0,1))?></div>
<div><strong><?=e($conversation['customer_name'])?><?php if($conversation['unread']):?> <span class="unread-count"><?=(int)$conversation['unread']?></span><?php endif;?></strong>
<span><?=e($conversation['subject'])?></span><small><?=e($conversation['store_name'])?> · <?=e(mb_strimwidth($conversation['last_message']??'',0,100,'…'))?></small></div>
<time><?=date('d/m H:i',strtotime($conversation['last_message_at']?:$conversation['created_at']))?></time></a>
<?php endforeach;?>
<?php if(!$conversations):$emptyTitle='Nenhuma mensagem recebida';$emptyText='As conversas de todas as suas lojas aparecerão aqui.';require dirname(__DIR__,2).'/components/dashboard/empty-state.php';endif;?>
</section>
