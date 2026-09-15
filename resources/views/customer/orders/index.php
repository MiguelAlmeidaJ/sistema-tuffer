<?php
$money = static fn(float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
$baseUrl = url('/minha-conta/pedidos');
$baseQuery = $type === 'wholesale' ? ['tipo' => 'atacado'] : [];
$filterUrl = static function(string $filter = '') use ($baseUrl, $baseQuery): string {
    $query = $baseQuery;
    if ($filter !== '') $query['situacao'] = $filter;
    return $baseUrl . ($query ? '?' . http_build_query($query) : '');
};
$hasFilters = $situation !== '' || $search !== '';
$statusMessages = [
    'pending' => 'Pedido recebido e aguardando atualização.',
    'pending_payment' => 'Aguardando confirmação do pagamento.',
    'paid' => 'Pagamento confirmado.',
    'processing' => 'Pedido em preparação.',
    'completed' => 'Pedido concluído.',
    'cancelled' => 'Pedido cancelado.',
];
?>
<style>
.customer-orders{display:grid;gap:20px}.customer-orders .dashboard-heading{margin-bottom:0;align-items:flex-end}.customer-orders__heading-actions{display:flex;gap:10px;flex-wrap:wrap}.customer-orders__heading-actions .button{text-decoration:none}.customer-orders__summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.customer-orders__summary-card{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:15px 17px;border:1px solid #e4e0d8;border-radius:16px;background:#fff;color:#111;text-decoration:none}.customer-orders__summary-card:hover{background:#faf9f7;border-color:#cbc5bb}.customer-orders__summary-card.is-active{border-color:#d90a18;box-shadow:inset 0 0 0 1px #d90a18}.customer-orders__summary-card span{font-size:13px;color:#6f6962}.customer-orders__summary-card strong{font-size:22px}.customer-orders__filter-panel{margin:0}.customer-orders__filter{display:grid;grid-template-columns:minmax(240px,1fr) minmax(190px,.45fr) auto;gap:12px;align-items:end}.customer-orders__filter label{display:grid;gap:7px;font-size:13px;font-weight:750}.customer-orders__filter input,.customer-orders__filter select{width:100%}.customer-orders__filter-actions{display:flex;gap:8px;align-items:center}.customer-orders__clear{display:inline-flex;align-items:center;min-height:42px;padding:0 8px;color:#666;font-weight:700;text-decoration:none;font-size:13px}.customer-orders__list-panel{margin:0;padding:0;overflow:hidden}.customer-orders__list-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;padding:20px 22px;border-bottom:1px solid #e7e3dc}.customer-orders__list-head h3{margin:4px 0 0}.customer-orders__list-head span{font-size:12px;color:#777}.customer-orders__list{display:grid}.customer-order{display:grid;grid-template-columns:minmax(250px,1.35fr) minmax(130px,.6fr) minmax(130px,.55fr) minmax(180px,.8fr) auto;gap:18px;align-items:center;padding:20px 22px;border-bottom:1px solid #ece8e1}.customer-order:last-child{border-bottom:0}.customer-order:hover{background:#fcfbf9}.customer-order__identity{display:grid;gap:6px}.customer-order__identity small{font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:800;color:#8a847c}.customer-order__identity strong{font-size:15px}.customer-order__type{display:inline-flex;width:max-content;padding:4px 7px;border-radius:999px;background:#f4f1eb;color:#6e675f;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.customer-order__meta{display:grid;gap:5px}.customer-order__meta small{font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:800;color:#8a847c}.customer-order__meta strong{font-size:14px}.customer-order__status{display:grid;gap:7px;justify-items:start}.customer-order__status small{font-size:12px;color:#777;line-height:1.35}.customer-order__action .button{text-decoration:none;white-space:nowrap}.customer-orders__empty{padding:48px 24px;text-align:center}.customer-orders__empty-icon{width:54px;height:54px;border-radius:17px;background:#fff0f1;color:#d90a18;display:grid;place-items:center;margin:0 auto 14px;font-size:23px;font-weight:800}.customer-orders__empty h3{margin:0 0 7px}.customer-orders__empty p{margin:0 auto 18px;max-width:460px;color:#777;line-height:1.5}.customer-orders__empty-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}.customer-orders__empty-actions .button{text-decoration:none}@media(max-width:1120px){.customer-orders__summary{grid-template-columns:repeat(2,minmax(0,1fr))}.customer-order{grid-template-columns:minmax(220px,1.2fr) minmax(120px,.55fr) minmax(130px,.55fr) auto}.customer-order__status{grid-column:1/4}.customer-order__action{grid-column:4;grid-row:1/3}}@media(max-width:760px){.customer-orders .dashboard-heading{align-items:flex-start}.customer-orders__heading-actions{width:100%}.customer-orders__heading-actions .button{flex:1}.customer-orders__filter{grid-template-columns:1fr}.customer-orders__filter-actions{grid-column:1}.customer-order{grid-template-columns:1fr 1fr;padding:18px}.customer-order__identity{grid-column:1/-1}.customer-order__status{grid-column:1/-1}.customer-order__action{grid-column:1/-1;grid-row:auto}.customer-order__action .button{width:100%;text-align:center}.customer-orders__list-head{padding:18px}.customer-orders__summary-card{padding:14px}}@media(max-width:430px){.customer-orders__summary{grid-template-columns:1fr}.customer-order{grid-template-columns:1fr}.customer-order__meta,.customer-order__status,.customer-order__action{grid-column:1}}
</style>

<div class="customer-orders">
    <div class="dashboard-heading">
        <div>
            <span class="eyebrow"><?= $type === 'wholesale' ? 'ATACADO' : 'SUAS COMPRAS' ?></span>
            <h2><?= $type === 'wholesale' ? 'Pedidos de atacado' : 'Meus pedidos' ?></h2>
            <p>Acompanhe pagamentos, andamento e histórico das suas compras em um só lugar.</p>
        </div>
        <div class="customer-orders__heading-actions">
            <a class="button button--secondary" href="<?= e(url('/minha-conta')) ?>">Visão geral</a>
            <a class="button button--primary" href="<?= e(url('/produtos')) ?>">Continuar comprando</a>
        </div>
    </div>

    <section class="customer-orders__summary" aria-label="Resumo dos pedidos">
        <a class="customer-orders__summary-card <?= $situation === '' ? 'is-active' : '' ?>" href="<?= e($filterUrl()) ?>"><span>Todos</span><strong><?= (int) ($summary['total'] ?? 0) ?></strong></a>
        <a class="customer-orders__summary-card <?= $situation === 'andamento' ? 'is-active' : '' ?>" href="<?= e($filterUrl('andamento')) ?>"><span>Em andamento</span><strong><?= (int) ($summary['ongoing'] ?? 0) ?></strong></a>
        <a class="customer-orders__summary-card <?= $situation === 'concluidos' ? 'is-active' : '' ?>" href="<?= e($filterUrl('concluidos')) ?>"><span>Concluídos</span><strong><?= (int) ($summary['completed'] ?? 0) ?></strong></a>
        <a class="customer-orders__summary-card <?= $situation === 'cancelados' ? 'is-active' : '' ?>" href="<?= e($filterUrl('cancelados')) ?>"><span>Cancelados</span><strong><?= (int) ($summary['cancelled'] ?? 0) ?></strong></a>
    </section>

    <section class="panel customer-orders__filter-panel">
        <form class="customer-orders__filter" method="get" action="<?= e($baseUrl) ?>">
            <?php if ($type === 'wholesale'): ?><input type="hidden" name="tipo" value="atacado"><?php endif; ?>
            <label>Buscar pedido
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Ex.: TF-30-4A5966686328" autocomplete="off">
            </label>
            <label>Status
                <select name="situacao">
                    <option value="">Todos</option>
                    <option value="andamento" <?= $situation === 'andamento' ? 'selected' : '' ?>>Em andamento</option>
                    <option value="concluidos" <?= $situation === 'concluidos' ? 'selected' : '' ?>>Concluídos</option>
                    <option value="cancelados" <?= $situation === 'cancelados' ? 'selected' : '' ?>>Cancelados</option>
                </select>
            </label>
            <div class="customer-orders__filter-actions">
                <button class="button button--secondary">Filtrar</button>
                <?php if ($hasFilters): ?><a class="customer-orders__clear" href="<?= e($filterUrl()) ?>">Limpar</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel customer-orders__list-panel">
        <div class="customer-orders__list-head">
            <div><span class="eyebrow">HISTÓRICO</span><h3><?= $hasFilters ? 'Resultados encontrados' : 'Pedidos recentes' ?></h3></div>
            <span><?= count($orders) ?> <?= count($orders) === 1 ? 'pedido' : 'pedidos' ?> nesta visualização</span>
        </div>

        <?php if ($orders): ?>
            <div class="customer-orders__list">
                <?php foreach ($orders as $order):
                    $orderStatus = (string) ($order['status'] ?? 'pending');
                ?>
                    <article class="customer-order">
                        <div class="customer-order__identity">
                            <small>Pedido</small>
                            <strong><?= e((string) $order['code']) ?></strong>
                            <?php if (($order['order_type'] ?? '') === 'wholesale'): ?><span class="customer-order__type">Atacado</span><?php endif; ?>
                        </div>
                        <div class="customer-order__meta">
                            <small>Data</small>
                            <strong><?= date('d/m/Y', strtotime((string) $order['created_at'])) ?></strong>
                        </div>
                        <div class="customer-order__meta">
                            <small>Total</small>
                            <strong><?= $money((float) $order['grand_total']) ?></strong>
                        </div>
                        <div class="customer-order__status">
                            <div><?php $status = $orderStatus; require dirname(__DIR__, 2) . '/components/dashboard/status-badge.php'; ?></div>
                            <small><?= e($statusMessages[$orderStatus] ?? 'Abra os detalhes para acompanhar a atualização deste pedido.') ?></small>
                        </div>
                        <div class="customer-order__action">
                            <a class="button button--secondary" href="<?= e(url('/minha-conta/pedidos/' . $order['code'])) ?>">Ver pedido</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="customer-orders__empty">
                <div class="customer-orders__empty-icon">↗</div>
                <h3><?= $hasFilters ? 'Nenhum pedido encontrado' : 'Você ainda não fez pedidos' ?></h3>
                <p><?= $hasFilters ? 'Tente buscar por outro código ou altere o filtro de status.' : 'Quando você concluir uma compra, ela aparecerá aqui com o status e todos os detalhes.' ?></p>
                <div class="customer-orders__empty-actions">
                    <?php if ($hasFilters): ?><a class="button button--secondary" href="<?= e($filterUrl()) ?>">Limpar filtros</a><?php endif; ?>
                    <a class="button button--primary" href="<?= e(url('/produtos')) ?>">Explorar produtos</a>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>
