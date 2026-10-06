<?php
$tab = (string) ($_GET['aba'] ?? 'movimentacoes');
if (!in_array($tab, ['movimentacoes', 'carteira'], true)) {
    $tab = 'movimentacoes';
}
$money = static fn(int $cents): string => 'R$ ' . number_format($cents / 100, 2, ',', '.');
$statusLabels = [
    'pending_transfer' => 'Pendente',
    'processing' => 'Processando',
    'transferred' => 'Transferido',
    'failed' => 'Falhou',
    'canceled' => 'Cancelado',
];
?>
<div class="dashboard-heading">
    <div>
        <span class="eyebrow"><?= e($currentStore['name']) ?></span>
        <h2>Financeiro da loja</h2>
        <p>Faturamento confirmado, comissões, líquido e carteira de recebimentos.</p>
    </div>
</div>

<nav class="seller-orders-tabs" aria-label="Financeiro">
    <a class="<?= $tab === 'movimentacoes' ? 'is-active' : '' ?>" href="<?= e(url('/vendedor/financeiro')) ?>">
        <span>Movimentações</span>
    </a>
    <a class="<?= $tab === 'carteira' ? 'is-active' : '' ?>" href="<?= e(url('/vendedor/financeiro?aba=carteira')) ?>">
        <span>Carteira</span>
    </a>
</nav>

<?php if ($tab === 'movimentacoes'): ?>
    <?php
    $cards = [
        ['label' => 'Faturamento bruto', 'value' => 'R$ ' . number_format((float) $summary['gross'], 2, ',', '.')],
        ['label' => 'Comissões', 'value' => 'R$ ' . number_format((float) $summary['commissions'], 2, ',', '.')],
        ['label' => 'Líquido', 'value' => 'R$ ' . number_format((float) $summary['net'], 2, ',', '.')],
        ['label' => 'Vendas confirmadas', 'value' => (int) $summary['orders']],
    ];
    ?>
    <div class="stat-grid">
        <?php foreach ($cards as $stat) require dirname(__DIR__, 2) . '/components/dashboard/stat-card.php'; ?>
    </div>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h3>Movimentações</h3>
                <p>Cancelados e estornados continuam no histórico, mas não entram nos totais de vendas.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Subpedido</th>
                    <th>Produtos</th>
                    <th>Comissão</th>
                    <th>Líquido</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= e($order['code']) ?></td>
                        <td>R$ <?= number_format((float) $order['products_total'], 2, ',', '.') ?></td>
                        <td>R$ <?= number_format((float) $order['commission_total'], 2, ',', '.') ?></td>
                        <td>R$ <?= number_format((float) $order['seller_net_total'], 2, ',', '.') ?></td>
                        <td><?php $status = $order['status']; require dirname(__DIR__, 2) . '/components/dashboard/status-badge.php'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php else: ?>
    <?php if (!empty($officialStore)): ?>
        <section class="panel">
            <div class="panel-head"><h3>Carteira da loja oficial</h3></div>
            <p>Os recebimentos desta loja usam a conta global da Tuffer. Transferências são administradas pelo financeiro da plataforma.</p>
        </section>
    <?php else: ?>
        <?php if ($walletWarning): ?>
            <div class="settings-note"><span>!</span><p><?= e($walletWarning) ?></p></div>
        <?php endif; ?>

        <?php if (!$wallet || empty($wallet['configured'])): ?>
            <section class="panel">
                <div class="panel-head"><h3>Carteira indisponível</h3></div>
                <p>Finalize e aprove a configuração de recebimentos na Pagar.me para consultar saldo e solicitar transferências.</p>
                <p><a class="button button--primary" href="<?= e(url('/vendedor/configuracoes/recebimentos')) ?>">Configurar recebimentos</a></p>
            </section>
        <?php else: ?>
            <div class="stat-grid">
                <?php
                $walletCards = [
                    ['label' => 'Disponível para transferir', 'value' => $money((int) $wallet['available_cents'])],
                    ['label' => 'A receber', 'value' => $money((int) $wallet['waiting_cents'])],
                    ['label' => 'Já transferido', 'value' => $money((int) $wallet['transferred_cents'])],
                ];
                foreach ($walletCards as $stat) require dirname(__DIR__, 2) . '/components/dashboard/stat-card.php';
                ?>
            </div>

            <div class="settings-layout">
                <div class="settings-form">
                    <section class="settings-card">
                        <header><span>01</span><div><h3>Solicitar transferência</h3><p>O valor sai do saldo disponível do recebedor desta empresa na Pagar.me.</p></div></header>
                        <div class="settings-grid">
                            <div class="settings-field">
                                <strong>Conta cadastrada</strong>
                                <div class="locked-input">
                                    <span><?= e(trim(($wallet['account']['bank_code'] ?? '') . ' · Ag. ' . ($wallet['account']['bank_branch_masked'] ?? '') . ' · Conta ' . ($wallet['account']['bank_account_masked'] ?? ''))) ?></span>
                                    <b>Destino atual</b>
                                </div>
                            </div>
                            <div class="settings-field">
                                <strong>Recebedor</strong>
                                <div class="locked-input">
                                    <span><?= e($wallet['account']['recipient_id'] ?? '—') ?></span>
                                    <b>Pagar.me</b>
                                </div>
                            </div>
                        </div>

                        <?php if ($canWithdraw): ?>
                            <form method="post" action="<?= e(url('/vendedor/financeiro/carteira/transferir')) ?>">
                                <?= csrf_field() ?>
                                <div class="settings-grid">
                                    <label class="settings-field">Valor da transferência
                                        <input name="amount" inputmode="decimal" required placeholder="0,00"
                                               value="<?= number_format((int) $wallet['available_cents'] / 100, 2, ',', '.') ?>">
                                    </label>
                                </div>
                                <div class="form-actions">
                                    <button class="button button--primary" <?= (int) $wallet['available_cents'] < 100 ? 'disabled' : '' ?>>Solicitar transferência</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="settings-note"><span>!</span><p>Somente o proprietário vendedor pode solicitar transferências.</p></div>
                        <?php endif; ?>
                    </section>

                    <section class="settings-card">
                        <header><span>02</span><div><h3>Histórico de transferências</h3><p>Últimas movimentações informadas pela Pagar.me para este recebedor.</p></div></header>
                        <?php if (!empty($wallet['transfers'])): ?>
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>ID</th><th>Valor</th><th>Status</th><th>Data</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($wallet['transfers'] as $transfer): ?>
                                        <?php
                                        $transferAmount = (int) ($transfer['amount'] ?? 0);
                                        $transferStatus = (string) ($transfer['status'] ?? '');
                                        $date = (string) ($transfer['date_created'] ?? $transfer['created_at'] ?? $transfer['funding_date'] ?? '');
                                        ?>
                                        <tr>
                                            <td><?= e((string) ($transfer['id'] ?? '—')) ?></td>
                                            <td><?= e($money($transferAmount)) ?></td>
                                            <td><?= e($statusLabels[$transferStatus] ?? ($transferStatus ?: '—')) ?></td>
                                            <td><?= $date !== '' ? e(date('d/m/Y H:i', strtotime($date))) : '—' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p>Nenhuma transferência encontrada para este recebedor.</p>
                        <?php endif; ?>
                    </section>
                </div>

                <aside class="settings-aside">
                    <section class="seller-status-card">
                        <span class="status-pill status-pill--active">Conta cadastrada</span>
                        <h3>Destino da transferência</h3>
                        <p>Por segurança, o saque direto usa a conta bancária vinculada e validada no recebedor Pagar.me.</p>
                        <dl>
                            <div><dt>Banco</dt><dd><?= e($wallet['account']['bank_code'] ?? '—') ?></dd></div>
                            <div><dt>Agência</dt><dd><?= e($wallet['account']['bank_branch_masked'] ?? '—') ?></dd></div>
                            <div><dt>Conta</dt><dd><?= e($wallet['account']['bank_account_masked'] ?? '—') ?></dd></div>
                        </dl>
                        <p><a class="button button--secondary" href="<?= e(url('/vendedor/configuracoes/recebimentos')) ?>">Alterar conta de recebimento</a></p>
                    </section>
                    <section class="account-checklist">
                        <h3>Sobre outra conta</h3>
                        <p>A transferência para uma conta diferente não deve aceitar dados bancários livres no saque. Primeiro a conta precisa ser cadastrada e validada no recebedor da Pagar.me; depois ela pode ser usada como destino.</p>
                    </section>
                </aside>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
