<?php if(!$order):?><section class="panel"><div class="empty-state"><h3>Pedido não encontrado</h3><p>Este pedido não pertence à loja selecionada.</p></div></section><?php else:?>
<div class="dashboard-heading"><div><a class="back-link" href="<?=e(url('/vendedor/pedidos'))?>">← Voltar para pedidos</a><span class="eyebrow"><?=e($currentStore['name'])?></span><h2><?=e($order['code'])?></h2><p>Pedido principal <?=e($order['order_code'])?> · <?=date('d/m/Y H:i',strtotime($order['order_created_at']))?></p></div><?php $status=$order['status'];require dirname(__DIR__,2).'/components/dashboard/status-badge.php';?></div>
<div class="review-layout"><div>
<section class="panel"><div class="panel-head"><h3>Itens da loja</h3><strong>R$ <?=number_format((float)$order['products_total'],2,',','.')?></strong></div><div class="table-wrap"><table><thead><tr><th>Produto</th><th>SKU</th><th>Qtd.</th><th>Total</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><strong><?=e($item['product_name'])?></strong></td><td><?=e($item['sku'])?></td><td><?=(int)$item['quantity']?></td><td>R$ <?=number_format((float)$item['total'],2,',','.')?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="panel">
    <div class="panel-head"><div><small>LOGÍSTICA</small><h3>Etiqueta e rastreamento</h3></div><?php if($shipment):?><?php $status=$shipment['status'];require dirname(__DIR__,2).'/components/dashboard/status-badge.php';?><?php endif;?></div>
    <?php if($shipment):?>
        <p><strong><?=e($shipment['service_name']?:'Entrega')?></strong> · <?=e($shipment['carrier_name']?:'Transportadora')?><br>Frete cobrado do cliente: R$ <?=number_format((float)$shipment['shipping_cost'],2,',','.')?><?php if(($shipment['label_actual_cost']??null)!==null):?><br>Custo da etiqueta: R$ <?=number_format((float)$shipment['label_actual_cost'],2,',','.')?><?php endif;?><?php if($shipment['tracking_code']):?><br>Rastreio: <strong><?=e($shipment['tracking_code'])?></strong><?php endif;?></p>
        <?php if(($shipment['label_purchase_status']??'')==='ready'&&!empty($shipment['label_url'])):?>
            <p><a class="button button--primary" href="<?=e($shipment['label_url'])?>" target="_blank" rel="noopener noreferrer">Imprimir etiqueta</a></p>
        <?php elseif(in_array($order['status'],['paid','processing'],true)):?>
            <?php if($labelPurchaseConfigured):?>
                <form class="resource-form" method="post" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/comprar-etiqueta'))?>">
                    <?=csrf_field()?>
                    <div class="form-grid"><label>Chave de acesso da NF-e<input name="invoice_key" inputmode="numeric" minlength="44" maxlength="44" pattern="[0-9]{44}" required value="<?=e($shipment['invoice_key']??'')?>" placeholder="44 dígitos"><small>Obrigatória para o envio comercial.</small></label></div>
                    <label class="checkbox"><input type="checkbox" name="confirm_purchase" value="1" required> Confirmo a compra da etiqueta com o saldo de fretes da Tuffer.</label>
                    <?php if(!empty($shipment['label_error'])):?><p class="field-error"><?=e($shipment['label_error'])?></p><?php endif;?>
                    <div class="form-actions"><button class="button button--primary"><?=in_array(($shipment['label_purchase_status']??''),['cart','purchased','generated'],true)?'Concluir geração da etiqueta':'Comprar e gerar etiqueta'?></button></div>
                </form>
            <?php else:?><p>A compra de etiquetas está temporariamente indisponível. Fale com o suporte da Tuffer.</p><?php endif;?>
        <?php else:?><p>A compra da etiqueta será liberada após a confirmação do pagamento.</p><?php endif;?>
        <?php if($trackingConfigured&&!empty($shipment['external_id'])):?><form method="post" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/sincronizar-rastreio'))?>"><?=csrf_field()?><button class="button button--secondary">Atualizar rastreamento</button></form><?php endif;?>
    <?php endif;?>
    <?php if($trackingEvents):?><div class="compact-orders"><?php foreach($trackingEvents as $event):?><div><strong><?=e($event['description'])?></strong><span><?=e($event['city']?($event['city'].'/'.$event['state']):$event['event_code'])?></span><b><?=date('d/m/Y H:i',strtotime($event['occurred_at']))?></b></div><?php endforeach;?></div><?php endif;?>
</section>
</div><aside>
<?php if($order['status']==='paid'):?><section class="panel"><h3>Próxima ação</h3><p>Confirme que a separação dos itens foi iniciada.</p><form method="post" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/preparar'))?>"><?=csrf_field()?><button class="button button--primary button--block">Iniciar preparação</button></form></section><?php endif;?>
<section class="panel"><h3>Cliente</h3><p><strong><?=e($order['customer_name'])?></strong><br><?=e($order['customer_email'])?><br><?=e($order['customer_phone']?:'Telefone não informado')?></p></section>
<?php if($address):?><section class="panel"><h3>Endereço de entrega</h3><p><strong><?=e($address['recipient_name'])?></strong><br><?=e($address['street'].', '.$address['number'])?><br><?=e($address['neighborhood'].' · '.$address['city'].'/'.$address['state'])?><br>CEP <?=e($address['postal_code'])?></p></section><?php endif;?>
<section class="panel"><h3>Financeiro da loja</h3><p>Produtos: R$ <?=number_format((float)$order['products_total'],2,',','.')?><br>Frete: R$ <?=number_format((float)$order['shipping_total'],2,',','.')?><br>Desconto: R$ <?=number_format((float)$order['discount_total'],2,',','.')?><br>Comissão: R$ <?=number_format((float)$order['commission_total'],2,',','.')?></p><strong>Líquido: R$ <?=number_format((float)$order['seller_net_total'],2,',','.')?></strong></section>
</aside></div><?php endif;?>
