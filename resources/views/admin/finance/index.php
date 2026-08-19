<?php
$money = static fn(?int $cents): string => $cents === null ? 'Não calculado' : 'R$ ' . number_format($cents / 100, 2, ',', '.');
$stats = static function(array $cards): void {
    foreach ($cards as [$label, $value]) {
        $stat = ['label' => $label, 'value' => $value];
        require dirname(__DIR__, 2) . '/components/dashboard/stat-card.php';
    }
};
?>
<div class="dashboard-heading">
    <div><span class="eyebrow">LIVRO FINANCEIRO</span><h2>Composição financeira da Tuffer</h2><p>Loja oficial e plataforma permanecem separadas mesmo quando usam o mesmo recipient.</p></div>
    <div><a class="button button--secondary" href="<?= e(url('/admin/financeiro/carteira-fretes')) ?>">Carteira de fretes</a> <a class="button button--primary" href="<?= e(url('/admin/financeiro/fechamentos')) ?>">Fechamentos</a></div>
</div>
<section class="panel"><div class="panel-head"><h3>Loja oficial</h3></div><div class="stat-grid"><?php $stats([
    ['Faturamento', $money((int) $official['gross'])],
    ['Receita líquida', $money((int) $official['net'])],
    ['Custo dos produtos', $money($official['product_cost'] === null ? null : (int) $official['product_cost'])],
    ['Taxas', $money((int) $official['fees'])],
    ['Impostos provisionados', $money((int) $official['taxes'])],
    ['Estornos/chargebacks', $money((int) $official['chargebacks'])],
    ['Lucro estimado', $money($official['estimated_profit'])],
    ['Reservas', $money((int) $official['reserves'])],
    ['Valor transferível', $money($official['transferable'] === null ? null : (int) $official['transferable'])],
    ['Já transferido', $money((int) $official['transferred'])],
]); ?></div></section>
<section class="panel"><div class="panel-head"><h3>Plataforma / marketplace</h3></div><div class="stat-grid"><?php $stats([
    ['Comissão e serviços', $money((int) $platform['gross'])],
    ['Receita líquida', $money((int) $platform['net'])],
    ['Cupons subsidiados', $money((int) $platform['coupons'])],
    ['Taxas Pagar.me', $money((int) $platform['fees'])],
    ['Chargebacks', $money((int) $platform['chargebacks'])],
    ['Reservas', $money((int) $platform['reserves'])],
    ['Saldo disponível', $money((int) $platform['transferable'])],
]); ?></div></section>
<section class="panel"><div class="panel-head"><h3>Consolidado Tuffer</h3></div><div class="stat-grid"><?php $stats([
    ['Total recebido', $money($consolidated['received'])],
    ['Composição loja', $money($consolidated['official'])],
    ['Composição plataforma', $money($consolidated['platform'])],
    ['Reservado', $money($consolidated['reserved'])],
    ['Transferido', $money($consolidated['transferred'])],
    ['Saldo Pagar.me', 'Não consultado'],
    ['Saldo bancário informado', 'Não informado'],
    ['Divergências', (string) $consolidated['issues']],
    ['Fechamentos pendentes', (string) $consolidated['pending_settlements']],
]); ?></div></section>
<section class="panel"><div class="panel-head"><h3>Lançamentos recentes</h3></div><div class="table-wrap"><table><thead><tr><th>Período</th><th>Centro</th><th>Tipo</th><th>Pedido</th><th>Direção</th><th>Valor</th><th>Status</th></tr></thead><tbody>
<?php foreach ($entries as $entry): ?><tr><td><?= e($entry['accounting_period']) ?></td><td><?= e($entry['financial_owner']) ?></td><td><?= e($entry['entry_type']) ?></td><td><?= e($entry['order_code'] ?? '—') ?></td><td><?= e($entry['direction']) ?></td><td><?= $money((int) $entry['amount_cents']) ?></td><td><?= e($entry['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$entries): ?><tr><td colspan="7">Nenhum lançamento confirmado.</td></tr><?php endif; ?></tbody></table></div></section>
