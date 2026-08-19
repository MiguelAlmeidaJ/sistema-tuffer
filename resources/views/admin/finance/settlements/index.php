<div class="dashboard-heading"><div><span class="eyebrow">FECHAMENTO</span><h2>Fechamentos financeiros</h2><p>Calculados exclusivamente a partir do livro financeiro.</p></div></div>
<form class="panel resource-form" method="get" action="<?= e(url('/admin/financeiro/fechamentos')) ?>">
    <div class="form-grid">
        <label>Centro financeiro<select name="owner"><option value="">Todos</option><?php foreach (['official_store'=>'Loja oficial','marketplace'=>'Plataforma','consolidated'=>'Consolidado'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $owner===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Período a partir de<input type="date" name="period_start" value="<?= e($periodStart) ?>"></label>
        <label>Período até<input type="date" name="period_end" value="<?= e($periodEnd) ?>"></label>
    </div>
    <div class="form-actions"><button class="button button--secondary">Filtrar</button></div>
</form>
<form class="panel resource-form" method="post" action="<?= e(url('/admin/financeiro/fechamentos')) ?>">
    <?= csrf_field() ?>
    <h3>Gerar fechamento</h3>
    <div class="form-grid">
        <label>Centro financeiro<select name="financial_owner" required><option value="official_store">Loja oficial</option><option value="marketplace">Plataforma</option><option value="consolidated">Consolidado</option></select></label>
        <label>Início<input type="date" name="period_start" required></label>
        <label>Fim<input type="date" name="period_end" required></label>
    </div>
    <div class="form-actions"><button class="button button--primary">Gerar fechamento</button></div>
</form>
<section class="panel"><div class="table-wrap"><table><thead><tr><th>Período</th><th>Centro</th><th>Receita líquida</th><th>Transferível</th><th>Transferido</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($settlements as $item): ?><tr><td><?= date('d/m/Y',strtotime($item['period_start'])) ?> a <?= date('d/m/Y',strtotime($item['period_end'])) ?></td><td><?= e($item['financial_owner']) ?></td><td>R$ <?= number_format($item['net_revenue_cents']/100,2,',','.') ?></td><td>R$ <?= number_format($item['transferable_amount_cents']/100,2,',','.') ?></td><td>R$ <?= number_format($item['transferred_amount_cents']/100,2,',','.') ?></td><td><?= e($item['status']) ?></td><td><a href="<?= e(url('/admin/financeiro/fechamentos/'.$item['id'])) ?>">Abrir</a></td></tr><?php endforeach; ?>
<?php if (!$settlements): ?><tr><td colspan="7">Nenhum fechamento gerado.</td></tr><?php endif; ?></tbody></table></div></section>
