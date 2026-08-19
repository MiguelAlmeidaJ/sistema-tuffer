<div class="dashboard-heading">
    <div><span class="eyebrow">CARTEIRA DE FRETES</span><h2>Melhor Carteira</h2><p>Consulte o saldo central e gere uma recarga sem sair do painel da Tuffer.</p></div>
    <a class="button button--secondary" href="<?=e(url('/admin/financeiro'))?>">Voltar ao financeiro</a>
</div>

<div class="stat-grid">
    <?php $stat=['label'=>'Saldo disponível','value'=>$balance===null?'Indisponível':'R$ '.number_format((float)$balance,2,',','.')];require dirname(__DIR__,2).'/components/dashboard/stat-card.php';?>
    <?php $stat=['label'=>'Integração','value'=>$walletConfigured?'Conectada':'Não configurada'];require dirname(__DIR__,2).'/components/dashboard/stat-card.php';?>
</div>

<?php if($balanceError):?><div class="alert alert--error"><?=e($balanceError)?></div><?php endif;?>

<section class="panel">
    <div class="panel-head"><div><small>NOVA RECARGA</small><h3>Adicionar saldo</h3></div></div>
    <p>A solicitação gera um pagamento no Melhor Envio. O saldo será atualizado somente depois que o PIX ou boleto for efetivamente pago e aprovado.</p>
    <form class="resource-form" method="post" action="<?=e(url('/admin/financeiro/carteira-fretes'))?>">
        <?=csrf_field()?>
        <div class="form-grid">
            <label>Valor da recarga (R$)<input type="number" name="amount" min="10" max="50000" step="0.01" required placeholder="500,00"></label>
            <label>Forma de pagamento<select name="method" required><option value="pix">PIX</option><option value="boleto">Boleto</option></select></label>
        </div>
        <label class="checkbox"><input type="checkbox" name="confirm_topup" value="1" required> Confirmo a geração desta cobrança para abastecer a carteira de fretes da Tuffer.</label>
        <div class="form-actions"><button class="button button--primary" <?=$walletConfigured?'':'disabled'?>>Gerar recarga</button></div>
    </form>
</section>

<section class="panel">
    <div class="panel-head"><div><small>CONTROLE</small><h3>Solicitações recentes</h3></div></div>
    <div class="table-wrap"><table><thead><tr><th>Data</th><th>Responsável</th><th>Método</th><th>Valor</th><th>Status</th><th>Pagamento</th></tr></thead><tbody>
    <?php foreach($topups as $topup):?><tr><td><?=date('d/m/Y H:i',strtotime((string)$topup['created_at']))?></td><td><?=e($topup['admin_name'])?></td><td><?=e(mb_strtoupper($topup['method']))?></td><td>R$ <?=number_format((float)$topup['amount'],2,',','.')?></td><td><?=e($topup['provider_status'])?></td><td><?php if($topup['payment_url']):?><a class="button button--secondary" href="<?=e($topup['payment_url'])?>" target="_blank" rel="noopener noreferrer">Abrir pagamento</a><?php else:?>Consultar provedor<?php endif;?></td></tr><?php endforeach;?>
    <?php if(!$topups):?><tr><td colspan="6">Nenhuma recarga solicitada pelo painel.</td></tr><?php endif;?>
    </tbody></table></div>
</section>
