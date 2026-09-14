<?php /* admin orders visual module */ ?>
<link rel="stylesheet" href="<?=e(asset('css/admin/orders.css?v=20260914-admin-orders'))?>">
<?php
$statusLabels = [
    '' => 'Todos',
    'pending_payment' => 'Aguardando pagamento',
    'active' => 'Em andamento (pago ou processando)',
    'paid' => 'Pago',
    'processing' => 'Em processamento',
    'completed' => 'Concluído',
    'cancelled' => 'Cancelado',
    'refunded' => 'Estornado',
];
$filterBase = url('/admin/pedidos');
$activeStatus = (string) ($filters['status'] ?? '');
$metrics = [
    ['status' => 'pending_payment', 'label' => 'Aguardando pagamento', 'value' => (int) ($counts['pending_payment'] ?? 0), 'hint' => 'Pedidos que ainda não foram pagos', 'icon' => 'clock'],
    ['status' => 'active', 'label' => 'Em andamento', 'value' => (int) ($counts['active'] ?? 0), 'hint' => 'Pagos ou em processamento', 'icon' => 'activity'],
    ['status' => 'cancelled', 'label' => 'Cancelados', 'value' => (int) ($counts['cancelled'] ?? 0), 'hint' => 'Inclui testes e falhas encerradas', 'icon' => 'x'],
    ['status' => 'completed', 'label' => 'Concluídos', 'value' => (int) ($counts['completed'] ?? 0), 'hint' => 'Pedidos finalizados', 'icon' => 'check'],
];
?>
<div class="admin-orders-page">
    <section class="admin-orders-hero">
        <div>
            <span class="eyebrow">OPERAÇÃO</span>
            <h2>Gestão de pedidos</h2>
            <p>Visualize pagamentos, identifique falhas e encerre testes sem misturar pedidos reais com tentativas inválidas.</p>
        </div>
        <div class="admin-orders-hero__summary">
            <small>Visão atual</small>
            <strong><?=(int)($counts['total'] ?? 0)?></strong>
            <span>pedidos registrados</span>
        </div>
    </section>

    <nav class="admin-orders-metrics" aria-label="Resumo dos pedidos">
        <?php foreach ($metrics as $metric): ?>
            <?php
            $metricQuery = '?status=' . rawurlencode($metric['status']);
            $metricActive = $activeStatus === $metric['status'];
            ?>
            <a class="admin-orders-metric <?=$metricActive ? 'is-active' : ''?> <?=$metric['status'] === 'pending_payment' ? 'has-attention' : ''?>"
               href="<?=e($filterBase . $metricQuery)?>">
                <i aria-hidden="true">
                    <?php if ($metric['icon'] === 'clock'): ?>
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg>
                    <?php elseif ($metric['icon'] === 'activity'): ?>
                        <svg viewBox="0 0 24 24"><path d="M4 13h3l2-5 4 10 2-5h5"/></svg>
                    <?php elseif ($metric['icon'] === 'x'): ?>
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="m9 9 6 6m0-6-6 6"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="m8.5 12 2.3 2.3 4.8-5"/></svg>
                    <?php endif; ?>
                </i>
                <span>
                    <small><?=e($metric['label'])?></small>
                    <strong><?=(int)$metric['value']?></strong>
                    <em><?=e($metric['hint'])?></em>
                </span>
                <b>→</b>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="admin-orders-workspace">
        <div class="admin-orders-workspace__header">
            <div>
                <span>LISTA DE PEDIDOS</span>
                <h3>Operação consolidada</h3>
                <p>Use a busca para localizar um pedido ou filtre por etapa.</p>
            </div>
            <strong><?=count($orders)?> exibido<?=count($orders) === 1 ? '' : 's'?></strong>
        </div>

        <form class="admin-orders-filters" method="get" action="<?=e($filterBase)?>">
            <label class="admin-orders-search">
                <span>Buscar</span>
                <div>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></svg>
                    <input name="q" value="<?=e((string)($filters['q'] ?? ''))?>" placeholder="Pedido, cliente ou e-mail">
                </div>
            </label>
            <label>
                <span>Status</span>
                <select name="status">
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?=e($value)?>" <?=$activeStatus === $value ? 'selected' : ''?>><?=e($label)?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="admin-orders-filters__actions">
                <button class="button button--primary">Filtrar</button>
                <?php if ($activeStatus !== '' || ($filters['q'] ?? '') !== ''): ?>
                    <a href="<?=e($filterBase)?>">Limpar</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($orders): ?>
            <div class="admin-orders-table-wrap">
                <table class="admin-orders-table">
                    <thead>
                        <tr>
                            <th>Pedido</th>
                            <th>Cliente</th>
                            <th>Operação</th>
                            <th>Total</th>
                            <th>Situação</th>
                            <th class="admin-orders-table__actions">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $customerName = trim((string) $order['customer_name']);
                        $initials = '';
                        foreach (array_slice(preg_split('/\s+/', $customerName) ?: [], 0, 2) as $part) {
                            $initials .= mb_substr($part, 0, 1);
                        }
                        $initials = mb_strtoupper($initials ?: '?');
                        $paymentError = trim((string) ($order['payment_error'] ?? ''));
                        $cancelReason = trim((string) ($order['cancellation_reason'] ?? ''));
                        $issue = (string) $order['status'] === 'cancelled' ? $cancelReason : $paymentError;
                        $canCancel = (string) $order['status'] === 'pending_payment';
                        ?>
                        <tr>
                            <td data-label="Pedido">
                                <div class="admin-order-code">
                                    <i aria-hidden="true">#</i>
                                    <span>
                                        <strong><?=e((string)$order['code'])?></strong>
                                        <small><?=date('d/m/Y H:i', strtotime((string)$order['created_at']))?> · <?=e((string)$order['order_type'])?></small>
                                    </span>
                                </div>
                            </td>
                            <td data-label="Cliente">
                                <div class="admin-order-customer">
                                    <i aria-hidden="true"><?=e($initials)?></i>
                                    <span>
                                        <strong><?=e($customerName)?></strong>
                                        <small><?=e((string)$order['customer_email'])?></small>
                                    </span>
                                </div>
                            </td>
                            <td data-label="Operação">
                                <div class="admin-order-operation">
                                    <strong><?=(int)$order['store_count']?> loja<?=(int)$order['store_count'] === 1 ? '' : 's'?></strong>
                                    <small><?=(int)$order['shipment_count']?> remessa<?=(int)$order['shipment_count'] === 1 ? '' : 's'?></small>
                                </div>
                            </td>
                            <td data-label="Total">
                                <strong class="admin-order-total">R$ <?=number_format((float)$order['grand_total'], 2, ',', '.')?></strong>
                            </td>
                            <td data-label="Situação">
                                <div class="admin-order-status">
                                    <?php $status = $order['status']; require dirname(__DIR__, 2) . '/components/dashboard/status-badge.php'; ?>
                                    <?php if (!empty($order['payment_status'])): ?>
                                        <small>Pagamento: <?=e(str_replace('_', ' ', (string)$order['payment_status']))?></small>
                                    <?php endif; ?>
                                    <?php if ($issue !== ''): ?>
                                        <span class="admin-order-issue" title="<?=e($issue)?>">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v5m0 3h.01"/><path d="M10.2 4.8 3.7 16a2 2 0 0 0 1.7 3h13.2a2 2 0 0 0 1.7-3L13.8 4.8a2 2 0 0 0-3.6 0Z"/></svg>
                                            <?=e($issue)?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Ações" class="admin-order-actions-cell">
                                <div class="admin-order-actions">
                                    <a href="<?=e(url('/admin/pedidos/' . $order['code']))?>" class="admin-order-details">Detalhes <b>→</b></a>
                                    <?php if ($canCancel): ?>
                                        <button type="button" class="admin-order-cancel-button" data-order-cancel-open="<?=e((string)$order['code'])?>">
                                            Cancelar
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="admin-orders-empty">
                <i aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7h16v12H4zM8 7V5h8v2"/><path d="M8 12h8"/></svg></i>
                <h3>Nenhum pedido encontrado</h3>
                <p>Tente remover os filtros ou buscar por outro pedido, cliente ou e-mail.</p>
                <a class="button button--secondary" href="<?=e($filterBase)?>">Limpar filtros</a>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php foreach ($orders as $order): ?>
    <?php if ((string)$order['status'] !== 'pending_payment') continue; ?>
    <?php $paymentError = trim((string)($order['payment_error'] ?? '')); ?>
    <dialog class="admin-order-cancel-dialog" data-order-cancel-dialog="<?=e((string)$order['code'])?>">
        <form method="post" action="<?=e(url('/admin/pedidos/' . $order['code'] . '/cancelar'))?>">
            <?=csrf_field()?>
            <div class="admin-order-cancel-dialog__head">
                <div>
                    <span>CANCELAR PEDIDO</span>
                    <h3><?=e((string)$order['code'])?></h3>
                </div>
                <button type="button" data-order-cancel-close aria-label="Fechar">×</button>
            </div>
            <div class="admin-order-cancel-dialog__body">
                <p>O cancelamento libera reservas de estoque e cupom e registra a justificativa no histórico. Pagamentos já confirmados não serão cancelados por esta ação.</p>
                <?php if ($paymentError !== ''): ?>
                    <div class="admin-order-detected-error">
                        <strong>Falha detectada</strong>
                        <span><?=e($paymentError)?></span>
                    </div>
                <?php endif; ?>
                <label>
                    <span>Motivo</span>
                    <select name="reason" required>
                        <option value="" <?=$paymentError === '' ? 'selected' : ''?> disabled>Selecione o motivo</option>
                        <option value="test_error" <?=$paymentError !== '' ? 'selected' : ''?>>Teste / homologação com erro</option>
                        <option value="payment_error">Falha no pagamento</option>
                        <option value="duplicate">Pedido duplicado</option>
                        <option value="operational_error">Erro operacional</option>
                        <option value="customer_request">Solicitação do cliente</option>
                        <option value="other">Outro motivo</option>
                    </select>
                </label>
                <label>
                    <span>Detalhes da falha <small>(opcional, exceto em “Outro”)</small></span>
                    <textarea name="details" rows="4" maxlength="300" placeholder="Ex.: teste de integração retornou erro ao criar a cobrança..."><?=e(mb_substr($paymentError, 0, 300))?></textarea>
                </label>
            </div>
            <div class="admin-order-cancel-dialog__actions">
                <button type="button" class="button button--secondary" data-order-cancel-close>Voltar</button>
                <button class="button admin-order-danger-button">Confirmar cancelamento</button>
            </div>
        </form>
    </dialog>
<?php endforeach; ?>

<script defer src="<?=e(asset('js/admin-orders.js'))?>"></script>
