<?php
$purchaseFlowStep = 'confirmation';
require dirname(__DIR__, 2).'/components/public/purchase-flow-progress.php';
?>
<div class="container checkout-result">
<?php if (!$order): ?>
  <section class="checkout-result-card"><h1>Pedido não encontrado</h1><p>Confira o código ou consulte seus pedidos na sua conta.</p><a class="button button--secondary" href="<?=e(url('/minha-conta/pedidos'))?>">Meus pedidos</a></section>
<?php else:
$method = (string) ($payment['method'] ?? '');
$paymentStatus = (string) ($payment['status'] ?? '');
$isPending = ($order['status'] ?? '') === 'pending_payment';
$qr = trim((string) ($payment['pix_qr_code'] ?? ''));
$validQr = $qr !== '' && ($payment['pix_expires_at'] ?? '') === '' || false;
$validQr = $qr !== '' && (empty($payment['pix_expires_at']) || strtotime((string)$payment['pix_expires_at']) > time());
$image = (string) ($payment['asaas_pix_image'] ?? '');
$validImage = $image !== '' && strlen($image) < 300000 && preg_match('/^[A-Za-z0-9+\/=]+$/D', $image);
$remoteUrl = (string) ($payment['checkout_url'] ?? '');
?>
<nav class="commerce-breadcrumb"><a href="<?=e(url('/produtos'))?>">Loja</a><span>›</span><strong>Confirmação</strong></nav>
<div class="checkout-result-layout">
  <main class="checkout-result-main">
    <section class="checkout-result-card checkout-result-hero">
      <div class="commerce-heading-kicker">PEDIDO REALIZADO</div>
      <h1>Seu pedido foi registrado!</h1>
      <p>Pedido <strong><?=e((string)$order['code'])?></strong> · <?=date('d/m/Y H:i',strtotime((string)$order['created_at']))?></p>
      <?php if ($isPending): ?><div class="checkout-result-alert">Aguardando pagamento. Não é necessário fazer outro pedido.</div><?php else: ?><div class="checkout-result-alert checkout-result-alert--success">Status do pedido: <?=e((string)$order['status'])?></div><?php endif;?>
    </section>
    <section class="checkout-result-card">
      <div class="commerce-heading-kicker">PAGAMENTO</div>
      <?php if (!$isPending): ?>
        <h2>Pagamento em acompanhamento</h2><p>Consulte o status atualizado do pedido em sua conta.</p>
      <?php elseif ($method === 'pix' && $validQr): ?>
        <h2>Pague com Pix</h2><p>Escaneie o QR Code pelo aplicativo do seu banco ou copie o código Pix.</p>
        <div class="checkout-result-pix">
          <?php if ($validImage): ?><img alt="QR Code do Pix gerado pelo Asaas" width="238" height="238" src="data:image/png;base64,<?=e($image)?>"><?php endif;?>
          <div class="checkout-result-pix-copy">
            <label for="checkout-pix-code">Pix copia e cola</label>
            <textarea id="checkout-pix-code" readonly rows="5"><?=e($qr)?></textarea>
            <button type="button" class="button button--primary" data-copy-target="#checkout-pix-code">Copiar código Pix</button>
            <?php if (!empty($payment['pix_expires_at'])): ?><small>Validade informada pelo Asaas: <?=date('d/m/Y H:i',strtotime((string)$payment['pix_expires_at']))?></small><?php endif;?>
          </div>
        </div>
      <?php elseif ($method === 'pix'): ?>
        <h2>Preparando seu Pix</h2>
        <p>O QR Code ainda não foi disponibilizado. A cobrança está sendo verificada com o Asaas, sem criar outro pedido.</p>
        <?php if ($remoteUrl !== ''): ?><p><a class="button button--secondary" href="<?=e($remoteUrl)?>" target="_blank" rel="noopener noreferrer">Consultar cobrança no Asaas</a></p><?php endif;?>
        <?php if (($payment['job_status'] ?? '') === 'failed'): ?><p class="checkout-result-alert">Falha ao preparar o Pix. Utilize “Verificar pagamento” e, se persistir, informe o código do pedido ao suporte.</p><?php endif;?>
        <form method="post" action="<?=e(url('/checkout/pedido/'.$order['code'].'/pagamento/atualizar'))?>">
          <?=csrf_field()?>
          <button class="button button--primary" type="submit">Verificar pagamento</button>
        </form>
      <?php elseif ($remoteUrl !== ''): ?>
        <h2><?= $method === 'card' ? 'Pagamento com cartão' : 'Pagamento por boleto' ?></h2>
        <p>Continue o pagamento na página segura do Asaas. A confirmação será registrada no seu pedido.</p>
        <a class="button button--primary" href="<?=e($remoteUrl)?>" target="_blank" rel="noopener noreferrer">Abrir pagamento seguro</a>
      <?php else: ?>
        <h2>Preparando cobrança</h2><p>Seu pedido está salvo. Confira novamente a cobrança sem gerar outro pedido.</p>
        <form method="post" action="<?=e(url('/checkout/pedido/'.$order['code'].'/pagamento/atualizar'))?>"><?=csrf_field()?><button class="button button--primary" type="submit">Verificar pagamento</button></form>
      <?php endif; ?>
    </section>
    <div class="checkout-result-actions"><a href="<?=e(url('/minha-conta/pedidos/'.$order['code']))?>">Ver detalhes na minha conta</a><a href="<?=e(url('/produtos'))?>">Continuar navegando</a></div>
  </main>
  <aside class="checkout-result-card checkout-result-summary">
    <div class="commerce-heading-kicker">RESUMO DA COMPRA</div><h2>Seu pedido</h2>
    <?php foreach($items as $item): ?><div class="checkout-result-line"><div><strong><?=e((string)$item['product_name'])?></strong><small><?= (int)$item['quantity']?> unidade(s)</small></div><span>R$ <?=number_format((float)$item['total'],2,',','.')?></span></div><?php endforeach;?>
    <dl><div><dt>Produtos</dt><dd>R$ <?=number_format((float)$order['products_total'],2,',','.')?></dd></div><div><dt>Entrega</dt><dd>R$ <?=number_format((float)$order['shipping_total'],2,',','.')?></dd></div><div><dt>Descontos</dt><dd>− R$ <?=number_format((float)$order['discount_total'],2,',','.')?></dd></div></dl>
    <div class="checkout-result-total"><span>Total</span><strong>R$ <?=number_format((float)$order['grand_total'],2,',','.')?></strong></div>
    <small>Pagamento <?=e(strtoupper($method))?> · confirmação pelo Asaas</small>
  </aside>
</div>
<?php endif; ?>
</div>