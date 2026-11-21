<?php
$shippingTotal=(float)($shipping['shipping_total']??0);
$grandTotal=max(0,(float)$cart['total']+$shippingTotal);
?>
<?php if (!empty($removedPaymentBlocked)): ?><div class="container"><div class="settings-note"><span>!</span><p>Alguns itens ficaram indisponíveis para pagamento e foram retirados. Revise o carrinho abaixo.</p></div></div><?php endif; ?>

<section class="cart-heading cart-heading--compact container commerce-refresh-heading cart-clean-heading"><div><h1><?=($cart['type']??'retail')==='wholesale'?'Carrinho de atacado':'Seu carrinho'?></h1><p><?=$cart['count']?> <?=$cart['count']===1?'item':'itens'?> na sacola</p></div><div class="cart-mode-actions"><a href="<?=e(url('/produtos'))?>">← Continuar comprando</a><?php if(($cart['type']??'retail')==='wholesale'):?><form method="post" action="<?=e(url('/carrinho/modo'))?>"><?=csrf_field()?><input type="hidden" name="mode" value="retail"><button>Voltar ao modo varejo</button></form><?php endif;?></div></section>
<?php if(($cart['type']??'retail')==='wholesale'):?><div class="wholesale-cart-notice container"><strong>Você está comprando no atacado</strong><span>O carrinho de varejo permanece separado.</span></div><?php endif;?>
<?php if(($authUser['type']??null)!==null && ($authUser['type']??null)!=='customer' && !empty($cart['items'])): ?>
<div class="container cart-clean-staff-note" role="status">Você está conectado como <?=e(($authUser['type']??'')==='admin'?'administrador':'equipe da loja')?>. Para finalizar uma compra, entre com uma conta de cliente. <a href="<?=e(url(($authUser['type']??'')==='admin'?'/admin':'/vendedor'))?>">Ir ao painel</a></div>
<?php endif; ?>
<?php if(!$cart['items']):?>
<section class="cart-empty container"><div class="empty-illustration">T</div><h2>Seu carrinho está vazio.</h2><p>Explore produtos das marcas Tuffer e Linda Flor.</p><a class="button button--primary" href="<?=e(url('/produtos'))?>">Explorar produtos</a></section>
<?php else:?>
<div class="cart-layout cart-container container"><div class="cart-content"><div class="cart-stores" id="cart-stores">
<?php foreach($cart['groups'] as $group):$storeShipping=$shipping['stores'][(int)$group['store_id']]??null;$selectedQuote=$storeShipping['selected_option']??null;?><section class="cart-store"><header class="cart-clean-group-title"><strong>Produtos</strong><span><?=count($group['items'])?> <?=count($group['items'])===1?'item':'itens'?></span></header>
<?php foreach($group['items'] as $item):$minQty=($cart['type']??'retail')==='wholesale'?max(1,(int)$item['wholesale_min_quantity']):1;?><article class="cart-item">
<a class="cart-item__image" href="<?=e(url('/produto/'.$item['slug']))?>"><?php if($item['image_url']):?><img src="<?=e($item['image_url'])?>" alt="<?=e($item['name'])?>"><?php else:?><span class="product-placeholder"><b>TUFFER</b></span><?php endif;?></a>
<div class="cart-item__info"><a href="<?=e(url('/produto/'.$item['slug']))?>"><h2><?=e($item['name'])?></h2></a><div class="cart-item__details"><?php if((int)$item['package_count']>1):?><span>Kit com <?=(int)$item['package_count']?> unidades</span><?php endif;?><?php if($item['variant_name']&&mb_strtolower($item['variant_name'])!=='padrão'):?><span>Variação: <?=e($item['variant_name'])?></span><?php endif;?><?php if($cart['type']==='wholesale'):?><span>Atacado</span><?php endif;?></div><div class="cart-item__links"><form action="<?=e(url('/carrinho/item/'.$item['id']))?>" method="post"><?=csrf_field()?><input type="hidden" name="_method" value="DELETE"><button class="remove-item">Remover</button></form><?php if(($authUser['type']??null)==='customer'):?><form action="<?=e(url('/carrinho/item/'.$item['id'].'/salvar'))?>" method="post"><?=csrf_field()?><button>Salvar para depois</button></form><?php endif;?></div></div>
<div class="cart-item__quantity"><span class="sr-only">Quantidade</span><form action="<?=e(url('/carrinho/item/'.$item['id']))?>" method="post"><?=csrf_field()?><input type="hidden" name="_method" value="PUT"><div class="quantity-stepper"><button name="quantity" value="<?=max($minQty,(int)$item['quantity']-1)?>" <?=(int)$item['quantity']<=$minQty?'disabled':''?> aria-label="Diminuir quantidade">−</button><output><?=(int)$item['quantity']?></output><button name="quantity" value="<?=min((int)$item['available'],(int)$item['quantity']+1)?>" <?=(int)$item['quantity']>=(int)$item['available']?'disabled':''?> aria-label="Aumentar quantidade">+</button></div></form></div>
<div class="cart-item__price"><small>R$ <?=number_format((float)$item['piece_price'],2,',','.')?> cada</small><strong>R$ <?=number_format((float)$item['line_total'],2,',','.')?></strong></div></article><?php endforeach;?>
<footer class="cart-clean-store-subtotal"><div><span>Subtotal dos produtos</span><?php if($group['coupon']):?><small>Cupom <?=e($group['coupon']['code'])?>: − R$ <?=number_format((float)$group['discount'],2,',','.')?></small><?php endif;?></div><strong>R$ <?=number_format((float)$group['subtotal']-(float)$group['discount'],2,',','.')?></strong></footer><?php if($cart['type']==='wholesale'&&!$group['minimum_met']):?><div class="store-minimum-warning">Complete o mínimo da loja<?php if($group['minimum_quantity']):?>: <?=$group['minimum_quantity']?> unidades<?php endif;?><?php if($group['minimum_total']):?> · R$ <?=number_format((float)$group['minimum_total'],2,',','.')?><?php endif;?></div><?php endif;?></section><?php endforeach;?></div>
<section class="cart-delivery-card cart-delivery-card--choice" id="cart-delivery">
  <div class="cart-clean-delivery-heading"><h2>Entrega</h2><?php if(!empty($shipping['selection_complete'])):?><span class="cart-clean-selected">Frete selecionado</span><?php endif;?></div>
  <?php if (!empty($preferredAddress)): ?>
  <div class="cart-delivery-address"><span>Enviar para</span><strong><?=e($preferredAddress['street'].', '.$preferredAddress['number'].' · '.$preferredAddress['city'].'/'.$preferredAddress['state'])?></strong><small>CEP <?=e($preferredAddress['postal_code'])?></small>
    <?php if(preg_replace('/\\D+/', '', (string)$preferredAddress['postal_code'])!==(string)($cart['postal_code']??'')): ?>
    <form method="post" action="<?=e(url('/carrinho/frete'))?>"><?=csrf_field()?><input type="hidden" name="postal_code" value="<?=e((string)$preferredAddress['postal_code'])?>"><button type="submit" class="cart-delivery-use-address">Calcular para este endereço</button></form>
    <?php endif; ?></div>
  <?php endif; ?>
  <form method="post" action="<?=e(url('/carrinho/frete'))?>" class="cart-delivery-postal">
    <?=csrf_field()?>
    <label>CEP de entrega<input name="postal_code" data-mask="cep" inputmode="numeric" placeholder="00000-000" value="<?=e($cart['postal_code']??'')?>" required></label>
    <button class="button button--dark">Alterar CEP</button>
  </form>
  <?php if ($cart['postal_code']): ?>
    <div class="cart-shipping-results cart-shipping-results--choice">
      <?php foreach ($cart['groups'] as $group):
        $storeId=(int)$group['store_id'];$state=$shipping['stores'][$storeId]??[];
        $selected=(string)($state['selected']??''); ?>
        <div class="cart-shipping-group">
          <?php if(empty($state['options'])): ?><p><?=e($state['message']??'Sem opções de frete para este CEP.')?></p>
          <?php else: ?>
            <div class="cart-shipping-options">
              <?php foreach ($state['options'] as $option): ?>
                <form method="post" action="<?=e(url('/carrinho/frete/escolher'))?>" class="cart-shipping-choice">
                  <?=csrf_field()?>
                  <input type="hidden" name="store_id" value="<?=$storeId?>">
                  <input type="hidden" name="service_id" value="<?=e((string)$option['id'])?>">
                  <button type="submit" class="cart-shipping-choice__button <?=$selected===(string)$option['id']?'is-selected':''?>" aria-pressed="<?=$selected===(string)$option['id']?'true':'false'?>">
                    <span class="cart-shipping-choice__indicator" aria-hidden="true"><?=$selected===(string)$option['id']?'✓':''?></span>
                    <span class="cart-shipping-choice__detail"><strong><?=e($option['carrier'].' · '.$option['service'])?></strong><small>Chega entre <?=e($option['arrival_min'])?> e <?=e($option['arrival_max'])?></small></span>
                    <span class="cart-shipping-choice__price">R$ <?=number_format((float)$option['price'],2,',','.')?></span>
                  </button>
                </form>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if(empty($shipping['selection_complete'])): ?><p class="cart-shipping-prompt">Selecione uma opção de entrega para continuar.</p><?php else: ?><?php endif; ?>
</section>
</div>
<aside class="order-summary cart-summary"><h2>Resumo</h2><div class="coupon-block"><?php $hasCoupon=false;foreach($cart['groups'] as $group)if($group['coupon'])$hasCoupon=true;?><details class="cart-clean-coupon" <?=$hasCoupon?'open':''?>><summary><?=$hasCoupon?'Cupom aplicado':'Adicionar cupom'?></summary><?php if($hasCoupon):?><?php foreach($cart['groups'] as $group):if(!$group['coupon'])continue;?><div class="applied-coupon"><span><b>✓ <?=e($group['coupon']['code'])?> aplicado</b><small><?=e($group['store_name'])?></small></span><form method="post" action="<?=e(url('/carrinho/cupom/'.$group['store_id']))?>"><?=csrf_field()?><input type="hidden" name="_method" value="DELETE"><button aria-label="Remover cupom">×</button></form></div><?php endforeach;?><?php else:?><form method="post" action="<?=e(url('/carrinho/cupom'))?>"><?=csrf_field()?><input name="code" maxlength="60" placeholder="Código do cupom" required><button>Aplicar</button></form><?php endif;?></details></div><dl><div><dt>Produtos</dt><dd>R$ <?=number_format((float)$cart['subtotal'],2,',','.')?></dd></div><div class="discount-row"><dt>Descontos</dt><dd>− R$ <?=number_format((float)$cart['discount_total'],2,',','.')?></dd></div><div><dt>Entrega</dt><dd><?=!empty($shipping['selection_complete'])?'R$ '.number_format($shippingTotal,2,',','.'):'Selecionar frete'?></dd></div></dl><div class="order-summary__total"><span>Total</span><strong>R$ <?=number_format($grandTotal,2,',','.')?></strong></div><?php $canContinue=($authUser['type']??null)===null || ($authUser['type']??null)==='customer'; if($cart['minimums_met']&&!empty($shipping['selection_complete'])&&$canContinue):?><a class="button button--primary button--block" href="<?=e(url('/checkout'))?>">Finalizar compra →</a><?php elseif(!$canContinue):?><a class="button button--secondary button--block" href="<?=e(url(($authUser['type']??'')==='admin'?'/admin':'/vendedor'))?>">Acessar meu painel</a><?php else:?><a class="button button--secondary button--block" href="#cart-delivery"><?=$cart['minimums_met']?'Escolher entrega':'Complete os mínimos'?></a><?php endif;?></aside></div>
<div class="mobile-cart-action"><span><small>Total estimado</small><strong>R$ <?=number_format($grandTotal,2,',','.')?></strong></span><?php if($cart['minimums_met']&&!empty($shipping['selection_complete'])&&$canContinue):?><a class="button button--primary" href="<?=e(url('/checkout'))?>">Finalizar</a><?php elseif(!$canContinue):?><a class="button button--secondary" href="<?=e(url(($authUser['type']??'')==='admin'?'/admin':'/vendedor'))?>">Meu painel</a><?php else:?><a class="button button--secondary" href="#cart-delivery">Escolher entrega</a><?php endif;?></div>
<?php endif;?>
