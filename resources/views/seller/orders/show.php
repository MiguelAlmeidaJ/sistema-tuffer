<?php if(!$order):?>
<section class="panel"><div class="empty-state"><h3>Pedido não encontrado</h3><p>Este pedido não pertence à loja selecionada.</p></div></section>
<?php else:?>
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

$labelReady=$shipment&&($shipment['label_purchase_status']??'')==='ready'&&!empty($shipment['label_url']);
$shipmentStatus=(string)($shipment['status']??'pending');
$shipmentPosted=in_array($shipmentStatus,['posted','in_transit','delivered'],true)||in_array((string)$order['status'],['shipped','delivered'],true);
$fiscalAuthorized=$fiscalDocument&&($fiscalDocument['status']??'')==='authorized';
$carrierName=trim((string)($shipment['carrier_name']??''));
$serviceName=trim((string)($shipment['service_name']??''));
$carrierSearch=mb_strtolower($carrierName);
$serviceSearch=mb_strtolower($serviceName);
$isLoggi=str_contains($carrierSearch,'loggi');
$isLoggiExpress=$isLoggi&&str_contains($serviceSearch,'express');
$isLoggiPonto=$isLoggi&&str_contains($serviceSearch,'ponto');
$isLoggiPickup=$isLoggi&&(str_contains($serviceSearch,'coleta')||str_contains($serviceSearch,'pickup'));
$postingTitle='Confira o local de postagem da transportadora';
$postingDescription='Antes de sair, confira na etiqueta e nas orientações do Melhor Envio qual unidade recebe esta modalidade.';
$postingActionLabel='Consultar pontos de postagem';
$postingActionUrl='https://lp.melhorenvio.com.br/ponto-parceiro/';
if($isLoggiExpress){
    $postingTitle='Leve a encomenda a um Ponto Parceiro / Pegaki';
    $postingDescription='A modalidade Loggi Express do Melhor Envio é postada em uma unidade Ponto Parceiro / Pegaki compatível. Embale o pedido, cole a etiqueta e entregue o volume no balcão.';
    $postingActionLabel='Encontrar Ponto Parceiro';
}elseif($isLoggiPonto){
    $postingTitle='Leve a encomenda ao Loggi Ponto deste envio';
    $postingDescription='A modalidade Loggi Ponto deve ser postada em uma unidade de recebimento Loggi. Confira a unidade indicada para este envio antes de despachar.';
}elseif($isLoggiPickup){
    $postingTitle='Aguarde a coleta da Loggi na origem da loja';
    $postingDescription='Nesta modalidade, a transportadora retira o pacote no endereço de origem. Deixe o volume embalado e etiquetado para a coleta.';
    $postingActionUrl='';$postingActionLabel='';
}

$isPaid=in_array((string)$order['status'],['paid','processing','shipped','delivered'],true);
$isPreparing=in_array((string)$order['status'],['processing','shipped','delivered'],true);
$hasLabel=$labelReady||in_array($shipmentStatus,['purchased','posted','in_transit','delivered'],true);
$isTransit=in_array($shipmentStatus,['in_transit','delivered'],true);
$isDelivered=$shipmentStatus==='delivered'||(string)$order['status']==='delivered';
$steps=[
    ['label'=>'Pagamento aprovado','done'=>$isPaid],
    ['label'=>'Em preparação','done'=>$isPreparing],
    ['label'=>'Etiqueta gerada','done'=>$hasLabel],
    ['label'=>'Postado','done'=>$shipmentPosted],
    ['label'=>'Em trânsito','done'=>$isTransit],
    ['label'=>'Entregue','done'=>$isDelivered],
];
$lastDone=-1;foreach($steps as $index=>$step){if($step['done'])$lastDone=$index;}
$recentEvents=array_slice($trackingEvents,0,3);
?>
<div class="seller-order-detail">
    <header class="seller-order-detail__heading">
        <div>
            <nav class="seller-order-breadcrumb"><a href="<?=e(url('/vendedor/pedidos'))?>">Pedidos</a><span>›</span><strong>Detalhes do pedido</strong></nav>
            <h2>Detalhes do pedido</h2>
            <p>Acompanhe a venda, emita documentos e cuide do envio em um só lugar.</p>
        </div>
        <a class="button button--secondary" href="<?=e(url('/vendedor/pedidos'))?>">← Voltar para pedidos</a>
    </header>

    <section class="panel seller-order-hero">
        <div class="seller-order-hero__identity">
            <span class="seller-order-hero__icon" aria-hidden="true">□</span>
            <div><small>PEDIDO</small><h1><?=e($order['code'])?></h1><p>Loja: <strong><?=e($currentStore['name'])?></strong> · <?=date('d/m/Y \à\s H:i',strtotime($order['order_created_at']))?></p></div>
        </div>
        <div class="seller-order-hero__status"><?php $status=$order['status'];require dirname(__DIR__,2).'/components/dashboard/status-badge.php';?><span><?=e($shipmentPosted?'Pacote postado':'Aguardando próxima etapa')?></span></div>
        <div class="seller-order-hero__actions">
            <?php if($shipment):?><a class="button button--primary" href="<?=e(url('/vendedor/pedidos/'.$order['code'].'/rastreamento'))?>">Ver rastreamento</a><?php endif;?>
            <?php if($labelReady):?><a class="button button--secondary" href="<?=e($shipment['label_url'])?>" target="_blank" rel="noopener noreferrer">Reimprimir etiqueta</a><?php endif;?>
            <?php if($trackingConfigured&&!empty($shipment['external_id'])):?><form method="post" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/sincronizar-rastreio'))?>"><?=csrf_field()?><button class="button button--secondary">Atualizar rastreamento</button></form><?php endif;?>
            <a class="button button--secondary" href="#fiscal">Dados da NF-e</a>
        </div>
    </section>

    <section class="panel seller-order-progress">
        <div class="seller-order-section-title"><span>STATUS DO PEDIDO</span><h3>Da aprovação à entrega</h3></div>
        <div class="seller-order-progress__track">
            <?php foreach($steps as $index=>$step):?>
                <div class="seller-order-progress__step <?= $step['done']?'is-done':'' ?> <?= $index===$lastDone&&!$isDelivered?'is-current':'' ?>">
                    <i><?= $step['done']?'✓':($index+1) ?></i><strong><?=e($step['label'])?></strong>
                </div>
            <?php endforeach;?>
        </div>
    </section>

    <div class="seller-order-summary-grid">
        <article class="panel seller-order-summary-card"><span>CLIENTE</span><h3><?=e($order['customer_name'])?></h3><p><?=e((string)($order['customer_document']?:'Documento não informado'))?><br><?=e($order['customer_email'])?><br><?=e($order['customer_phone']?:'Telefone não informado')?></p><button type="button" class="seller-order-link-button" data-copy="<?=e($customerCopy)?>">Copiar dados</button></article>
        <article class="panel seller-order-summary-card"><span>ENDEREÇO DE ENTREGA</span><?php if($address):?><h3><?=e($address['city'].' / '.$address['state'])?></h3><p><?=e($address['street'].', '.$address['number'])?><br><?=e($address['neighborhood'])?><br>CEP <?=e($address['postal_code'])?></p><button type="button" class="seller-order-link-button" data-copy="<?=e($addressCopy)?>">Copiar endereço</button><?php else:?><h3>Não informado</h3><p>O pedido não possui endereço salvo.</p><?php endif;?></article>
        <article class="panel seller-order-summary-card"><span>RESUMO FINANCEIRO</span><dl><div><dt>Produtos</dt><dd><?=$money((float)$order['products_total'])?></dd></div><div><dt>Frete</dt><dd><?=$money((float)$order['shipping_total'])?></dd></div><div><dt>Desconto</dt><dd>- <?=$money((float)$order['discount_total'])?></dd></div><div class="is-total"><dt>Total</dt><dd><?=$money($fiscalTotal)?></dd></div></dl></article>
        <article class="panel seller-order-summary-card"><span>NF-e</span><div class="seller-order-summary-card__badge <?= $fiscalAuthorized?'is-success':'' ?>"><?=e($fiscalStatusLabel)?></div><?php if($fiscalDocument):?><h3>Nº <?=e((string)$fiscalDocument['number'])?> · série <?=e((string)$fiscalDocument['series'])?></h3><p class="seller-order-break">Chave <?=e((string)$fiscalDocument['access_key'])?></p><button type="button" class="seller-order-link-button" data-copy="<?=e((string)$fiscalDocument['access_key'])?>">Copiar chave</button><?php else:?><h3>Documento pendente</h3><p>Use a área fiscal abaixo para concluir esta etapa.</p><a class="seller-order-link-button" href="#fiscal">Ir para dados fiscais</a><?php endif;?></article>
    </div>

    <section class="panel seller-order-fiscal seller-order-fiscal--refined" id="fiscal">
        <div class="seller-order-fiscal__head">
            <div><small>EMISSÃO FISCAL</small><h3>Dados para emissão da NF-e</h3><p>Informações deste subpedido prontas para copiar para o Tiny, FFAdmin ou outro emissor da loja.</p></div>
            <span class="seller-order-fiscal__status seller-order-fiscal__status--<?=e($fiscalStatus)?>"><?=e($fiscalStatusLabel)?></span>
        </div>
        <div class="seller-order-copybar seller-order-copybar--light">
            <div><strong>Copie sem redigitar</strong><span>Todos os dados abaixo pertencem somente à <?=e($currentStore['name'])?>.</span></div>
            <div class="seller-order-copybar__actions"><button type="button" class="order-copy-button" data-copy="<?=e($customerCopy)?>">Copiar cliente</button><?php if($addressCopy!==''):?><button type="button" class="order-copy-button" data-copy="<?=e($addressCopy)?>">Copiar endereço</button><?php endif;?><button type="button" class="order-copy-button order-copy-button--primary" data-copy="<?=e($orderCopy)?>">Copiar todos os dados</button></div>
        </div>
        <div class="seller-order-fiscal__grid">
            <article class="seller-order-data-card"><header><span>01</span><div><small>DESTINATÁRIO</small><h4>Dados do cliente</h4></div></header><dl><div><dt>Nome</dt><dd><?=e($order['customer_name'])?></dd></div><div><dt>CPF/CNPJ</dt><dd><?=e((string)($order['customer_document']?:'Não informado'))?></dd></div><div><dt>E-mail</dt><dd><?=e($order['customer_email'])?></dd></div><div><dt>Telefone</dt><dd><?=e((string)($order['customer_phone']?:'Não informado'))?></dd></div></dl></article>
            <article class="seller-order-data-card"><header><span>02</span><div><small>DESTINO</small><h4>Endereço de entrega</h4></div></header><?php if($address):?><dl><div><dt>CEP</dt><dd><?=e($address['postal_code'])?></dd></div><div><dt>Endereço</dt><dd><?=e($address['street'].', '.$address['number'])?></dd></div><div><dt>Bairro</dt><dd><?=e($address['neighborhood'])?></dd></div><div><dt>Cidade / UF</dt><dd><?=e($address['city'].' / '.$address['state'])?></dd></div><?php if(!empty($address['complement'])):?><div><dt>Complemento</dt><dd><?=e($address['complement'])?></dd></div><?php endif;?></dl><?php else:?><p class="seller-order-data-card__empty">Endereço não informado.</p><?php endif;?></article>
            <article class="seller-order-data-card seller-order-data-card--totals"><header><span>03</span><div><small>VALORES</small><h4>Totais da NF-e</h4></div></header><dl><div><dt>Produtos</dt><dd><?=$money((float)$order['products_total'])?></dd></div><div><dt>Frete</dt><dd><?=$money((float)$order['shipping_total'])?></dd></div><div><dt>Desconto</dt><dd><?=$money((float)$order['discount_total'])?></dd></div><div class="is-total"><dt>Total</dt><dd><?=$money($fiscalTotal)?></dd></div></dl></article>
        </div>
        <div class="seller-order-invoice">
            <?php if($fiscalDocument&&in_array((string)$fiscalDocument['status'],['authorized','cancelled'],true)):?>
                <div class="seller-order-invoice__done"><div class="seller-order-invoice__icon">✓</div><div><small>NF-e VINCULADA</small><h4>Nº <?=e((string)$fiscalDocument['number'])?> · série <?=e((string)$fiscalDocument['series'])?></h4><p>Chave de acesso <code><?=e((string)$fiscalDocument['access_key'])?></code></p><?php if(!empty($fiscalDocument['protocol'])):?><p>Protocolo <?=e((string)$fiscalDocument['protocol'])?></p><?php endif;?></div><button type="button" class="order-copy-button" data-copy="<?=e((string)$fiscalDocument['access_key'])?>">Copiar chave</button></div>
            <?php elseif(!$fiscalEnabled):?><div class="seller-order-invoice__notice"><div><small>ANTES DE REGISTRAR</small><h4>Ative os dados fiscais desta loja</h4><p>A loja precisa estar com o perfil fiscal configurado para vincular uma NF-e ao pedido.</p></div><a class="button button--secondary" href="<?=e(url('/vendedor/fiscal'))?>">Configurar Fiscal</a></div>
            <?php elseif($fiscalMode!=='manual'):?><div class="seller-order-invoice__notice"><div><small>MODO <?=e(mb_strtoupper($fiscalMode))?></small><h4>Este pedido usa integração fiscal</h4><p>A NF-e será sincronizada pelo fluxo configurado para a loja.</p></div><a class="button button--secondary" href="<?=e(url('/vendedor/fiscal'))?>">Ver configuração</a></div>
            <?php elseif(!$fiscalEligible):?><div class="seller-order-invoice__notice"><div><small>EMISSÃO BLOQUEADA</small><h4>Aguarde a confirmação do pagamento</h4><p>A NF-e só pode ser vinculada quando o pedido desta loja estiver pago.</p></div></div>
            <?php else:?>
                <div class="seller-order-invoice__form-head"><div><small>DEPOIS DE EMITIR NO SEU SISTEMA</small><h4>Registrar NF-e emitida</h4><p>Informe os dados retornados pelo seu emissor. XML e DANFE são opcionais, mas recomendados.</p></div></div>
                <form class="seller-order-invoice__form" method="post" enctype="multipart/form-data" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/nota-fiscal'))?>"><?=csrf_field()?><div class="seller-order-invoice__fields"><label>Número da NF-e<input name="number" inputmode="numeric" required placeholder="Ex.: 1542"></label><label>Série<input name="series" inputmode="numeric" min="1" max="999" required value="<?=e((string)($fiscalProfile['nfe_series']??1))?>"></label><label class="is-wide">Chave de acesso<input name="access_key" inputmode="numeric" minlength="44" maxlength="44" pattern="[0-9]{44}" required placeholder="44 dígitos"></label><label>Protocolo <span>opcional</span><input name="protocol" maxlength="100"></label><label>Referência externa <span>opcional</span><input name="external_reference" maxlength="150"></label><label>XML da NF-e <span>opcional</span><input type="file" name="xml" accept=".xml,application/xml,text/xml"></label><label>DANFE PDF <span>opcional</span><input type="file" name="danfe" accept=".pdf,application/pdf"></label></div><div class="seller-order-invoice__footer"><p>O registro apenas vincula à Tuffer uma NF-e já emitida pela loja.</p><button class="button button--primary">Registrar NF-e</button></div></form>
            <?php endif;?>
        </div>
    </section>

    <section class="panel seller-order-items">
        <div class="seller-order-section-head"><div><span>ITENS DA LOJA</span><h3>Produtos deste pedido</h3></div><strong><?=count($items)?> <?=count($items)===1?'item':'itens'?></strong></div>
        <div class="seller-order-items__table"><table><thead><tr><th>Produto</th><th>SKU</th><th>Qtd.</th><th>Unitário</th><th>Subtotal</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><div class="seller-order-product"><?php if(!empty($item['product_image'])):?><img src="<?=e($item['product_image'])?>" alt=""><?php else:?><span>□</span><?php endif;?><strong><?=e($item['product_name'])?></strong></div></td><td><?=e((string)($item['sku']?:'—'))?></td><td><?=(int)$item['quantity']?></td><td><?=$money((float)$item['unit_price'])?></td><td><strong><?=$money((float)$item['total'])?></strong></td></tr><?php endforeach;?></tbody></table></div>
    </section>

    <section class="panel seller-order-logistics" id="logistica">
        <div class="seller-order-section-head"><div><span>LOGÍSTICA</span><h3>Despacho, etiqueta e rastreamento</h3></div><?php if($shipment):?><a class="button button--secondary" href="<?=e(url('/vendedor/pedidos/'.$order['code'].'/rastreamento'))?>">Ver rastreamento completo →</a><?php endif;?></div>
        <?php if($shipment):?>
            <div class="seller-order-logistics__grid">
                <div class="seller-order-logistics__facts">
                    <dl><div><dt>Transportadora</dt><dd><?=e($carrierName?:'Transportadora')?></dd></div><div><dt>Modalidade</dt><dd><?=e($serviceName?:'Entrega')?></dd></div><div><dt>Código de rastreio</dt><dd><?=e((string)($shipment['tracking_code']?:'Ainda não disponível'))?><?php if(!empty($shipment['tracking_code'])):?><button type="button" data-copy="<?=e($shipment['tracking_code'])?>">Copiar</button><?php endif;?></dd></div><div><dt>Frete</dt><dd><?=$money((float)$shipment['shipping_cost'])?><?php if(($shipment['label_actual_cost']??null)!==null):?><small>Etiqueta <?=$money((float)$shipment['label_actual_cost'])?></small><?php endif;?></dd></div></dl>
                    <div class="seller-order-logistics__actions"><?php if($labelReady):?><a class="button button--secondary" href="<?=e($shipment['label_url'])?>" target="_blank" rel="noopener noreferrer">Reimprimir etiqueta</a><?php endif;?><?php if($trackingConfigured&&!empty($shipment['external_id'])):?><form method="post" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/sincronizar-rastreio'))?>"><?=csrf_field()?><button class="button button--secondary">Atualizar rastreamento</button></form><?php endif;?></div>
                </div>
                <div class="seller-order-logistics__events"><h4>Últimas movimentações</h4><?php if($recentEvents):?><ol><?php foreach($recentEvents as $event):?><li><i></i><div><strong><?=e($event['description'])?></strong><span><?=date('d/m/Y \à\s H:i',strtotime($event['occurred_at']))?><?php if(!empty($event['city'])):?> · <?=e($event['city'].' / '.$event['state'])?><?php endif;?></span></div></li><?php endforeach;?></ol><?php else:?><p>A transportadora ainda não enviou movimentações detalhadas.</p><?php endif;?><a href="<?=e(url('/vendedor/pedidos/'.$order['code'].'/rastreamento'))?>">Ver todas as movimentações →</a></div>
            </div>
            <?php if($labelReady&&!$shipmentPosted):?><div class="seller-order-posting"><div class="seller-order-posting__pin">⌖</div><div class="seller-order-posting__content"><small>ONDE DESPACHAR</small><h4><?=e($postingTitle)?></h4><p><?=e($postingDescription)?></p></div><div class="seller-order-posting__actions"><?php if($postingActionUrl!==''&&$postingActionLabel!==''):?><a class="button button--secondary" href="<?=e($postingActionUrl)?>" target="_blank" rel="noopener noreferrer"><?=e($postingActionLabel)?></a><?php endif;?><a class="button button--primary" href="<?=e($shipment['label_url'])?>" target="_blank" rel="noopener noreferrer">Imprimir etiqueta</a></div></div>
            <?php elseif(!$labelReady&&in_array($order['status'],['paid','processing'],true)):?>
                <?php if($labelPurchaseConfigured):?><div class="seller-order-label-purchase"><div><strong>Etiqueta ainda não gerada</strong><p>Vincule a NF-e e compre a etiqueta para liberar o despacho.</p></div><form class="resource-form" method="post" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/comprar-etiqueta'))?>"><?=csrf_field()?><div class="form-grid"><label>Chave de acesso da NF-e<input name="invoice_key" inputmode="numeric" minlength="44" maxlength="44" pattern="[0-9]{44}" required value="<?=e($shipment['invoice_key']??($fiscalDocument['access_key']??''))?>" placeholder="44 dígitos"></label></div><label class="checkbox"><input type="checkbox" name="confirm_purchase" value="1" required> Confirmo a compra da etiqueta com o saldo de fretes da Tuffer.</label><div class="form-actions"><button class="button button--primary">Comprar e gerar etiqueta</button></div></form></div><?php else:?><p>A compra de etiquetas está temporariamente indisponível.</p><?php endif;?>
            <?php endif;?>
        <?php else:?><div class="seller-order-empty-logistics">Não há remessa vinculada a este pedido.</div><?php endif;?>
    </section>

    <div class="seller-order-bottom-grid">
        <section class="panel seller-order-finance"><div class="seller-order-section-title"><span>FINANCEIRO DA LOJA</span><h3>Quanto esta venda gera para você</h3></div><dl><div><dt>Produtos</dt><dd><?=$money((float)$order['products_total'])?></dd></div><div><dt>Frete</dt><dd><?=$money((float)$order['shipping_total'])?></dd></div><div><dt>Desconto</dt><dd><?=$money((float)$order['discount_total'])?></dd></div><div><dt>Comissão</dt><dd>- <?=$money((float)$order['commission_total'])?></dd></div><div class="is-total"><dt>Líquido da loja</dt><dd><?=$money((float)$order['seller_net_total'])?></dd></div></dl></section>
        <section class="panel seller-order-actions-card"><div class="seller-order-section-title"><span>AÇÕES DO PEDIDO</span><h3>Atalhos operacionais</h3></div><?php if($order['status']==='paid'):?><form method="post" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/preparar'))?>"><?=csrf_field()?><button class="seller-order-action-row"><strong>Iniciar preparação</strong><span>Confirme que a separação dos itens começou.</span></button></form><?php endif;?><?php if($shipment):?><a class="seller-order-action-row" href="<?=e(url('/vendedor/pedidos/'.$order['code'].'/rastreamento'))?>"><strong>Rastreamento completo</strong><span>Veja o histórico detalhado da transportadora.</span></a><?php endif;?><a class="seller-order-action-row" href="#fiscal"><strong>Dados da NF-e</strong><span>Confira ou registre o documento fiscal.</span></a></section>
    </div>
</div>
<div class="order-copy-toast" data-copy-toast role="status" aria-live="polite">Copiado</div>
<script>
document.addEventListener('click',function(event){const button=event.target.closest('[data-copy]');if(!button)return;const text=button.getAttribute('data-copy')||'';const done=function(){const toast=document.querySelector('[data-copy-toast]');if(!toast)return;toast.textContent='Copiado para a área de transferência';toast.classList.add('is-visible');window.setTimeout(function(){toast.classList.remove('is-visible');},1800);};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(text).then(done).catch(function(){});return;}const area=document.createElement('textarea');area.value=text;area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();try{document.execCommand('copy');done();}catch(e){}area.remove();});
</script>
<?php endif;?>