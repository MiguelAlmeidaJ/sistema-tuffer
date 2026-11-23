<section class="address-page">
<header class="address-page__header"><div><span class="eyebrow">SUAS ENTREGAS</span><h2>Meus endereços</h2><p>Escolha onde receber suas compras. Você pode editar seus dados a qualquer momento.</p></div><button type="button" class="address-page__add" data-address-modal-open>Novo endereço</button></header>
<?php if($addresses):?><div class="address-page__list">
<?php foreach($addresses as $address):?><article class="address-page__card">
<div class="address-page__card-head"><h3><?=e($address['label']?:'Endereço de entrega')?></h3><?php if($address['is_default']):?><span class="address-page__primary">Principal</span><?php endif;?></div>
<p class="address-page__recipient"><?=e($address['recipient_name'])?></p>
<address><?=e($address['street'])?>, <?=e($address['number'])?><?=!empty($address['complement'])?' · '.e($address['complement']):''?><br><?=e($address['neighborhood'])?> — <?=e($address['city'])?>/<?=e($address['state'])?><br>CEP <?=e($address['postal_code'])?></address>
<div class="address-page__actions"><a href="<?=e(url('/minha-conta/enderecos/'.(int)$address['id'].'/editar'))?>">Editar endereço</a><form action="<?=e(url('/minha-conta/enderecos/'.(int)$address['id']))?>" method="post" onsubmit="return confirm('Excluir este endereço?')"><?=csrf_field()?><input type="hidden" name="_method" value="DELETE"><button type="submit">Excluir</button></form></div>
</article><?php endforeach;?></div>
<?php else:?><section class="address-page__empty"><h3>Seu primeiro endereço de entrega</h3><p>Cadastre um endereço para facilitar suas próximas compras. Você pode informar o CEP ou usar a localização para ajudar no preenchimento.</p><button type="button" data-address-modal-open>Adicionar endereço</button></section><?php endif;?>
</section>
<?php if(!$addresses):?><?php $addressModalAutoOpen=true;require dirname(__DIR__,2).'/components/dashboard/customer-address-modal.php';?><?php else:?><?php $addressModalAutoOpen=false;require dirname(__DIR__,2).'/components/dashboard/customer-address-modal.php';?><?php endif;?>