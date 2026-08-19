<?php
$statusOptions = [
    'pending_payment' => 'Aguardando pagamento',
    'paid' => 'Pago — preparar',
    'processing' => 'Em preparação',
    'shipped' => 'Enviado',
    'delivered' => 'Entregue',
    'cancelled' => 'Cancelado',
    'refunded' => 'Estornado',
];
$orderTypeLabels = ['retail' => 'Varejo', 'wholesale' => 'Atacado'];
$hasFilters = $filters['q'] !== '' || $filters['status'] !== '';
$ordersUrl = static function (string $status = '') use ($filters): string {
    $query = array_filter(['q' => $filters['q'], 'status' => $status], static fn(string $value): bool => $value !== '');
    return url('/vendedor/pedidos') . ($query ? '?' . http_build_query($query) : '');
};
$icons = [
    'orders' => '<path d="M5 3h14v18H5z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
    'paid' => '<path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="M3 7v10l9 5 9-5V7M12 12v10"/>',
    'processing' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'shipped' => '<path d="M3 6h11v11H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
];
$metricCards = [
    ['orders', 'Todos os pedidos', (int) ($counts['total'] ?? 0), '', 'Histórico da loja'],
    ['paid', 'Aguardando preparo', (int) ($counts['paid'] ?? 0), 'paid', 'Precisam de ação'],
    ['processing', 'Em preparação', (int) ($counts['processing'] ?? 0), 'processing', 'Sendo separados'],
    ['shipped', 'Enviados', (int) ($counts['shipped'] ?? 0), 'shipped', 'Em transporte'],
];
$quickFilters = [
    ['', 'Todos', (int) ($counts['total'] ?? 0)],
    ['paid', 'Preparar', (int) ($counts['paid'] ?? 0)],
    ['processing', 'Em preparação', (int) ($counts['processing'] ?? 0)],
    ['shipped', 'Enviados', (int) ($counts['shipped'] ?? 0)],
    ['delivered', 'Entregues', (int) ($counts['delivered'] ?? 0)],
];
?>

<div class="seller-orders-page">
    <header class="seller-orders-hero">
        <div>
            <span class="eyebrow"><?= e($currentStore['name']) ?> · OPERAÇÃO</span>
            <h2>Pedidos</h2>
            <p>Acompanhe cada venda desde a confirmação do pagamento até a entrega ao cliente.</p>
        </div>
        <div class="seller-orders-hero__summary">
            <small>Total líquido dos pedidos</small>
            <strong>R$ <?= number_format((float) ($counts['net_total'] ?? 0), 2, ',', '.') ?></strong>
            <span><?= (int) ($counts['total'] ?? 0) ?> pedido(s) no histórico</span>
        </div>
    </header>

    <section class="seller-orders-metrics" aria-label="Resumo dos pedidos">
        <?php foreach ($metricCards as [$icon, $label, $value, $status, $caption]): ?>
            <a class="seller-orders-metric <?= $status === 'paid' && $value > 0 ? 'has-attention' : '' ?> <?= $filters['status'] === $status && ($status !== '' || !$hasFilters) ? 'is-active' : '' ?>" href="<?= e($ordersUrl($status)) ?>">
                <i><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg></i>
                <span><small><?= e($label) ?></small><strong><?= $value ?></strong><em><?= e($caption) ?></em></span>
                <b aria-hidden="true">→</b>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="seller-orders-workspace">
        <header class="seller-orders-workspace__header">
            <div><span>GESTÃO DE PEDIDOS</span><h3><?= $hasFilters ? 'Resultados encontrados' : 'Pedidos recentes' ?></h3><p>Exibindo até 100 pedidos mais recentes desta loja.</p></div>
            <strong><?= count($orders) ?> resultado(s)</strong>
        </header>

        <nav class="seller-orders-tabs" aria-label="Filtrar pedidos por etapa">
            <?php foreach ($quickFilters as [$status, $label, $count]): ?>
                <a class="<?= $filters['status'] === $status ? 'is-active' : '' ?>" href="<?= e($ordersUrl($status)) ?>"><span><?= e($label) ?></span><b><?= $count ?></b></a>
            <?php endforeach; ?>
        </nav>

        <form class="seller-orders-filters" method="get" action="<?= e(url('/vendedor/pedidos')) ?>">
            <label class="seller-orders-search"><span>Buscar pedido</span><div><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input name="q" value="<?= e($filters['q']) ?>" placeholder="Número, cliente ou e-mail"></div></label>
            <label><span>Status</span><select name="status"><option value="">Todos os status</option><?php foreach ($statusOptions as $value => $label): ?><option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <div class="seller-orders-filters__actions"><button class="button button--primary">Aplicar filtros</button><?php if ($hasFilters): ?><a href="<?= e(url('/vendedor/pedidos')) ?>">Limpar filtros</a><?php endif; ?></div>
        </form>

        <?php if ($orders): ?>
            <div class="seller-orders-table-wrap">
                <table class="seller-orders-table">
                    <thead><tr><th>Pedido</th><th>Cliente</th><th>Recebido em</th><th>Valor líquido</th><th>Entrega</th><th>Status</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($orders as $order):
                        $createdAt = strtotime((string) $order['order_created_at']);
                        $customerInitial = mb_strtoupper(mb_substr(trim((string) $order['customer_name']) ?: 'C', 0, 1));
                        $tracking = trim((string) ($order['tracking_code'] ?? ''));
                        $service = trim((string) ($order['service_name'] ?? ''));
                    ?>
                        <tr>
                            <td data-label="Pedido"><div class="seller-order-code"><i>#</i><span><strong><?= e($order['code']) ?></strong><small>Pedido <?= e($order['order_code']) ?> · <?= e($orderTypeLabels[$order['order_type']] ?? ucfirst((string) $order['order_type'])) ?></small></span></div></td>
                            <td data-label="Cliente"><div class="seller-order-customer"><i><?= e($customerInitial) ?></i><span><strong><?= e($order['customer_name']) ?></strong><small><?= e($order['customer_email']) ?></small></span></div></td>
                            <td data-label="Recebido em"><div class="seller-order-date"><strong><?= date('d/m/Y', $createdAt) ?></strong><small><?= date('H:i', $createdAt) ?></small></div></td>
                            <td data-label="Valor líquido"><strong class="seller-order-total">R$ <?= number_format((float) $order['seller_net_total'], 2, ',', '.') ?></strong></td>
                            <td data-label="Entrega"><div class="seller-order-shipping"><strong><?= e($tracking ?: $service ?: 'A definir') ?></strong><small><?= $tracking !== '' ? 'Código de rastreio' : ($service !== '' ? 'Serviço selecionado' : 'Aguardando definição') ?></small></div></td>
                            <td data-label="Status"><?php $status = $order['status']; require dirname(__DIR__, 2) . '/components/dashboard/status-badge.php'; ?></td>
                            <td class="seller-order-action"><a href="<?= e(url('/vendedor/pedidos/' . $order['code'])) ?>" aria-label="Gerenciar pedido <?= e($order['code']) ?>"><span>Gerenciar</span><b>→</b></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="seller-orders-empty"><i><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14v18H5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg></i><h3>Nenhum pedido encontrado</h3><p><?= $hasFilters ? 'Tente remover os filtros ou buscar por outro termo.' : 'Quando uma nova venda for confirmada, ela aparecerá aqui para preparação.' ?></p><?php if ($hasFilters): ?><a class="button button--secondary" href="<?= e(url('/vendedor/pedidos')) ?>">Ver todos os pedidos</a><?php endif; ?></div>
        <?php endif; ?>
    </section>
</div>
