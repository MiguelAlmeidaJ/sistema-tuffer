<?php if(!$order):?>
<section class="panel"><div class="empty-state"><h3>Rastreamento não encontrado</h3><p>Este pedido não pertence à loja selecionada.</p></div></section>
<?php else:?>
<?php
$money=static fn(float $value):string=>'R$ '.number_format($value,2,',','.');
$status=(string)($shipment['status']??'pending');
$statusLabels=['pending'=>'Aguardando etiqueta','purchased'=>'Etiqueta gerada','posted'=>'Postado','in_transit'=>'Em trânsito','delivered'=>'Entregue','cancelled'=>'Cancelado','exception'=>'Ocorrência'];
$statusLabel=$statusLabels[$status]??ucfirst(str_replace('_',' ',$status));
$carrier=trim((string)($shipment['carrier_name']??''))?:'Transportadora';
$service=trim((string)($shipment['service_name']??''))?:'Entrega';
$trackingCode=trim((string)($shipment['tracking_code']??''));
$trackingUrl=trim((string)($shipment['tracking_url']??''));
$latest=$trackingEvents[0]??null;
$oldest=$trackingEvents?end($trackingEvents):null;
if($trackingEvents)reset($trackingEvents);
$eventCodes=mb_strtolower(implode(' ',array_map(static fn(array $event):string=>(string)($event['event_code']??''),$trackingEvents)));
$hasLabel=$shipment&&in_array((string)($shipment['label_purchase_status']??''),['purchased','generated','ready'],true)||in_array($status,['purchased','posted','in_transit','delivered'],true);
$hasPosted=in_array($status,['posted','in_transit','delivered'],true)||str_contains($eventCodes,'posted');
$hasTransit=in_array($status,['in_transit','delivered'],true)||str_contains($eventCodes,'transit')||str_contains($eventCodes,'received');
$hasOut=str_contains($eventCodes,'out_for_delivery')||str_contains($eventCodes,'out-for-delivery');
$hasDelivered=$status==='delivered';
$steps=[['Etiqueta gerada',$hasLabel],['Objeto postado',$hasPosted],['Recebido / em trânsito',$hasTransit],['Em trânsito',$hasTransit],['Saiu para entrega',$hasOut||$hasDelivered],['Entregue',$hasDelivered]];
$lastDone=-1;foreach($steps as $i=>$step){if($step[1])$lastDone=$i;}
$quote=is_array($shipment)?json_decode((string)($shipment['quote_payload']??''),true):null;
$packages=is_array($quote['packages']??null)?$quote['packages']:[];
$package=$packages[0]??[];
$dimensions=is_array($package['dimensions']??null)?$package['dimensions']:$package;
$weight=(float)($package['weight']??0);
$width=(float)($dimensions['width']??0);$height=(float)($dimensions['height']??0);$length=(float)($dimensions['length']??0);
$forecastMin=!empty($shipment['estimated_delivery_min'])?date('d/m/Y',strtotime($shipment['estimated_delivery_min'])):null;
$forecastMax=!empty($shipment['estimated_delivery_max'])?date('d/m/Y',strtotime($shipment['estimated_delivery_max'])):null;
$forecast=$forecastMin&&$forecastMax?($forecastMin===$forecastMax?$forecastMin:$forecastMin.' a '.$forecastMax):($forecastMin?:($forecastMax?:'Aguardando previsão'));
$originCity=$oldest&&$oldest['city']?($oldest['city'].' / '.$oldest['state']):'Origem da loja';
$destination=$address?($address['city'].' / '.$address['state']):'Destino do cliente';
?>
<div class="seller-tracking-page">
    <header class="seller-order-detail__heading">
        <div><nav class="seller-order-breadcrumb"><a href="<?=e(url('/vendedor/pedidos'))?>">Pedidos</a><span>›</span><a href="<?=e(url('/vendedor/pedidos/'.$order['code']))?>">Detalhes do pedido</a><span>›</span><strong>Rastreamento</strong></nav><h2>Rastreamento do pedido</h2><p>Acompanhe todas as movimentações registradas para esta remessa.</p></div>
        <a class="button button--secondary" href="<?=e(url('/vendedor/pedidos/'.$order['code']))?>">← Voltar para o pedido</a>
    </header>

    <section class="panel seller-tracking-hero">
        <div class="seller-tracking-hero__main"><span class="seller-order-hero__icon">□</span><div><small>PEDIDO</small><h1><?=e($order['code'])?></h1><p>Loja: <strong><?=e($currentStore['name'])?></strong> · <?=date('d/m/Y \à\s H:i',strtotime($order['order_created_at']))?></p></div></div>
        <div class="seller-tracking-hero__badge badge badge--<?=e($status)?>"><?=e($statusLabel)?></div>
        <div class="seller-tracking-hero__facts"><div><span>Transportadora</span><strong><?=e($carrier)?></strong></div><div><span>Modalidade</span><strong><?=e($service)?></strong></div><div><span>Código de rastreio</span><strong><?=e($trackingCode?:'Ainda não disponível')?></strong><?php if($trackingCode!==''):?><button type="button" data-copy="<?=e($trackingCode)?>">Copiar</button><?php endif;?></div><div><span>Previsão de entrega</span><strong><?=e($forecast)?></strong></div></div>
    </section>

    <section class="panel seller-order-progress seller-tracking-progress">
        <div class="seller-order-section-title"><span>STATUS DA ENTREGA</span><h3>Etapas da remessa</h3></div>
        <div class="seller-order-progress__track"><?php foreach($steps as $index=>$step):?><div class="seller-order-progress__step <?=$step[1]?'is-done':''?> <?=$index===$lastDone&&!$hasDelivered?'is-current':''?>"><i><?=$step[1]?'✓':($index+1)?></i><strong><?=e($step[0])?></strong></div><?php endforeach;?></div>
    </section>

    <div class="seller-tracking-summary">
        <article class="panel"><span>ÚLTIMA ATUALIZAÇÃO</span><h3><?= $latest?date('d/m/Y \à\s H:i',strtotime($latest['occurred_at'])):'Sem atualização' ?></h3><p><?=e((string)($latest['description']??'Aguardando movimentações da transportadora.'))?></p></article>
        <article class="panel"><span>ORIGEM REGISTRADA</span><h3><?=e($originCity)?></h3><p><?= $shipment&&$shipment['posted_at']?'Postado em '.date('d/m/Y \à\s H:i',strtotime($shipment['posted_at'])):'Aguardando postagem confirmada.' ?></p></article>
        <article class="panel"><span>DESTINO</span><h3><?=e($destination)?></h3><p><?php if($address):?><?=e($address['neighborhood'])?><br>CEP <?=e($address['postal_code'])?><?php else:?>Endereço não informado.<?php endif;?></p></article>
        <article class="panel"><span>PREVISÃO</span><h3><?=e($forecast)?></h3><p>A janela é atualizada conforme os dados disponíveis da remessa.</p></article>
    </div>

    <div class="seller-tracking-workspace">
        <section class="panel seller-tracking-history">
            <div class="seller-order-section-head"><div><span>HISTÓRICO DE MOVIMENTAÇÕES</span><h3>Linha do tempo da entrega</h3></div><strong><?=count($trackingEvents)?> <?=count($trackingEvents)===1?'evento':'eventos'?></strong></div>
            <?php if($trackingEvents):?><ol><?php foreach($trackingEvents as $index=>$event):?><li class="<?=$index===0?'is-latest':''?>"><time><?=date('d/m/Y',strtotime($event['occurred_at']))?><b><?=date('H:i',strtotime($event['occurred_at']))?></b></time><i></i><div><strong><?=e($event['description'])?></strong><p><?=e((string)($event['event_code']?:'Atualização da transportadora'))?></p><?php if(!empty($event['city'])):?><span><?=e($event['city'].' / '.$event['state'])?></span><?php endif;?></div></li><?php endforeach;?></ol>
            <?php else:?><div class="seller-tracking-empty"><strong>Ainda não há movimentações detalhadas</strong><p>Assim que o Melhor Envio ou a transportadora atualizar esta remessa, os eventos aparecerão aqui.</p></div><?php endif;?>
        </section>

        <aside class="seller-tracking-sidebar">
            <section class="panel seller-tracking-package"><div class="seller-order-section-title"><span>DETALHES DO PACOTE</span><h3>Informações da remessa</h3></div><dl><?php if($weight>0):?><div><dt>Peso</dt><dd><?=number_format($weight,3,',','.')?> kg</dd></div><?php endif;?><?php if($width>0&&$height>0&&$length>0):?><div><dt>Dimensões</dt><dd><?=number_format($width,0)?> × <?=number_format($height,0)?> × <?=number_format($length,0)?> cm</dd></div><?php endif;?><div><dt>Valor do frete</dt><dd><?=$money((float)($shipment['shipping_cost']??0))?></dd></div><div><dt>Modalidade</dt><dd><?=e($service)?></dd></div><div><dt>Situação da etiqueta</dt><dd><?=e((string)($shipment['label_purchase_status']??'not_requested'))?></dd></div><div><dt>Referência do envio</dt><dd><?=e((string)($shipment['external_id']??'—'))?></dd></div></dl></section>
            <section class="panel seller-tracking-actions"><div class="seller-order-section-title"><span>AÇÕES</span><h3>Gerenciar rastreamento</h3></div><?php if($trackingConfigured&&!empty($shipment['external_id'])):?><form method="post" action="<?=e(url('/vendedor/pedidos/'.$order['code'].'/sincronizar-rastreio'))?>"><?=csrf_field()?><input type="hidden" name="return_to" value="tracking"><button class="button button--secondary button--block">Atualizar rastreamento</button></form><?php endif;?><?php if($trackingCode!==''):?><button type="button" class="button button--secondary button--block" data-copy="<?=e($trackingCode)?>">Copiar código de rastreio</button><?php endif;?><?php if($trackingUrl!==''):?><a class="button button--secondary button--block" href="<?=e($trackingUrl)?>" target="_blank" rel="noopener noreferrer">Abrir rastreio oficial ↗</a><?php endif;?><a class="button button--secondary button--block" href="<?=e(url('/vendedor/mensagens'))?>">Falar com suporte</a></section>
        </aside>
    </div>

    <section class="panel seller-tracking-note"><div><strong><?=e($statusLabel)?></strong><p><?php if($status==='delivered'):?>A entrega foi concluída. Confira o histórico acima para ver a última confirmação registrada.<?php elseif($status==='exception'):?>A transportadora informou uma ocorrência. Atualize o rastreamento e, se necessário, acione o suporte.<?php elseif(in_array($status,['posted','in_transit'],true)):?>O pacote está a caminho. As informações desta página vêm dos eventos armazenados pela integração de rastreamento.<?php else:?>Acompanhe esta página após a geração da etiqueta e a postagem do pacote.<?php endif;?></p></div></section>
</div>
<div class="order-copy-toast" data-copy-toast role="status" aria-live="polite">Copiado</div>
<script>
document.addEventListener('click',function(event){const button=event.target.closest('[data-copy]');if(!button)return;const text=button.getAttribute('data-copy')||'';const done=function(){const toast=document.querySelector('[data-copy-toast]');if(!toast)return;toast.textContent='Copiado para a área de transferência';toast.classList.add('is-visible');window.setTimeout(function(){toast.classList.remove('is-visible');},1800);};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(text).then(done).catch(function(){});return;}const area=document.createElement('textarea');area.value=text;area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();try{document.execCommand('copy');done();}catch(e){}area.remove();});
</script>
<?php endif;?>