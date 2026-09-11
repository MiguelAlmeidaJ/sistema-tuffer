<?php if (!$order): ?>
<section class="panel"><?php $emptyTitle='Pedido não encontrado'; $emptyText='Verifique o código e tente novamente.'; require dirname(__DIR__,2).'/components/dashboard/empty-state.php'; ?></section>
<?php else: ?>
<?php
$isCardPayment = ($payment['method'] ?? '') === 'card';
$paymentStatus = (string) ($payment['status'] ?? '');
$displayOrderStatus = $order['status'];
if ($isCardPayment && ($order['status'] ?? '') === 'pending_payment' && in_array($paymentStatus, ['pending','processing','waiting_payment'], true)) {
    $displayOrderStatus = 'processing';
}
?>
<div class="dashboard-heading"><div><span class="eyebrow">PEDIDO</span><h2>#<?= e($order['code']) ?></h2><p>Realizado em <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></p></div><?php $status=$displayOrderStatus; require dirname(__DIR__,2).'/components/dashboard/status-badge.php'; ?></div>

<?php if (($order['status'] ?? '') === 'pending_payment'): ?>
<?php $pixPayable = !empty($payment['pix_qr_code']) && in_array($paymentStatus, ['pending','waiting_payment','processing'], true) && (empty($payment['pix_expires_at']) || strtotime((string) $payment['pix_expires_at']) > time()); ?>
<section class="panel"><div class="panel-head"><div><small>PAGAMENTO</small><h3><?php if($isCardPayment): ?><?= in_array($paymentStatus,['pending','processing','waiting_payment'],true)?'Cartão enviado · confirmação em andamento':($paymentStatus==='failed'?'Cartão não autorizado':($paymentStatus==='paid'?'Pagamento confirmado':(!empty($payment['checkout_url'])?'Continue o pagamento':'Pagamento do cartão'))); ?><?php else: ?><?= $pixPayable ? 'Pix aguardando pagamento' : (in_array($paymentStatus, ['expired','cancelled'], true) ? 'Pix expirado ou cancelado' : (!empty($payment['checkout_url']) ? 'Aguardando confirmação' : (($payment['async_status'] ?? '') === 'failed' ? 'Pagamento indisponível' : 'Preparando pagamento'))) ?><?php endif; ?></h3></div></div>
<?php if ($isCardPayment && in_array($paymentStatus,['pending','processing','waiting_payment'],true) && empty($payment['checkout_url'])): ?>
<p>Os dados do cartão já foram enviados com segurança. Estamos aguardando a confirmação da operadora e da Pagar.me. <strong>Você não precisa pagar novamente</strong> enquanto esta mensagem estiver aparecendo.</p>
<?php elseif ($isCardPayment && $paymentStatus === 'failed' && empty($payment['checkout_url'])): ?>
<p>O cartão não foi confirmado. Você pode preparar uma alternativa de pagamento para este mesmo pedido, sem duplicar a compra.</p><form method="post" action="<?=e(url('/minha-conta/pedidos/'.$order['code'].'/pagamento/atualizar'))?>"><?=csrf_field()?><button class="button button--primary" type="submit">Preparar outra forma de pagamento</button></form>
<?php elseif ($pixPayable): ?>
<p>Copie o código Pix abaixo. O pedido será liberado somente após a confirmação segura da Pagar.me.</p>
<?php if (!empty($payment['pix_qr_code_url'])): ?><p><img src="<?= e($payment['pix_qr_code_url']) ?>" alt="QR Code Pix" width="220" height="220"></p><?php endif; ?>
<label>Código Pix copia e cola<textarea id="pix-copy-code" readonly rows="5" style="width:100%"><?= e($payment['pix_qr_code']) ?></textarea></label><p><button type="button" class="button button--secondary" data-copy-target="#pix-copy-code">Copiar código Pix</button></p>
<?php if (!empty($payment['pix_expires_at'])): ?><p><small>Expira em <?= date('d/m/Y H:i', strtotime((string) $payment['pix_expires_at'])) ?></small></p><?php endif; ?>
<?php elseif (!empty($payment['checkout_url'])): ?><p><?= $isCardPayment ? 'Não foi possível concluir o cartão dentro do checkout. Preparamos uma alternativa segura para este mesmo pedido.' : 'O retorno do navegador não confirma o pagamento. O pedido será atualizado somente após a confirmação segura da Pagar.me.' ?></p><?php if (empty($payment['expires_at']) || strtotime((string) $payment['expires_at']) > time()): ?><p><a class="button button--primary" href="<?= e($payment['checkout_url']) ?>"><?= $isCardPayment ? 'Continuar pagamento com segurança' : 'Continuar pagamento' ?></a></p><?php endif; ?><?php elseif (($payment['async_status'] ?? '') === 'failed'): ?><p>Não foi possível preparar o pagamento automaticamente. Entre em contato com o suporte e informe o código deste pedido.</p><?php else: ?><p>A cobrança ainda está sendo preparada. Você pode tentar atualizar agora sem criar um pedido duplicado.</p><form method="post" action="<?=e(url('/minha-conta/pedidos/'.$order['code'].'/pagamento/atualizar'))?>"><?=csrf_field()?><button class="button button--primary" type="submit">Atualizar pagamento</button></form><?php endif; ?></section>
<?php endif; ?>

<section class="panel order-summary"><h3>Resumo total</h3><dl><div><dt>Produtos</dt><dd>R$ <?= number_format((float)$order['products_total'],2,',','.') ?></dd></div><div><dt>Entrega</dt><dd>R$ <?= number_format((float)$order['shipping_total'],2,',','.') ?></dd></div><div><dt>Descontos</dt><dd>− R$ <?= number_format((float)$order['discount_total'],2,',','.') ?></dd></div></dl><strong>R$ <?= number_format((float)$order['grand_total'],2,',','.') ?></strong></section>

<?php foreach ($sellerOrders as $sellerOrder): ?>
<section class="panel"><div class="panel-head"><div><small><?= e($sellerOrder['code']) ?></small><h3><?= e($sellerOrder['store_name']) ?></h3></div><?php $status=$sellerOrder['status']; require dirname(__DIR__,2).'/components/dashboard/status-badge.php'; ?></div><div class="table-wrap"><table><thead><tr><th>Produto</th><th>Quantidade</th><th>Unitário</th><th>Total</th></tr></thead><tbody><?php foreach ($sellerOrder['items'] as $item): ?><tr><td><strong><?= e($item['product_name']) ?></strong><small><?= e($item['sku']) ?></small></td><td><?= (int)$item['quantity'] ?></td><td>R$ <?= number_format((float)$item['unit_price'],2,',','.') ?></td><td>R$ <?= number_format((float)$item['total'],2,',','.') ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php if (!empty($sellerOrder['fiscal_documents'])): ?>
<div style="margin-top:24px"><h4>Nota fiscal</h4><?php foreach($sellerOrder['fiscal_documents'] as $fiscal):?><div class="settings-note" style="margin-top:10px"><span>NF</span><p><strong>NF-e <?=e((string)($fiscal['number']??'—'))?> · série <?=e((string)($fiscal['series']??'—'))?></strong><?php if(($fiscal['status']??'')==='cancelled'):?> · Cancelada<?php endif;?><br><small>Chave: <?=e((string)($fiscal['access_key']??'—'))?></small><?php if(!empty($fiscal['cancellation_reason'])):?><br><small>Motivo do cancelamento: <?=e((string)$fiscal['cancellation_reason'])?></small><?php endif;?><br><?php if(!empty($fiscal['xml_storage_path'])):?><a class="button button--secondary" href="<?=e(url('/minha-conta/pedidos/'.$order['code'].'/fiscal/'.(int)$fiscal['id'].'/xml'))?>">Baixar XML</a><?php endif;?> <?php if(!empty($fiscal['danfe_storage_path'])):?><a class="button button--secondary" href="<?=e(url('/minha-conta/pedidos/'.$order['code'].'/fiscal/'.(int)$fiscal['id'].'/danfe'))?>">Baixar DANFE</a><?php endif;?></p></div><?php endforeach;?></div>
<?php endif; ?>
</section>
<?php endforeach; ?>

<?php if ($address): ?><section class="panel"><div class="panel-head"><h3>Endereço de entrega</h3></div><p><strong><?= e($address['recipient_name']) ?></strong><br><?= e($address['street'].', '.$address['number']) ?><?= $address['complement'] ? ' · '.e($address['complement']) : '' ?><br><?= e($address['neighborhood'].' · '.$address['city'].'/'.$address['state']) ?><br>CEP <?= e($address['postal_code']) ?></p></section><?php endif; ?>
<?php endif; ?>
