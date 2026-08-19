<?php
$money = static fn(?int $cents): string => $cents === null
    ? 'Não calculado'
    : 'R$ ' . number_format($cents / 100, 2, ',', '.');
$remaining = max(0, (int) $settlement['transferable_amount_cents'] - (int) $settlement['transferred_amount_cents']);
?>
<div class="dashboard-heading">
    <div>
        <a class="back-link" href="<?= e(url('/admin/financeiro/fechamentos')) ?>">← Fechamentos</a>
        <span class="eyebrow"><?= e($settlement['financial_owner']) ?></span>
        <h2>Fechamento #<?= (int) $settlement['id'] ?></h2>
        <p><?= date('d/m/Y', strtotime($settlement['period_start'])) ?> a <?= date('d/m/Y', strtotime($settlement['period_end'])) ?> · <?= e($settlement['status']) ?></p>
    </div>
</div>
<?php
$cards = [
    ['label' => 'Faturamento', $money((int) $settlement['gross_revenue_cents'])],
    ['label' => 'Receita líquida', $money((int) $settlement['net_revenue_cents'])],
    ['label' => 'Custo', $money($settlement['product_cost_cents'] === null ? null : (int) $settlement['product_cost_cents'])],
    ['label' => 'Lucro estimado', $money($settlement['estimated_profit_cents'] === null ? null : (int) $settlement['estimated_profit_cents'])],
    ['label' => 'Reserva', $money((int) $settlement['reserve_amount_cents'])],
    ['label' => 'Transferível restante', $money($remaining)],
];
?>
<div class="stat-grid"><?php foreach ($cards as $stat) require dirname(__DIR__, 3) . '/components/dashboard/stat-card.php'; ?></div>

<?php if ($issues): ?>
<section class="panel">
    <h3>Divergências abertas</h3>
    <?php foreach ($issues as $issue): ?>
        <div class="settings-note">
            <p><strong><?= e($issue['severity']) ?> · <?= e($issue['issue_type']) ?></strong><br>Esperado: <?= e($issue['expected_value'] ?? '—') ?> · Atual: <?= e($issue['actual_value'] ?? '—') ?></p>
            <form method="post" action="<?= e(url('/admin/financeiro/fechamentos/' . $settlement['id'] . '/divergencias/' . $issue['id'] . '/resolver')) ?>">
                <?= csrf_field() ?><label>Tratamento realizado<input name="notes" required maxlength="1000"></label>
                <button class="button button--secondary">Marcar resolvida</button>
            </form>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="review-layout">
    <section class="panel">
        <h3>Revisão e aprovação</h3>
        <?php if ($settlement['status'] === 'awaiting_review' && !$settlement['reviewed_at']): ?>
            <form method="post" action="<?= e(url('/admin/financeiro/fechamentos/' . $settlement['id'] . '/revisar')) ?>">
                <?= csrf_field() ?><label>Observações<textarea name="notes" rows="4"></textarea></label>
                <button class="button button--primary">Registrar revisão</button>
            </form>
        <?php elseif ($settlement['status'] === 'awaiting_review'): ?>
            <form method="post" action="<?= e(url('/admin/financeiro/fechamentos/' . $settlement['id'] . '/aprovar')) ?>" data-confirm="Aprovar este fechamento?">
                <?= csrf_field() ?><button class="button button--primary">Aprovar fechamento</button>
            </form>
        <?php else: ?>
            <p>Status atual: <strong><?= e($settlement['status']) ?></strong></p>
        <?php endif; ?>
    </section>
    <?php if ($settlement['financial_owner'] === 'official_store'): ?><aside>
        <section class="panel">
            <h3>Registrar transferência já realizada</h3>
            <p>Esta ação não movimenta dinheiro e permanece bloqueada por configuração até habilitação explícita.</p>
            <form method="post" enctype="multipart/form-data" action="<?= e(url('/admin/financeiro/fechamentos/' . $settlement['id'] . '/transferencias')) ?>">
                <?= csrf_field() ?>
                <label>Valor (R$)<input type="number" name="amount" min="0.01" step="0.01" required></label>
                <label>Nome do destino<input name="destination_name" required></label>
                <label>Conta mascarada<input name="destination_masked" placeholder="****1234" required></label>
                <label>Referência bancária<input name="bank_reference" required></label>
                <label>Comprovante privado<input type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png"></label>
                <label>Observações<textarea name="notes"></textarea></label>
                <button class="button button--secondary">Registrar transferência manual</button>
            </form>
        </section>
    </aside><?php endif; ?>
</div>

<section class="panel">
    <h3>Lançamentos incluídos</h3>
    <div class="table-wrap"><table><thead><tr><th>Data</th><th>Centro</th><th>Tipo</th><th>Pedido</th><th>Valor</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($entries as $entry): ?><tr><td><?= date('d/m/Y', strtotime($entry['occurred_at'])) ?></td><td><?= e($entry['financial_owner']) ?></td><td><?= e($entry['entry_type']) ?></td><td><?= e($entry['order_code'] ?? '—') ?></td><td><?= $money((int) $entry['amount_cents']) ?></td><td><?= e($entry['status']) ?></td></tr><?php endforeach; ?></tbody></table></div>
</section>

<?php if (in_array($settlement['status'], ['awaiting_review', 'approved'], true) && (int) $settlement['transferred_amount_cents'] === 0): ?>
<section class="panel">
    <h3>Cancelar fechamento</h3>
    <p>O cancelamento preserva todos os lançamentos e o histórico.</p>
    <form method="post" action="<?= e(url('/admin/financeiro/fechamentos/' . $settlement['id'] . '/cancelar')) ?>" data-confirm="Cancelar este fechamento?">
        <?= csrf_field() ?><label>Motivo<textarea name="notes" required></textarea></label>
        <button class="button button--secondary">Cancelar fechamento</button>
    </form>
</section>
<?php endif; ?>

<section class="panel">
    <h3>Transferências registradas</h3>
    <?php if (!$transfers): ?><p>Nenhuma transferência registrada.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Data</th><th>Valor</th><th>Destino</th><th>Referência</th><th>Comprovante</th></tr></thead><tbody>
    <?php foreach ($transfers as $transfer): ?><tr>
        <td><?= date('d/m/Y H:i', strtotime($transfer['transferred_at'])) ?></td><td><?= $money((int) $transfer['amount_cents']) ?></td>
        <td><?= e($transfer['destination_account_name']) ?> · <?= e($transfer['destination_account_masked']) ?></td><td><?= e($transfer['bank_reference']) ?></td>
        <td><?php if ($transfer['proof_file']): ?><a href="<?= e(url('/admin/financeiro/fechamentos/' . $settlement['id'] . '/transferencias/' . $transfer['id'] . '/comprovante')) ?>">Baixar</a><?php else: ?>—<?php endif; ?></td>
    </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>

<section class="panel">
    <h3>Histórico</h3>
    <?php foreach ($history as $item): ?><p><strong><?= e($item['action']) ?></strong> · <?= date('d/m/Y H:i', strtotime($item['created_at'])) ?><br><?= e($item['notes'] ?? '') ?></p><?php endforeach; ?>
</section>
