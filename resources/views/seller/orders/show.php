<?php if(!$order):?><section class="panel"><div class="empty-state"><h3>Pedido não encontrado</h3><p>Este pedido não pertence à loja selecionada.</p></div></section><?php else:?>
<?php
$fiscalMode=(string)($fiscalProfile['issuance_mode']??'manual');
$fiscalEnabled=!empty($fiscalProfile['enabled']);
$fiscalEligible=in_array((string)$order['status'],['paid','processing','shipped','delivered'],true);
$fiscalStatus=(string)($fiscalDocument['status']??($fiscalEligible?'awaiting_manual':'pending'));
$fiscalStatusLabels=['pending'=>'Aguardando pagamento','configuration_required'=>'Configuração necessária','awaiting_manual'=>'Aguardando emissão','awaiting_external'=>'Aguardando integração','processing'=>'Processando','authorized'=>'NF-e autorizada','cancelled'=>'NF-e cancelada','rejected'=>'NF-e rejeitada','error'=>'Erro fiscal'];
$fiscalStatusLabel=$fiscalStatusLabels[$fiscalStatus]??ucfirst(str_replace('_',' ',$fiscalStatus));
$fiscalTotal=max(0,(float)$order['products_total']+(float)$order['shipping_total']-(float)$order['discount_total']);
$money=static fn(float $value):string=>'R$ '.number_format($value,2,',','.');
$customerCopy="Nome: ".(string)$order['customer_name']."\nCPF/CNPJ: ".((string)($order['customer_document']??'')?:'Não informado')."\nE-mail: ".(string)$order['customer_email']."\nTelefone: ".((string)($order['customer_phone']??'')?:'Não informado');
$addressCopy='';
if($address){$addressCopy="Destinatário: ".(string)($address['recipient_name']??$order['customer_name'])."\nCEP: ".(string)($address['postal_code']??'')."\nEndereço: ".(string)($address['street']??'').', '.(string)($address['number']??'')."\nComplemento: ".((string)($address['complement']??'')?:'—')."\nBairro: ".(string)($address['neighborhood']??'')."\nCidade/UF: ".(string)($address['city']??'').'/'.(string)($address['state']??'');}
$itemCopy=[];foreach($items as $item){$itemCopy[]=(int)$item['quantity'].'x '.(string)$item['product_name'].' | SKU: '.((string)($item['sku']??'')?:'—').' | Unit.: '.$money((float)$item['unit_price']).' | Total: '.$money((float)$item['total']);}
$orderCopy="Pedido da loja: ".(string)$order['code']."\nPedido principal: ".(string)$order['order_code']."\n\nCLIENTE\n".$customerCopy.($addressCopy!==''?"\n\nENDEREÇO\n".$addressCopy:'')."\n\nITENS\n".implode("\n",$itemCopy)."\n\nProdutos: ".$money((float)$order['products_total'])."\nFrete: ".$money((float)$order['shipping_total'])."\nDesconto: ".$money((float)$order['discount_total'])."\nTOTAL PARA EMISSÃO: ".$money($fiscalTotal);
?>
<div class="dashboard-heading"><div><a class="back-link" href="<?=e(url('/vendedor/pedidos'))?>">← Voltar para pedidos</a><span class="eyebrow"><?=e($currentStore['name'])?></span><h2><?=e($order['code'])?></h2><p>Pedido principal <?=e($order['order_code'])?> · <?=date('d/m/Y H:i',strtotime($order['order_created_at']))?></p></div><?php $status=$order['status'];require dirname(__DIR__,2).'/components/dashboard/status-badge.php';?></div>
<div class="review-layout"><div>
<section class="panel seller-order-fiscal" id="fiscal">
    <div class="seller-order-fiscal__head">
        <div><small>EMISSÃO FISCAL</small><h3>Dados para emitir a NF-e</h3><p>Use este resumo para lançar o pedido manualmente no Tiny, FFAdmin ou outro sistema fiscal da loja.</p></div>
        <span class="seller-order-fiscal__status seller-order-fiscal__status--<?=e($fiscalStatus)?>"><?=e($fiscalStatusLabel)?></span>
    </div>

    <div class="seller-order-copybar">
        <div><strong>Copie sem redigitar</strong><span>Os dados abaixo são somente da <?=e($currentStore['name'])?> neste pedido.</span></div>
        <div class="seller-order-copybar__actions">
            <button type="button" class="order-copy-button" data-copy="<?=e($customerCopy)?>">Copiar cliente</button>
            <?php if($addressCopy!==''):?><button type="button" class="order-copy-button" data-copy="<?=e($addressCopy)?>">Copiar endereço</button><?php endif;?>
            <button type="button" class="order-copy-button order-copy-button--primary" data-copy="<?=e($orderCopy)?>">Copiar tudo para emissão</button>
        </div>
    </div>

    <div class="seller-order-fiscal__grid">
        <article class="seller-order-data-card"><header><span>01</span><div><small>DESTINATÁRIO</small><h4>Cliente</h4></div></header><dl><div><dt>Nome</dt><dd><?=e($order['customer_name'])?></dd></div><div><dt>CPF/CNPJ</dt><dd><?=e((string)($order['customer_document']?:'Não informado'))?></dd></div><div><dt>E-mail</dt><dd><?=e($order['customer_email'])?></dd></div><div><dt>Telefone</dt><dd><?=e((string)($order['customer_phone']?:'Não informado'))?></dd></div></dl></article>
        <article class="seller-order-data-card"><header><span>02</span><div><small>ENTREGA</small><h4>Endereço</h4></div></header><?php if($address):?><dl><div><dt>CEP</dt><dd><?=e($address['postal_code'])?></dd></div><div><dt>Endereço</dt><dd><?=e($address['street'].', '.$address['number'])?></dd></div><div><dt>Bairro</dt><dd><?=e($address['neighborhood'])?></dd></div><div><dt>Cidade / UF</dt><dd><?=e($address['city'].' / '.$address['state'])?></dd></div><?php if(!empty($address['complement'])):?><div><dt>Complemento</dt><dd><?=e($address['complement'])?></dd></div><?php endif;?></dl><?php else:?><p class="seller-order-data-card__empty">Endereço não informado.</p><?php endif;?></article>
        <article class="seller-order-data-card seller-order-data-card--totals"><header><span>03</span><div><small>VALORES FISCAIS</small><h4>Resumo</h4></div></header><dl><div><dt>Produtos</dt><dd><?=$money((float)$order['products_total'])?></dd></div><div><dt>Frete</dt><dd><?=$money((float)$order['shipping_total'])?></dd></div><div><dt>Desconto</dt><dd>- <?=$money((float)$order['discount_total'])?></dd></div><div class="is-total"><dt>Total para emissão</dt><dd><?=$money($fiscalTotal)?></dd></div></dl></article>
    </div>

    <div class="seller-order-fiscal__items"><div class="seller-order-fiscal__items-head"><div><small>ITENS DA LOJA</small><h4>Produtos deste documento</h4></div><strong><?=count($items)?> <?=count($items)===1?'item':'itens'?></strong></div><div class="table-wrap"><table><thead><tr><th>Produto</th><th>SKU</th><th>Qtd.</th><th>Unitário</th><th>Total</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><strong><?=e($item['product_name'])?></strong></td><td><?=e((string)($item['sku']?:'—'))?></td><td><?=(int)$item['quantity']?></td><td><?=$money((float)$item['unit_price'])?></td><td><strong><?=$money((float)$item['total'])?></strong></td></tr><?php endforeach;?></tbody></table></div></div>

    <div class="seller-order-invoice">
        <?php if($fiscalDocument&&in_array((string)$fiscalDocument['status'],['authorized','cancelled'],true)):?>
            <div class="seller-order-invoice__done"><div class="seller-order-invoice__icon">✓</div><div><small>NF-e VINCULADA</small><h4>Nº <?=e((string)$fiscalDocument['number'])?> · série <?=e((string)$fiscalDocument['series'])?></h4><p>Chave de acesso <code><?=e((string)$fiscalDocument['access_key'])?></code></p><?php if(!empty($fiscalDocument['protocol'])):?><p>Protocolo <?=e((string)$fiscalDocument['protocol'])?></p><?php endif;?></div><button type="button" class="order-copy-button" data-copy="<?=e((string)$fiscalDocument['access_key'])?>">Copiar chave</button></div>
        <?php elseif(!$fiscalEnabled):?>
            <div class="seller-order-invoice__notice"><div><small>ANTES DE REGISTRAR</small><h4>Ative os dados fiscais desta loja</h4><p>A loja precisa estar com o perfil fiscal configurado para vincular uma NF-e ao pedido.</p></div><a class="button button--secondary" href="<?=e(url('/vendedor/fiscal'))?>">Configurar Fiscal</a></div>
        <?php elseif($fiscalMode!=='manual'):?>
            <div class="seller-order-invoice__notice"><div><small>MODO <?=e(mb_strtoupper($fiscalMode))?></small><h4>Este pedido está usando integração fiscal</h4><p>Os dados continuam disponíveis para conferência, mas a NF-e é sincronizada pelo fluxo configurado da loja.</p></div><a class="button button--secondary" href="<?=e(url('/vendedor/fiscal'))?>">Ver configuração</a></div>
        <?php elseif(!$fiscalEligible):?>
            <div class="seller-order-invoice__notice"><div><small>EMISSÃO BLOQUEADA</small><h4>Aguarde a confirmação do pagamento</h4><p>A NF-e só pode ser vinculada quando o pedido desta loja estiver pago.</p></div></div>
        <?php else:?>
            <div class="seller-order-invoice__form-head"><div><small>DEPOIS DE EMITIR NO SEU SISTEMA</small><h4>Registrar NF-e emitida</h4><p>Informe os dados retornados pelo Tiny/FFAdmin. XML e DANFE são opcionais, mas recomendados.</p></div></div>
            <form class="seller-order-invoice__form" method="post" enctype="multipart/form-data" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/nota-fiscal'))?>">
                <?=csrf_field()?>
                <div class="seller-order-invoice__fields"><label>Número da NF-e<input name="number" inputmode="numeric" required placeholder="Ex.: 1542"></label><label>Série<input name="series" inputmode="numeric" min="1" max="999" required value="<?=e((string)($fiscalProfile['nfe_series']??1))?>"></label><label class="is-wide">Chave de acesso<input name="access_key" inputmode="numeric" minlength="44" maxlength="44" pattern="[0-9]{44}" required placeholder="44 dígitos"></label><label>Protocolo <span>opcional</span><input name="protocol" maxlength="100" placeholder="Protocolo de autorização"></label><label>Referência externa <span>opcional</span><input name="external_reference" maxlength="150" placeholder="ID no Tiny/FFAdmin"></label><label>XML da NF-e <span>opcional</span><input type="file" name="xml" accept=".xml,application/xml,text/xml"></label><label>DANFE PDF <span>opcional</span><input type="file" name="danfe" accept=".pdf,application/pdf"></label></div>
                <div class="seller-order-invoice__footer"><p>O registro não emite a nota. Ele apenas vincula à Tuffer a NF-e que você já emitiu no sistema fiscal da loja.</p><button class="button button--primary">Registrar NF-e neste pedido</button></div>
            </form>
        <?php endif;?>
    </div>
</section>

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
                    <div class="form-grid"><label>Chave de acesso da NF-e<input name="invoice_key" inputmode="numeric" minlength="44" maxlength="44" pattern="[0-9]{44}" required value="<?=e($shipment['invoice_key']??($fiscalDocument['access_key']??''))?>" placeholder="44 dígitos"><small>Obrigatória para o envio comercial.</small></label></div>
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
<section class="panel"><h3>Cliente</h3><p><strong><?=e($order['customer_name'])?></strong><br><?php if(!empty($order['customer_document'])):?><?=e($order['customer_document'])?><br><?php endif;?><?=e($order['customer_email'])?><br><?=e($order['customer_phone']?:'Telefone não informado')?></p></section>
<?php if($address):?><section class="panel"><h3>Endereço de entrega</h3><p><strong><?=e($address['recipient_name'])?></strong><br><?=e($address['street'].', '.$address['number'])?><br><?=e($address['neighborhood'].' · '.$address['city'].'/'.$address['state'])?><br>CEP <?=e($address['postal_code'])?></p></section><?php endif;?>
<section class="panel"><h3>Financeiro da loja</h3><p>Produtos: R$ <?=number_format((float)$order['products_total'],2,',','.')?><br>Frete: R$ <?=number_format((float)$order['shipping_total'],2,',','.')?><br>Desconto: R$ <?=number_format((float)$order['discount_total'],2,',','.')?><br>Comissão: R$ <?=number_format((float)$order['commission_total'],2,',','.')?></p><strong>Líquido: R$ <?=number_format((float)$order['seller_net_total'],2,',','.')?></strong></section>
</aside></div>
<div class="order-copy-toast" data-copy-toast role="status" aria-live="polite">Copiado</div>
<script>
document.addEventListener('click',function(event){const button=event.target.closest('[data-copy]');if(!button)return;const text=button.getAttribute('data-copy')||'';const done=function(){const toast=document.querySelector('[data-copy-toast]');if(!toast)return;toast.textContent='Copiado para a área de transferência';toast.classList.add('is-visible');window.setTimeout(function(){toast.classList.remove('is-visible');},1800);};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(text).then(done).catch(function(){});return;}const area=document.createElement('textarea');area.value=text;area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();try{document.execCommand('copy');done();}catch(e){}area.remove();});
</script>
<?php endif;?>
