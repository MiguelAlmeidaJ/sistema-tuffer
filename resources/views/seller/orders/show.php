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

$labelReady=$shipment&&($shipment['label_purchase_status']??'')==='ready'&&!empty($shipment['label_url']);
$shipmentStatus=(string)($shipment['status']??'');
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
    $postingActionLabel='Consultar pontos de postagem';
}elseif($isLoggiPickup){
    $postingTitle='Aguarde a coleta da Loggi na origem da loja';
    $postingDescription='Nesta modalidade, a transportadora retira o pacote no endereço de origem. Deixe o volume embalado e etiquetado para a coleta.';
    $postingActionUrl='';
    $postingActionLabel='';
}

$nextStepEyebrow='PRÓXIMO PASSO';
$nextStepTitle='Prepare o pedido para envio';
$nextStepDescription='Confira a nota fiscal, gere a etiqueta e siga as orientações de despacho da transportadora.';
$nextStepHref='#fiscal';
$nextStepLabel='Ver emissão fiscal';
$nextStepClass='seller-order-next--attention';
if((string)$order['status']==='pending_payment'){
    $nextStepTitle='Aguarde a confirmação do pagamento';
    $nextStepDescription='Assim que o pagamento for confirmado, a preparação e a emissão da etiqueta serão liberadas.';
    $nextStepHref='';
    $nextStepLabel='';
}elseif($shipmentPosted){
    $nextStepEyebrow='ENVIO EM ANDAMENTO';
    $nextStepTitle=$shipmentStatus==='delivered'||(string)$order['status']==='delivered'?'Pedido entregue':'Pacote já foi postado';
    $nextStepDescription='Acompanhe as movimentações da transportadora e o código de rastreio deste pedido.';
    $nextStepHref='#logistica';
    $nextStepLabel='Ver rastreamento';
    $nextStepClass='seller-order-next--success';
}elseif($labelReady){
    $nextStepTitle='Despache o pacote';
    $nextStepDescription=$postingTitle.'. '.$postingDescription;
    $nextStepHref='#logistica';
    $nextStepLabel='Ver instruções de despacho';
    $nextStepClass='seller-order-next--ready';
}elseif($fiscalAuthorized){
    $nextStepTitle='Gere a etiqueta de envio';
    $nextStepDescription='A NF-e já está vinculada. Agora compre e gere a etiqueta para liberar as instruções de despacho.';
    $nextStepHref='#logistica';
    $nextStepLabel='Gerar etiqueta';
}else{
    $nextStepTitle='Emita e vincule a NF-e';
    $nextStepDescription='A nota fiscal é necessária antes da compra da etiqueta de envio.';
    $nextStepHref='#fiscal';
    $nextStepLabel='Ir para emissão fiscal';
}
?>
<div class="dashboard-heading"><div><a class="back-link" href="<?=e(url('/vendedor/pedidos'))?>">← Voltar para pedidos</a><span class="eyebrow"><?=e($currentStore['name'])?></span><h2><?=e($order['code'])?></h2><p>Pedido principal <?=e($order['order_code'])?> · <?=date('d/m/Y H:i',strtotime($order['order_created_at']))?></p></div><?php $status=$order['status'];require dirname(__DIR__,2).'/components/dashboard/status-badge.php';?></div>

<section class="panel seller-order-next <?=$nextStepClass?>">
    <div class="seller-order-next__icon" aria-hidden="true">→</div>
    <div class="seller-order-next__content">
        <small><?=e($nextStepEyebrow)?></small>
        <h3><?=e($nextStepTitle)?></h3>
        <p><?=e($nextStepDescription)?></p>
        <?php if($shipment&&($carrierName!==''||$serviceName!=='')):?><div class="seller-order-next__meta"><span><b>Transportadora</b><?=e($carrierName?:'—')?></span><span><b>Modalidade</b><?=e($serviceName?:'—')?></span><?php if($labelReady&&!$shipmentPosted):?><span><b>Onde despachar</b><?=e($postingTitle)?></span><?php endif;?></div><?php endif;?>
    </div>
    <?php if($nextStepHref!==''&&$nextStepLabel!==''):?><a class="button button--primary seller-order-next__action" href="<?=e($nextStepHref)?>"><?=e($nextStepLabel)?></a><?php endif;?>
</section>

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
        <article class="seller-order-data-card"><header><span>02</span><div><small>DESTINO DO CLIENTE</small><h4>Endereço de entrega</h4></div></header><?php if($address):?><dl><div><dt>CEP</dt><dd><?=e($address['postal_code'])?></dd></div><div><dt>Endereço</dt><dd><?=e($address['street'].', '.$address['number'])?></dd></div><div><dt>Bairro</dt><dd><?=e($address['neighborhood'])?></dd></div><div><dt>Cidade / UF</dt><dd><?=e($address['city'].' / '.$address['state'])?></dd></div><?php if(!empty($address['complement'])):?><div><dt>Complemento</dt><dd><?=e($address['complement'])?></dd></div><?php endif;?></dl><?php else:?><p class="seller-order-data-card__empty">Endereço não informado.</p><?php endif;?></article>
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

<section class="panel seller-order-shipping" id="logistica">
    <div class="panel-head"><div><small>LOGÍSTICA</small><h3>Despacho, etiqueta e rastreamento</h3><p>Veja o que fazer com o pacote depois que a etiqueta estiver pronta.</p></div><?php if($shipment):?><?php $status=$shipment['status'];require dirname(__DIR__,2).'/components/dashboard/status-badge.php';?><?php endif;?></div>
    <?php if($shipment):?>
        <div class="seller-order-shipping__summary">
            <div><small>TRANSPORTADORA</small><strong><?=e($carrierName?:'Transportadora')?></strong><span><?=e($serviceName?:'Entrega')?></span></div>
            <div><small>FRETE</small><strong><?=$money((float)$shipment['shipping_cost'])?></strong><span>Cobrado do cliente<?php if(($shipment['label_actual_cost']??null)!==null):?> · etiqueta <?=$money((float)$shipment['label_actual_cost'])?><?php endif;?></span></div>
            <div><small>RASTREIO</small><strong><?=e((string)($shipment['tracking_code']?:'Ainda não disponível'))?></strong><span><?=e($shipmentPosted?'Envio já postado':'Disponível após a geração/postagem')?></span></div>
        </div>

        <?php if($labelReady&&!$shipmentPosted):?>
            <div class="seller-order-posting">
                <div class="seller-order-posting__pin" aria-hidden="true">⌖</div>
                <div class="seller-order-posting__content">
                    <small>ONDE DESPACHAR</small>
                    <h4><?=e($postingTitle)?></h4>
                    <p><?=e($postingDescription)?></p>
                    <ol><li><span>1</span>Embale o pedido com segurança.</li><li><span>2</span>Imprima e cole a etiqueta sem cobrir o código de barras.</li><li><span>3</span><?=e($isLoggiPickup?'Deixe o pacote pronto para a coleta.':'Leve o pacote ao ponto indicado e guarde o comprovante de postagem.')?></li></ol>
                </div>
                <div class="seller-order-posting__actions">
                    <?php if($postingActionUrl!==''&&$postingActionLabel!==''):?><a class="button button--secondary" href="<?=e($postingActionUrl)?>" target="_blank" rel="noopener noreferrer"><?=e($postingActionLabel)?></a><?php endif;?>
                    <a class="button button--primary" href="<?=e($shipment['label_url'])?>" target="_blank" rel="noopener noreferrer">Imprimir etiqueta</a>
                </div>
            </div>
        <?php elseif($labelReady):?>
            <div class="seller-order-shipping__actions"><a class="button button--secondary" href="<?=e($shipment['label_url'])?>" target="_blank" rel="noopener noreferrer">Reimprimir etiqueta</a></div>
        <?php elseif(in_array($order['status'],['paid','processing'],true)):?>
            <?php if($labelPurchaseConfigured):?>
                <div class="seller-order-shipping__callout"><strong>Etiqueta ainda não gerada</strong><span>Vincule a NF-e e gere a etiqueta para ver as instruções de despacho.</span></div>
                <form class="resource-form" method="post" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/comprar-etiqueta'))?>">
                    <?=csrf_field()?>
                    <div class="form-grid"><label>Chave de acesso da NF-e<input name="invoice_key" inputmode="numeric" minlength="44" maxlength="44" pattern="[0-9]{44}" required value="<?=e($shipment['invoice_key']??($fiscalDocument['access_key']??''))?>" placeholder="44 dígitos"><small>Obrigatória para o envio comercial.</small></label></div>
                    <label class="checkbox"><input type="checkbox" name="confirm_purchase" value="1" required> Confirmo a compra da etiqueta com o saldo de fretes da Tuffer.</label>
                    <?php if(!empty($shipment['label_error'])):?><p class="field-error"><?=e($shipment['label_error'])?></p><?php endif;?>
                    <div class="form-actions"><button class="button button--primary"><?=in_array(($shipment['label_purchase_status']??''),['cart','purchased','generated'],true)?'Concluir geração da etiqueta':'Comprar e gerar etiqueta'?></button></div>
                </form>
            <?php else:?><p>A compra de etiquetas está temporariamente indisponível. Fale com o suporte da Tuffer.</p><?php endif;?>
        <?php else:?><p>A compra da etiqueta será liberada após a confirmação do pagamento.</p><?php endif;?>
        <?php if($trackingConfigured&&!empty($shipment['external_id'])):?><form class="seller-order-shipping__sync" method="post" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/sincronizar-rastreio'))?>"><?=csrf_field()?><button class="button button--secondary">Atualizar rastreamento</button></form><?php endif;?>
    <?php else:?><p>Não há remessa vinculada a este pedido.</p><?php endif;?>
    <?php if($trackingEvents):?><div class="compact-orders seller-order-shipping__events"><?php foreach($trackingEvents as $event):?><div><strong><?=e($event['description'])?></strong><span><?=e($event['city']?($event['city'].'/'.$event['state']):$event['event_code'])?></span><b><?=date('d/m/Y H:i',strtotime($event['occurred_at']))?></b></div><?php endforeach;?></div><?php endif;?>
</section>
</div><aside>
<?php if($order['status']==='paid'):?><section class="panel"><h3>Próxima ação</h3><p>Confirme que a separação dos itens foi iniciada.</p><form method="post" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/preparar'))?>"><?=csrf_field()?><button class="button button--primary button--block">Iniciar preparação</button></form></section><?php endif;?>
<section class="panel"><h3>Cliente</h3><p><strong><?=e($order['customer_name'])?></strong><br><?php if(!empty($order['customer_document'])):?><?=e($order['customer_document'])?><br><?php endif;?><?=e($order['customer_email'])?><br><?=e($order['customer_phone']?:'Telefone não informado')?></p></section>
<?php if($address):?><section class="panel"><h3>Destino do cliente</h3><p><strong><?=e($address['recipient_name'])?></strong><br><?=e($address['street'].', '.$address['number'])?><br><?=e($address['neighborhood'].' · '.$address['city'].'/'.$address['state'])?><br>CEP <?=e($address['postal_code'])?></p><small class="seller-order-destination-note">Este é o endereço do cliente, não o local de postagem.</small></section><?php endif;?>
<section class="panel"><h3>Financeiro da loja</h3><p>Produtos: R$ <?=number_format((float)$order['products_total'],2,',','.')?><br>Frete: R$ <?=number_format((float)$order['shipping_total'],2,',','.')?><br>Desconto: R$ <?=number_format((float)$order['discount_total'],2,',','.')?><br>Comissão: R$ <?=number_format((float)$order['commission_total'],2,',','.')?></p><strong>Líquido: R$ <?=number_format((float)$order['seller_net_total'],2,',','.')?></strong></section>
</aside></div>
<div class="order-copy-toast" data-copy-toast role="status" aria-live="polite">Copiado</div>
<style>
.seller-order-next{display:flex;align-items:center;gap:16px;margin-bottom:16px;padding:18px 20px;border:1px solid #e3e4e6;background:#fff}.seller-order-next__icon{display:grid;place-items:center;flex:0 0 44px;height:44px;border-radius:13px;background:#111;color:#fff;font-size:1.05rem;font-weight:900}.seller-order-next__content{min-width:0;flex:1}.seller-order-next__content>small,.seller-order-posting small,.seller-order-shipping__summary small{display:block;color:var(--brand-red);font-size:.54rem;font-weight:900;letter-spacing:.11em}.seller-order-next h3{margin:3px 0 4px;font-family:inherit;font-size:1rem;letter-spacing:-.02em}.seller-order-next p{max-width:850px;color:var(--muted);font-size:.66rem;line-height:1.5}.seller-order-next__meta{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:10px}.seller-order-next__meta span{color:#555b62;font-size:.6rem}.seller-order-next__meta b{margin-right:5px;color:#8b9096;font-size:.54rem;text-transform:uppercase;letter-spacing:.06em}.seller-order-next__action{flex:0 0 auto}.seller-order-next--ready{border-color:#cfe8d8;background:#f6fbf8}.seller-order-next--ready .seller-order-next__icon,.seller-order-next--success .seller-order-next__icon{background:#207244}.seller-order-next--attention{background:#fffdf7}.seller-order-shipping .panel-head p{margin-top:4px;color:var(--muted);font-size:.62rem}.seller-order-shipping__summary{display:grid;grid-template-columns:1fr 1fr 1.2fr;gap:10px;margin:16px 0}.seller-order-shipping__summary>div{padding:14px;border:1px solid #e4e5e7;border-radius:12px;background:#fafaf9}.seller-order-shipping__summary strong,.seller-order-shipping__summary span{display:block}.seller-order-shipping__summary strong{margin-top:4px;color:#202328;font-size:.72rem;overflow-wrap:anywhere}.seller-order-shipping__summary span{margin-top:3px;color:var(--muted);font-size:.57rem;line-height:1.4}.seller-order-posting{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:15px;align-items:start;padding:18px;border:1px solid #cfe8d8;border-radius:14px;background:#f4faf6}.seller-order-posting__pin{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:#e1f2e7;color:#207244;font-size:1.1rem;font-weight:900}.seller-order-posting h4{margin:4px 0 5px;font-family:inherit;font-size:.84rem;letter-spacing:0}.seller-order-posting p{max-width:720px;color:#5d646a;font-size:.63rem;line-height:1.55}.seller-order-posting ol{display:flex;flex-wrap:wrap;gap:8px 14px;margin-top:12px;padding:0;list-style:none}.seller-order-posting li{display:flex;align-items:center;gap:6px;color:#3f464c;font-size:.59rem;font-weight:700}.seller-order-posting li span{display:grid;place-items:center;width:20px;height:20px;border-radius:50%;background:#fff;border:1px solid #d8e7dd;color:#207244;font-size:.52rem;font-weight:900}.seller-order-posting__actions{display:grid;gap:8px;min-width:178px}.seller-order-shipping__callout{display:flex;gap:6px;flex-direction:column;margin:14px 0;padding:12px 14px;border-radius:10px;background:#fff8e8}.seller-order-shipping__callout strong{font-size:.67rem}.seller-order-shipping__callout span{color:#7c6a43;font-size:.59rem}.seller-order-shipping__actions,.seller-order-shipping__sync{margin-top:12px}.seller-order-shipping__events{margin-top:16px}.seller-order-destination-note{display:block;margin-top:9px;padding-top:9px;border-top:1px solid var(--line);color:#9b5b16;font-size:.56rem;line-height:1.4}@media(max-width:900px){.seller-order-next{align-items:flex-start;flex-wrap:wrap}.seller-order-next__action{margin-left:60px}.seller-order-shipping__summary{grid-template-columns:1fr}.seller-order-posting{grid-template-columns:auto 1fr}.seller-order-posting__actions{grid-column:1/-1;display:flex;flex-wrap:wrap;min-width:0}}@media(max-width:620px){.seller-order-next{padding:15px}.seller-order-next__icon{flex-basis:38px;height:38px}.seller-order-next__action{width:100%;margin-left:0}.seller-order-next__meta{display:grid;gap:6px}.seller-order-posting{grid-template-columns:1fr}.seller-order-posting__pin{display:none}.seller-order-posting__actions{display:grid}.seller-order-posting__actions .button{width:100%}.seller-order-posting ol{display:grid}}
</style>
<script>
document.addEventListener('click',function(event){const button=event.target.closest('[data-copy]');if(!button)return;const text=button.getAttribute('data-copy')||'';const done=function(){const toast=document.querySelector('[data-copy-toast]');if(!toast)return;toast.textContent='Copiado para a área de transferência';toast.classList.add('is-visible');window.setTimeout(function(){toast.classList.remove('is-visible');},1800);};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(text).then(done).catch(function(){});return;}const area=document.createElement('textarea');area.value=text;area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();try{document.execCommand('copy');done();}catch(e){}area.remove();});
</script>
<?php endif;?>
