<div class="dashboard-heading">
    <div>
        <span class="eyebrow">HOMOLOGAÇÃO</span>
        <h2>Diagnóstico Pagar.me</h2>
        <p>Visão sanitizada da integração Pix com split. Nenhuma chave, documento ou dado bancário é exibido.</p>
    </div>
</div>

<?php
$cards = [
    ['label' => 'Modo do checkout', 'value' => $platform['checkout_mode'] === 'orders_pix_limited' ? 'Orders Pix limitado' : 'Payment Link'],
    ['label' => 'Vendedores elegíveis', 'value' => (string) $summary['enabled_sellers']],
    ['label' => 'Permitidos na homologação', 'value' => (string) $summary['allowed_sellers']],
    ['label' => 'Pix pendentes', 'value' => (string) $summary['pending_pix']],
    ['label' => 'Webhooks falhos (7 dias)', 'value' => (string) $summary['failed_webhooks']],
    ['label' => 'Divergências abertas', 'value' => (string) $summary['open_divergences']],
];
?>
<div class="stat-grid"><?php foreach ($cards as $stat) require dirname(__DIR__, 2) . '/components/dashboard/stat-card.php'; ?></div>

<section class="panel">
    <div class="panel-head"><div><small>RECEBEDOR DA PLATAFORMA</small><h3><?= e($platform['recipient_id']) ?></h3></div></div>
    <div class="table-wrap"><table><tbody>
        <tr><th>Ambiente da chave</th><td><?= e($platform['key_environment']) ?></td></tr>
        <tr><th>Status do recipient</th><td><?= e($platform['recipient_status'] ?? 'não validado') ?></td></tr>
        <tr><th>Status do KYC</th><td><?= ($platform['kyc_status'] ?? null) === 'legacy_not_required' ? 'Dispensado para recebedor existente' : e($platform['kyc_status'] ?? 'não validado') ?></td></tr>
        <tr><th>Recipient no mesmo ambiente</th><td><?= $platform['environment_match'] ? 'Sim' : 'Não confirmado' ?></td></tr>
        <tr><th>Orders Pix</th><td><?= $platform['orders_pix_enabled'] ? 'Habilitado por flag' : 'Desabilitado' ?></td></tr>
        <tr><th>Split</th><td><?= $platform['split_enabled'] ? 'Habilitado por flag' : 'Desabilitado' ?></td></tr>
    </tbody></table></div>
    <?php if (!empty($platform['error'])): ?><p class="alert alert--error"><?= e($platform['error']) ?></p><?php endif; ?>
</section>

<section class="panel">
    <div class="panel-head"><div><small>SINCRONIZADOR</small><h3>Última reconciliação</h3></div></div>
    <?php if ($lastRun): ?>
        <p><strong><?= e($lastRun['status']) ?></strong> · início <?= date('d/m/Y H:i:s', strtotime((string) $lastRun['started_at'])) ?>
            <?php if ($lastRun['finished_at']): ?> · fim <?= date('d/m/Y H:i:s', strtotime((string) $lastRun['finished_at'])) ?><?php endif; ?></p>
        <p>Verificados: <?= (int) $lastRun['checked_count'] ?> · recuperados: <?= (int) $lastRun['recovered_count'] ?>
            · atualizados: <?= (int) $lastRun['updated_count'] ?> · divergências: <?= (int) $lastRun['divergence_count'] ?></p>
    <?php else: ?><p>Nenhuma reconciliação executada.</p><?php endif; ?>
</section>

<div class="review-layout">
    <section class="panel">
        <div class="panel-head"><h3>Falhas recentes de webhook</h3></div>
        <div class="table-wrap"><table><thead><tr><th>Evento</th><th>Tipo</th><th>Erro seguro</th><th>Recebido</th></tr></thead><tbody>
        <?php foreach ($failedWebhooks as $webhook): ?><tr>
            <td><?= e($webhook['provider_event_id'] ?: 'sem ID') ?></td>
            <td><?= e($webhook['event_type']) ?></td>
            <td><?= e($webhook['error_message'] ?: 'Falha não detalhada') ?></td>
            <td><?= date('d/m/Y H:i', strtotime((string) $webhook['created_at'])) ?></td>
        </tr><?php endforeach; ?>
        <?php if ($failedWebhooks === []): ?><tr><td colspan="4">Nenhuma falha recente.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <section class="panel">
        <div class="panel-head"><h3>Divergências para revisão</h3></div>
        <div class="table-wrap"><table><thead><tr><th>Pedido</th><th>Tipo</th><th>Local / remoto</th><th>Detectada</th></tr></thead><tbody>
        <?php foreach ($divergences as $divergence): ?><tr>
            <td><?= e($divergence['order_code'] ?: '—') ?></td>
            <td><?= e($divergence['divergence_type']) ?></td>
            <td><?= e(($divergence['local_status'] ?: '—') . ' / ' . ($divergence['remote_status'] ?: '—')) ?></td>
            <td><?= date('d/m/Y H:i', strtotime((string) $divergence['detected_at'])) ?></td>
        </tr><?php endforeach; ?>
        <?php if ($divergences === []): ?><tr><td colspan="4">Nenhuma divergência aberta.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
