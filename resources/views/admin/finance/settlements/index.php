<?php
$money = static fn(int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$ownerLabels = [
    'official_store' => 'Loja oficial',
    'marketplace' => 'Plataforma',
    'consolidated' => 'Consolidado',
];
$statusLabels = [
    'awaiting_review' => 'Aguardando revisão',
    'approved' => 'Aprovado',
    'partially_transferred' => 'Transferência parcial',
    'transferred' => 'Transferido',
    'canceled' => 'Cancelado',
];
$statusClasses = [
    'awaiting_review' => 'is-warning',
    'approved' => 'is-ready',
    'partially_transferred' => 'is-info',
    'transferred' => 'is-success',
    'canceled' => 'is-muted',
];

$totalSettlements = count($settlements);
$awaitingReview = 0;
$pendingTransferCents = 0;
$transferredCents = 0;
foreach ($settlements as $settlementItem) {
    $status = (string) ($settlementItem['status'] ?? '');
    if ($status === 'awaiting_review') $awaitingReview++;
    if ($status !== 'canceled') {
        $pendingTransferCents += max(
            0,
            (int) ($settlementItem['transferable_amount_cents'] ?? 0)
            - (int) ($settlementItem['transferred_amount_cents'] ?? 0)
        );
    }
    $transferredCents += (int) ($settlementItem['transferred_amount_cents'] ?? 0);
}
$hasFilters = $owner !== '' || $periodStart !== '' || $periodEnd !== '';
$generateStart = $periodStart;
$generateEnd = $periodEnd;
?>
<style>
.settlements-page{display:grid;gap:18px}.settlements-page .dashboard-heading{margin-bottom:0;align-items:flex-end}.settlements-heading__actions{display:flex;gap:10px;flex-wrap:wrap}.settlements-heading__actions .button{text-decoration:none}.settlement-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.settlement-kpi{background:#fff;border:1px solid #e5e2dc;border-radius:16px;padding:18px 20px;min-height:112px;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 1px 0 rgba(17,17,17,.02)}.settlement-kpi small{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#777}.settlement-kpi strong{font-size:24px;line-height:1.1;color:#151515}.settlement-kpi span{font-size:12px;color:#777}.settlements-panel{margin:0}.settlements-panel .panel-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:18px}.settlements-panel .panel-head h3{margin:0 0 5px}.settlements-panel .panel-head p{margin:0;color:#737373;font-size:13px}.settlements-filter{display:grid;grid-template-columns:minmax(220px,1.15fr) repeat(2,minmax(180px,.8fr)) auto;gap:12px;align-items:end}.settlements-filter label,.settlement-create-form label{display:grid;gap:7px;font-weight:700;font-size:13px}.settlements-filter select,.settlements-filter input,.settlement-create-form select,.settlement-create-form input{width:100%}.settlements-filter__actions{display:flex;gap:8px;align-items:center}.settlements-filter__actions .button{white-space:nowrap}.settlements-clear{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 10px;color:#666;font-weight:700;font-size:13px;text-decoration:none}.settlements-clear:hover{color:#111}.settlement-create{display:grid;grid-template-columns:minmax(260px,.85fr) minmax(520px,1.65fr);gap:28px;align-items:start}.settlement-create__intro{padding-right:12px}.settlement-create__intro h3{font-size:24px;margin:6px 0 8px}.settlement-create__intro p{margin:0;color:#666;line-height:1.55}.settlement-create__flow{display:flex;flex-wrap:wrap;gap:7px;margin-top:18px}.settlement-create__flow span{border:1px solid #e6e2dc;background:#faf9f7;border-radius:999px;padding:7px 10px;font-size:12px;font-weight:750;color:#555}.settlement-create__notice{margin-top:16px;border-left:3px solid #e30613;background:#fff7f7;border-radius:0 10px 10px 0;padding:11px 13px;color:#5f4545;font-size:12px;line-height:1.45}.settlement-create-form{display:grid;grid-template-columns:1.15fr .8fr .8fr;gap:12px;align-items:end}.settlement-create-form__footer{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;gap:12px;padding-top:4px}.settlement-create-form__hint{font-size:12px;color:#777;max-width:520px}.settlement-period-shortcuts{display:flex;gap:7px;flex-wrap:wrap;grid-column:1/-1}.settlement-period-shortcuts button{appearance:none;border:1px solid #dedad3;background:#fff;border-radius:999px;padding:7px 10px;font:inherit;font-size:12px;font-weight:750;cursor:pointer;color:#4d4d4d}.settlement-period-shortcuts button:hover{border-color:#bdb8b0;color:#111;background:#faf9f7}.settlements-history-head{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:14px}.settlements-history-head h3{margin:0}.settlements-history-head span{font-size:12px;color:#777}.settlement-period{display:grid;gap:2px;white-space:nowrap}.settlement-period strong{font-size:13px}.settlement-period small{color:#777}.settlement-owner{font-weight:750}.settlement-money{white-space:nowrap;font-variant-numeric:tabular-nums}.settlement-money--pending{font-weight:800}.settlement-status{display:inline-flex;align-items:center;gap:7px;border-radius:999px;padding:7px 10px;font-size:11px;font-weight:800;white-space:nowrap;border:1px solid transparent}.settlement-status::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}.settlement-status.is-warning{color:#8a6200;background:#fff8df;border-color:#f3e5a7}.settlement-status.is-ready{color:#256b3a;background:#eff9f1;border-color:#cfe9d5}.settlement-status.is-info{color:#315d86;background:#eef6fd;border-color:#d2e5f5}.settlement-status.is-success{color:#15733b;background:#edf9f1;border-color:#c9ead5}.settlement-status.is-muted{color:#777;background:#f4f4f3;border-color:#e3e3e0}.settlement-open{font-weight:800;text-decoration:none;white-space:nowrap;color:#111}.settlement-open:hover{text-decoration:underline}.settlement-empty{padding:38px 20px!important;text-align:center!important;color:#666}.settlement-empty strong{display:block;color:#222;font-size:16px;margin-bottom:4px}.settlement-empty span{font-size:13px}.settlements-page table th{white-space:nowrap}.settlements-page table td{vertical-align:middle}@media(max-width:1100px){.settlement-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.settlements-filter{grid-template-columns:1fr 1fr}.settlements-filter__actions{justify-content:flex-start}.settlement-create{grid-template-columns:1fr}.settlement-create-form{grid-template-columns:1fr 1fr 1fr}}@media(max-width:720px){.settlements-page .dashboard-heading{align-items:flex-start}.settlements-heading__actions{width:100%}.settlements-heading__actions .button{flex:1}.settlement-kpis{grid-template-columns:1fr 1fr}.settlement-kpi{min-height:100px;padding:15px}.settlement-kpi strong{font-size:20px}.settlements-filter,.settlement-create-form{grid-template-columns:1fr}.settlement-create-form__footer{align-items:stretch;flex-direction:column}.settlement-create-form__footer .button{width:100%}.settlements-filter__actions{grid-column:1/-1}.settlements-panel .panel-head{flex-direction:column}.settlement-create{gap:18px}}@media(max-width:460px){.settlement-kpis{grid-template-columns:1fr}.settlements-heading__actions{display:grid;grid-template-columns:1fr}}
</style>

<div class="settlements-page">
    <div class="dashboard-heading">
        <div>
            <span class="eyebrow">FECHAMENTO</span>
            <h2>Fechamentos financeiros</h2>
            <p>Consolide períodos do livro financeiro, revise os valores e acompanhe o que ainda precisa ser transferido.</p>
        </div>
        <div class="settlements-heading__actions">
            <a class="button button--secondary" href="<?= e(url('/admin/financeiro')) ?>">Visão financeira</a>
            <a class="button button--primary" href="#novo-fechamento">Novo fechamento</a>
        </div>
    </div>

    <section class="settlement-kpis" aria-label="Resumo dos fechamentos exibidos">
        <article class="settlement-kpi">
            <small>Fechamentos</small>
            <strong><?= $totalSettlements ?></strong>
            <span><?= $hasFilters ? 'No filtro atual' : 'Últimos registros exibidos' ?></span>
        </article>
        <article class="settlement-kpi">
            <small>Aguardando revisão</small>
            <strong><?= $awaitingReview ?></strong>
            <span>Precisam de conferência do admin</span>
        </article>
        <article class="settlement-kpi">
            <small>Saldo a transferir</small>
            <strong><?= $money($pendingTransferCents) ?></strong>
            <span>Transferível menos o que já saiu</span>
        </article>
        <article class="settlement-kpi">
            <small>Já transferido</small>
            <strong><?= $money($transferredCents) ?></strong>
            <span>Nos fechamentos desta listagem</span>
        </article>
    </section>

    <section class="panel settlements-panel">
        <div class="panel-head">
            <div>
                <h3>Localizar fechamentos</h3>
                <p>Filtre por centro financeiro e pelo período que deseja auditar.</p>
            </div>
            <?php if ($hasFilters): ?><a class="settlements-clear" href="<?= e(url('/admin/financeiro/fechamentos')) ?>">Limpar filtros</a><?php endif; ?>
        </div>
        <form class="settlements-filter" method="get" action="<?= e(url('/admin/financeiro/fechamentos')) ?>">
            <label>Centro financeiro
                <select name="owner">
                    <option value="">Todos os centros</option>
                    <?php foreach ($ownerLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= $owner === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Período a partir de<input type="date" name="period_start" value="<?= e($periodStart) ?>"></label>
            <label>Período até<input type="date" name="period_end" value="<?= e($periodEnd) ?>"></label>
            <div class="settlements-filter__actions"><button class="button button--secondary">Aplicar filtros</button></div>
        </form>
    </section>

    <section class="panel settlement-create" id="novo-fechamento">
        <div class="settlement-create__intro">
            <span class="eyebrow">NOVO FECHAMENTO</span>
            <h3>Gerar fechamento</h3>
            <p>Crie uma fotografia financeira do período para revisão. A geração não movimenta dinheiro nem executa transferência bancária.</p>
            <div class="settlement-create__flow" aria-label="Etapas do fechamento">
                <span>1. Gerar</span><span>2. Revisar</span><span>3. Aprovar</span><span>4. Registrar transferência</span>
            </div>
            <div class="settlement-create__notice">O fechamento usa exclusivamente lançamentos confirmados do livro financeiro. Se o mesmo centro e período já existirem, a Tuffer abre o fechamento existente em vez de duplicá-lo.</div>
        </div>
        <form class="settlement-create-form" method="post" action="<?= e(url('/admin/financeiro/fechamentos')) ?>" id="settlement-create-form">
            <?= csrf_field() ?>
            <label>Centro financeiro
                <select name="financial_owner" required>
                    <option value="official_store">Loja oficial</option>
                    <option value="marketplace">Plataforma</option>
                    <option value="consolidated">Consolidado</option>
                </select>
            </label>
            <label>Início<input id="settlement-period-start" type="date" name="period_start" value="<?= e($generateStart) ?>" required></label>
            <label>Fim<input id="settlement-period-end" type="date" name="period_end" value="<?= e($generateEnd) ?>" required></label>
            <div class="settlement-period-shortcuts">
                <button type="button" data-settlement-period="current">Este mês</button>
                <button type="button" data-settlement-period="previous">Mês anterior</button>
            </div>
            <div class="settlement-create-form__footer">
                <span class="settlement-create-form__hint">Depois de gerar, abra o fechamento para conferir a composição antes de aprovar qualquer transferência.</span>
                <button class="button button--primary">Gerar fechamento</button>
            </div>
        </form>
    </section>

    <section class="panel settlements-panel">
        <div class="settlements-history-head">
            <div><span class="eyebrow">HISTÓRICO</span><h3>Fechamentos gerados</h3></div>
            <span>Exibindo até 120 registros</span>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th>Período</th><th>Centro</th><th>Receita líquida</th><th>Transferível</th><th>Transferido</th><th>Pendente</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($settlements as $item):
                $itemStatus = (string) ($item['status'] ?? '');
                $transferable = (int) ($item['transferable_amount_cents'] ?? 0);
                $transferred = (int) ($item['transferred_amount_cents'] ?? 0);
                $pending = $itemStatus === 'canceled' ? 0 : max(0, $transferable - $transferred);
            ?>
                <tr>
                    <td><div class="settlement-period"><strong><?= date('d/m/Y', strtotime($item['period_start'])) ?> → <?= date('d/m/Y', strtotime($item['period_end'])) ?></strong><small>Fechamento #<?= (int) $item['id'] ?></small></div></td>
                    <td class="settlement-owner"><?= e($ownerLabels[$item['financial_owner']] ?? (string) $item['financial_owner']) ?></td>
                    <td class="settlement-money"><?= $money((int) $item['net_revenue_cents']) ?></td>
                    <td class="settlement-money"><?= $money($transferable) ?></td>
                    <td class="settlement-money"><?= $money($transferred) ?></td>
                    <td class="settlement-money settlement-money--pending"><?= $money($pending) ?></td>
                    <td><span class="settlement-status <?= e($statusClasses[$itemStatus] ?? 'is-muted') ?>"><?= e($statusLabels[$itemStatus] ?? $itemStatus) ?></span></td>
                    <td><a class="settlement-open" href="<?= e(url('/admin/financeiro/fechamentos/' . $item['id'])) ?>">Ver detalhes →</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$settlements): ?>
                <tr><td colspan="8" class="settlement-empty"><strong>Nenhum fechamento encontrado</strong><span><?= $hasFilters ? 'Ajuste os filtros ou limpe a busca para ver outros períodos.' : 'Gere o primeiro fechamento usando o formulário acima.' ?></span></td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </section>
</div>

<script>
(() => {
    const start = document.getElementById('settlement-period-start');
    const end = document.getElementById('settlement-period-end');
    if (!start || !end) return;
    const format = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };
    document.querySelectorAll('[data-settlement-period]').forEach((button) => {
        button.addEventListener('click', () => {
            const now = new Date();
            let first;
            let last;
            if (button.dataset.settlementPeriod === 'previous') {
                first = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                last = new Date(now.getFullYear(), now.getMonth(), 0);
            } else {
                first = new Date(now.getFullYear(), now.getMonth(), 1);
                last = now;
            }
            start.value = format(first);
            end.value = format(last);
            start.focus();
        });
    });
})();
</script>
