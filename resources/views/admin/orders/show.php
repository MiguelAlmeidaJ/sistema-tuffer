<?php if(!$order):?><section class="panel"><div class="empty-state"><h3>Pedido não encontrado</h3></div></section><?php else:?>
<?php $money=static fn(float $value):string=>'R$ '.number_format($value,2,',','.'); ?>
<div class="order-detail-page order-detail-page--admin">
<nav class="order-detail-back"><a href="<?=e(url('/admin/pedidos'))?>">← Todos os pedidos</a></nav>
<header class="order-detail-hero">
<div class="order-detail-hero__copy"><span class="order-detail-kicker">GESTÃO DE PEDIDOS</span><h2>Pedido <span>#<?=e($order['code'])?></span></h2><p><?=e($order['customer_name'])?> · <?=e($order['customer_email'])?></p><p>Realizado em <?=date('d/m/Y H:i',strtotime($order['created_at']))?></p></div>
<div class="order-detail-hero__aside"><?php $status=$order['status'];require dirname(__DIR__,2).'/components/dashboard/status-badge.php';?><small>Situação atual</small></div>
</header>
<div class="order-detail-highlights order-detail-highlights--admin">
<div><span>Total do pedido</span><strong><?=$money((float)$order['grand_total'])?></strong></div>
<div><span>Valor dos produtos</span><strong><?=$money((float)$order['products_total'])?></strong></div>
<div><span>Remessas vinculadas</span><strong><?=count($sellerOrders)?></strong></div>
<div><span>Pagamentos registrados</span><strong><?=count($payments)?></strong></div>
</div>

<?php if (!empty($manualApprovalEligible)): ?>
<section class="panel" style="margin-bottom:1.25rem;border:1px solid #d6b680">
    <div class="panel-head"><div><small>LIBERAÇÃO ADMINISTRATIVA</small><h3>Liberar pedido para produção</h3></div></div>
    <p>Libera o pedido para a esteira de produção <strong>sem Pagar.me ou Asaas</strong>. O pagamento continuará <strong>não confirmado</strong> no financeiro. A autorização ficará registrada e a reserva de estoque será baixada.</p>
    <form method="post" action="<?=e(url('/admin/pedidos/'.$order['code'].'/aprovar-manualmente'))?>" data-confirm="Liberar este pedido para produção SEM confirmação de pagamento?">
        <?=csrf_field()?>
        <p><label>Justificativa administrativa<br><textarea name="reason" required minlength="12" maxlength="500" rows="3" style="width:100%" placeholder="Ex.: Pedido de teste liberado para produção durante troca do gateway."></textarea></label></p>
        <p><label class="checkbox"><input type="checkbox" name="operational_confirmed" value="1" required> Entendo que estou autorizando a produção sem confirmação financeira e assumo a responsabilidade por esta liberação.</label></p>
        <button class="button button--primary" type="submit">Liberar para produção</button>
    </form>
</section>
<?php endif; ?>
<?php if (!empty($manualApproval)): ?>
<section class="panel" style="margin-bottom:1.25rem">
    <div class="panel-head"><div><small>AUDITORIA</small><h3>Liberação operacional manual</h3></div></div>
    <p>Responsável: <?=e((string)$manualApproval['approved_by_name'])?> · <?=e(date('d/m/Y H:i',strtotime((string)$manualApproval['created_at'])))?><br>
    Valor do pedido: <?=$money(((int)$manualApproval['amount_cents']) / 100)?><br>
    Motivo: <?=e((string)$manualApproval['reason'])?></p>
    <p><small>Esta ação não confirma recebimento, liquidação, pagamento, split ou repasses.</small></p>
</section>
<?php endif; ?>
<div class="order-detail-section-heading"><div><span class="order-detail-kicker">OPERAÇÃO E LOGÍSTICA</span><h3>Remessas do pedido</h3></div><small><?=count($sellerOrders)?> <?=count($sellerOrders)===1?'remessa':'remessas'?></small></div>
<div class="admin-order-shipments">
<?php foreach($sellerOrders as $sellerOrder):?>
<?php
$shipmentId=(int)($sellerOrder['shipment_id']??0);
$currentServiceId=(string)($sellerOrder['service_id']??'');
$shipmentStatus=(string)($sellerOrder['shipment_status']??'');
$canReplace=$labelReplacementConfigured&&$shipmentId>0
    &&in_array((string)$order['status'],['paid','processing'],true)
    &&in_array((string)$sellerOrder['status'],['paid','processing'],true)
    &&!in_array($shipmentStatus,['posted','in_transit','delivered'],true);
$replacementOpen=$shipmentId>0&&$replaceShipmentId===$shipmentId;
$quoteState=is_array($sellerOrder['replacement_quote']??null)?$sellerOrder['replacement_quote']:null;
$quoteOptions=is_array($quoteState['options']??null)?array_values(array_filter($quoteState['options'],static fn($option):bool=>is_array($option)&&(string)($option['id']??'')!==$currentServiceId)):[];
$freightPaid=(float)($sellerOrder['shipping_cost']??$sellerOrder['shipping_total']??0);
$labelCost=($sellerOrder['label_actual_cost']??null)!==null?(float)$sellerOrder['label_actual_cost']:null;
?>
<section class="panel admin-order-shipment" id="remessa-<?=$shipmentId?>">
    <div class="panel-head admin-order-shipment__head">
        <div><small><?=e($sellerOrder['code'])?></small><h3><?=e($sellerOrder['store_name'])?></h3><p><?=e($sellerOrder['trade_name']?:$sellerOrder['store_name'])?></p></div>
        <?php $status=$sellerOrder['status'];require dirname(__DIR__,2).'/components/dashboard/status-badge.php';?>
    </div>

    <div class="table-wrap"><table><thead><tr><th>Produto</th><th>SKU</th><th>Qtd.</th><th>Total</th></tr></thead><tbody><?php foreach($sellerOrder['items'] as $item):?><tr><td><?=e($item['product_name'])?></td><td><?=e($item['sku'])?></td><td><?=(int)$item['quantity']?></td><td><?=$money((float)$item['total'])?></td></tr><?php endforeach;?></tbody></table></div>

    <div class="admin-order-shipment__summary">
        <article><small>TRANSPORTADORA</small><strong><?=e($sellerOrder['carrier_name']?:'Não definida')?></strong><span><?=e($sellerOrder['service_name']?:'Modalidade não definida')?></span></article>
        <article><small>FRETE DO CLIENTE</small><strong><?=$money($freightPaid)?></strong><span>Valor da venda permanece inalterado</span></article>
        <article><small>CUSTO DA ETIQUETA</small><strong><?=$labelCost!==null?$money($labelCost):'—'?></strong><span><?=e((string)($sellerOrder['label_purchase_status']?:'não solicitada'))?></span></article>
        <article><small>RASTREIO</small><strong><?=e($sellerOrder['tracking_code']?:'—')?></strong><span><?=e($shipmentStatus?:'pending')?></span></article>
    </div>

    <?php if(!empty($sellerOrder['label_error'])):?><div class="admin-order-shipment__error"><strong>Falha da etiqueta</strong><span><?=e($sellerOrder['label_error'])?></span></div><?php endif;?>

    <div class="admin-order-shipment__actions">
        <?php if(!empty($sellerOrder['label_url'])):?><a class="button button--primary" href="<?=e($sellerOrder['label_url'])?>" target="_blank" rel="noopener noreferrer">Imprimir etiqueta atual</a><?php endif;?>
        <?php if($trackingConfigured&&!empty($sellerOrder['external_id'])):?><form method="post" action="<?=e(url('/admin/pedidos/'.$order['code'].'/remessas/'.$shipmentId.'/sincronizar'))?>"><?=csrf_field()?><button class="button button--secondary">Sincronizar rastreamento</button></form><?php endif;?>
        <?php if($canReplace):?>
            <?php if($replacementOpen):?><a class="button button--secondary" href="<?=e(url('/admin/pedidos/'.$order['code']))?>#remessa-<?=$shipmentId?>">Fechar troca</a><?php else:?><a class="button admin-order-shipment__replace-button" href="<?=e(url('/admin/pedidos/'.$order['code'].'?trocar_remessa='.$shipmentId))?>#remessa-<?=$shipmentId?>">Trocar transportadora</a><?php endif;?>
        <?php endif;?>
    </div>

    <?php if($replacementOpen):?>
    <section class="admin-shipping-replacement">
        <div class="admin-shipping-replacement__head"><div><small>EXCEÇÃO LOGÍSTICA</small><h4>Cancelar a etiqueta atual e gerar outra</h4><p>A venda não será cancelada. O valor cobrado do cliente e a mesma NF-e serão mantidos; somente a remessa será substituída.</p></div><span>ADMIN</span></div>
        <?php if(!$labelReplacementConfigured):?>
            <div class="admin-shipping-replacement__notice">A integração de etiquetas do Melhor Envio não está disponível.</div>
        <?php elseif(!$canReplace):?>
            <div class="admin-shipping-replacement__notice admin-shipping-replacement__notice--danger">Esta remessa já avançou para postagem/trânsito ou o pedido não está em uma situação que permita a troca.</div>
        <?php elseif(!$quoteState):?>
            <div class="admin-shipping-replacement__notice">Não foi possível carregar uma recotação para esta remessa.</div>
        <?php elseif(!$quoteOptions):?>
            <div class="admin-shipping-replacement__notice"><?=e((string)($quoteState['message']??'Nenhuma modalidade alternativa está disponível agora.'))?></div>
        <?php else:?>
            <form method="post" action="<?=e(url('/admin/pedidos/'.$order['code'].'/remessas/'.$shipmentId.'/trocar-transportadora'))?>" data-confirm="Confirma a troca? A etiqueta atual será cancelada no Melhor Envio antes da compra da nova etiqueta.">
                <?=csrf_field()?>
                <div class="admin-shipping-replacement__options">
                    <?php foreach($quoteOptions as $index=>$option):?>
                    <?php $difference=(float)$option['price']-$freightPaid; ?>
                    <label class="admin-shipping-option">
                        <input type="radio" name="service_id" value="<?=e((string)$option['id'])?>" <?=$index===0?'checked':''?>>
                        <span class="admin-shipping-option__main"><b><?=e($option['carrier'])?> · <?=e($option['service'])?></b><small>Prazo estimado: <?=e((string)$option['min_days'])?>–<?=e((string)$option['max_days'])?> dias úteis</small></span>
                        <span class="admin-shipping-option__price"><b><?=$money((float)$option['price'])?></b><small><?=$difference>=0?'+ ':''?><?=$money($difference)?> vs. frete do cliente</small></span>
                    </label>
                    <?php endforeach;?>
                </div>
                <div class="admin-shipping-replacement__warning">
                    <strong>NF-e preservada</strong>
                    <p>A nova etiqueta usará a mesma chave de NF-e. Se a NF-e autorizada mencionar nominalmente a transportadora atual, confira a necessidade de CC-e antes da postagem.</p>
                </div>
                <label class="admin-shipping-replacement__reason">Motivo da troca<textarea name="reason" required minlength="5" maxlength="500" placeholder="Ex.: Loggi Express sem ponto de postagem compatível na cidade do vendedor."></textarea></label>
                <label class="checkbox admin-shipping-replacement__confirm"><input type="checkbox" name="confirm_replacement" value="1" required> Confirmo que a etiqueta atual deve ser cancelada e substituída. O cliente não será cobrado novamente.</label>
                <div class="admin-shipping-replacement__footer"><p>O Melhor Envio será consultado novamente no envio deste formulário. Se a etiqueta atual não puder mais ser cancelada, nenhuma troca será feita.</p><button class="button button--primary">Cancelar etiqueta e gerar nova</button></div>
            </form>
        <?php endif;?>
    </section>
    <?php endif;?>

    <?php if(!empty($sellerOrder['replacement_history'])):?><details class="admin-shipping-history"><summary>Histórico de trocas (<?=count($sellerOrder['replacement_history'])?>)</summary><div class="admin-shipping-history__list"><?php foreach($sellerOrder['replacement_history'] as $replacement):?><article><div><small><?=date('d/m/Y H:i',strtotime($replacement['created_at']))?> · <?=e($replacement['changed_by_name']?:'Administrador')?></small><strong><?=e($replacement['old_carrier_name']?:'—')?> / <?=e($replacement['old_service_name']?:'—')?> → <?=e($replacement['new_carrier_name'])?> / <?=e($replacement['new_service_name'])?></strong><span><?=e($replacement['reason']?:'Troca administrativa')?></span></div><div><small>Custo esperado</small><b><?=$money((float)$replacement['new_expected_cost'])?></b><?php if(($replacement['new_label_actual_cost']??null)!==null):?><span>Real: <?=$money((float)$replacement['new_label_actual_cost'])?></span><?php endif;?></div></article><?php endforeach;?></div></details><?php endif;?>
</section>
<?php endforeach;?>
</div>

<div class="order-detail-section-heading"><div><span class="order-detail-kicker">ACOMPANHAMENTO</span><h3>Histórico e informações financeiras</h3></div></div>
<div class="review-layout order-detail-review"><section class="panel"><div class="panel-head"><h3>Histórico operacional</h3></div><div class="compact-orders"><?php foreach($history as $event):?><div><strong><?=e($event['notes']?:$event['status'])?></strong><span><?=e($event['status'])?></span><b><?=date('d/m/Y H:i',strtotime($event['created_at']))?></b></div><?php endforeach;?></div></section><aside><section class="panel order-detail-totals"><h3>Resumo financeiro</h3><p>Produtos: <?=$money((float)$order['products_total'])?><br>Frete: <?=$money((float)$order['shipping_total'])?><br>Descontos: <?=$money((float)$order['discount_total'])?></p><strong class="order-detail-admin-total">Total: <?=$money((float)$order['grand_total'])?></strong></section><?php if($address):?><section class="panel"><h3>Entrega</h3><p><?=e($address['recipient_name'])?><br><?=e($address['street'].', '.$address['number'])?><br><?=e($address['city'].'/'.$address['state'])?> · <?=e($address['postal_code'])?></p></section><?php endif;?><section class="panel"><h3>Pagamentos</h3><?php foreach($payments as $payment):?><p><strong><?=e(strtoupper($payment['method']))?> · <?=$money((float)$payment['amount'])?></strong><br>Status: <?=e($payment['status'])?><br>ID externo: <?=e($payment['external_order_id']?:$payment['external_checkout_id']?:'—')?></p><?php if($payment['method']==='pix'&&$payment['integration_type']==='orders'&&$payment['status']==='paid'):?><form method="post" action="<?=e(url('/admin/pedidos/'.$order['code'].'/pagamentos/'.$payment['id'].'/estornar-pix'))?>" data-confirm="Confirmar solicitação de estorno integral deste Pix?"><?=csrf_field()?><button class="button button--secondary">Solicitar estorno integral</button></form><?php endif;?><?php endforeach;?></section></aside></div>
</div>
<?php endif;?>
