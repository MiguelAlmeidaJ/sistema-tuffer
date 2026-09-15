<?php
$firstName = trim((string) ($authUser['name'] ?? 'Cliente'));
$firstName = explode(' ', $firstName)[0] ?: 'Cliente';
$money = static fn(float $value): string => 'R$ ' . number_format($value, 2, ',', '.');
$latestStatus = is_array($latestOrder ?? null) ? (string) ($latestOrder['status'] ?? '') : '';
$statusMessages = [
    'pending' => 'Estamos preparando os próximos passos do seu pedido.',
    'pending_payment' => 'Seu pedido aguarda a confirmação do pagamento.',
    'paid' => 'Pagamento confirmado. O pedido seguirá para preparação.',
    'processing' => 'Seu pedido está em preparação.',
    'completed' => 'Pedido concluído. Esperamos que você tenha gostado da compra.',
    'cancelled' => 'Este pedido foi cancelado.',
];
?>
<style>
.customer-home{display:grid;gap:20px}.customer-home .dashboard-heading{margin-bottom:0;align-items:flex-end}.customer-home__actions{display:flex;gap:10px;flex-wrap:wrap}.customer-home__actions .button{text-decoration:none}.customer-home__stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.customer-home__stat{display:flex;flex-direction:column;gap:12px;min-height:126px;padding:20px;border:1px solid #e4e0d8;border-radius:18px;background:#fff;text-decoration:none;color:#111;transition:transform .15s ease,border-color .15s ease,box-shadow .15s ease}.customer-home__stat:hover{transform:translateY(-2px);border-color:#cbc5bb;box-shadow:0 10px 26px rgba(17,17,17,.05)}.customer-home__stat-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.customer-home__stat-label{font-size:13px;color:#6d6862}.customer-home__stat-arrow{font-size:18px;color:#a39d94}.customer-home__stat strong{font-size:30px;line-height:1}.customer-home__stat small{color:#837d75;font-size:12px}.customer-home__grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,.75fr);gap:16px}.customer-home__panel{margin:0}.customer-home__panel .panel-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:18px}.customer-home__panel .panel-head h3{margin:4px 0 0}.customer-home__panel .panel-head a{font-weight:750;text-decoration:none;color:#111}.customer-home__latest{border:1px solid #e7e3dc;border-radius:16px;padding:20px;background:#fcfbf9;display:grid;gap:18px}.customer-home__latest-top{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}.customer-home__latest-code{display:grid;gap:5px}.customer-home__latest-code small{font-size:11px;letter-spacing:.08em;text-transform:uppercase;font-weight:800;color:#777}.customer-home__latest-code strong{font-size:19px}.customer-home__latest-total{text-align:right}.customer-home__latest-total small{display:block;color:#777;font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:800;margin-bottom:4px}.customer-home__latest-total strong{font-size:20px}.customer-home__latest-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding-top:4px}.customer-home__meta{display:grid;gap:4px}.customer-home__meta small{color:#777;font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:800}.customer-home__latest-message{margin:0;color:#666;font-size:13px;line-height:1.5}.customer-home__latest-footer{display:flex;justify-content:space-between;gap:12px;align-items:center;border-top:1px solid #e8e4dd;padding-top:16px}.customer-home__latest-footer .button{text-decoration:none}.customer-home__empty{display:grid;place-items:center;text-align:center;min-height:270px;padding:26px}.customer-home__empty-icon{width:52px;height:52px;border-radius:16px;background:#fff0f1;color:#d90a18;display:grid;place-items:center;font-size:22px;font-weight:800;margin-bottom:12px}.customer-home__empty h4{margin:0 0 6px;font-size:20px}.customer-home__empty p{margin:0 0 18px;color:#777}.customer-home__quick{display:grid;gap:9px}.customer-home__quick-link{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:15px 16px;border:1px solid #e7e3dc;border-radius:14px;background:#fff;text-decoration:none;color:#111}.customer-home__quick-link:hover{background:#faf9f7}.customer-home__quick-link strong{font-size:14px}.customer-home__quick-link small{display:block;color:#777;margin-top:3px;font-size:12px}.customer-home__quick-link span{font-size:18px;color:#9a948c}.customer-home__tip{margin-top:12px;padding:14px 15px;border-radius:14px;background:#f7f5f1;color:#69635c;font-size:12px;line-height:1.5}.customer-home__tip strong{color:#222}@media(max-width:1100px){.customer-home__stats{grid-template-columns:repeat(2,minmax(0,1fr))}.customer-home__grid{grid-template-columns:1fr}}@media(max-width:640px){.customer-home .dashboard-heading{align-items:flex-start}.customer-home__actions{width:100%}.customer-home__actions .button{flex:1}.customer-home__stats{grid-template-columns:1fr 1fr}.customer-home__stat{min-height:112px;padding:16px}.customer-home__stat strong{font-size:25px}.customer-home__latest-top,.customer-home__latest-footer{align-items:flex-start;flex-direction:column}.customer-home__latest-total{text-align:left}.customer-home__latest-meta{grid-template-columns:1fr}.customer-home__latest-footer .button{width:100%;text-align:center}}@media(max-width:430px){.customer-home__stats{grid-template-columns:1fr}}
</style>

<div class="customer-home">
    <div class="dashboard-heading">
        <div>
            <span class="eyebrow">OLÁ, <?= e($firstName) ?></span>
            <h2>Seu espaço na Tuffer.</h2>
            <p>Acompanhe pedidos, endereços e preferências sem precisar procurar em vários lugares.</p>
        </div>
        <div class="customer-home__actions">
            <a class="button button--secondary" href="<?= e(url('/minha-conta/pedidos')) ?>">Ver meus pedidos</a>
            <a class="button button--primary" href="<?= e(url('/produtos')) ?>">Continuar comprando</a>
        </div>
    </div>

    <section class="customer-home__stats" aria-label="Resumo da conta">
        <a class="customer-home__stat" href="<?= e(url('/minha-conta/pedidos')) ?>">
            <div class="customer-home__stat-top"><span class="customer-home__stat-label">Todos os pedidos</span><span class="customer-home__stat-arrow">↗</span></div>
            <strong><?= (int) ($stats['total'] ?? 0) ?></strong>
            <small>Seu histórico completo de compras</small>
        </a>
        <a class="customer-home__stat" href="<?= e(url('/minha-conta/pedidos?situacao=andamento')) ?>">
            <div class="customer-home__stat-top"><span class="customer-home__stat-label">Em andamento</span><span class="customer-home__stat-arrow">↗</span></div>
            <strong><?= (int) ($stats['ongoing'] ?? 0) ?></strong>
            <small>Pedidos que ainda precisam de alguma etapa</small>
        </a>
        <a class="customer-home__stat" href="<?= e(url('/minha-conta/pedidos?situacao=concluidos')) ?>">
            <div class="customer-home__stat-top"><span class="customer-home__stat-label">Concluídos</span><span class="customer-home__stat-arrow">↗</span></div>
            <strong><?= (int) ($stats['delivered'] ?? 0) ?></strong>
            <small>Compras finalizadas</small>
        </a>
        <a class="customer-home__stat" href="<?= e(url('/minha-conta/favoritos')) ?>">
            <div class="customer-home__stat-top"><span class="customer-home__stat-label">Favoritos</span><span class="customer-home__stat-arrow">↗</span></div>
            <strong><?= (int) ($stats['favorites'] ?? 0) ?></strong>
            <small>Produtos guardados para depois</small>
        </a>
    </section>

    <div class="customer-home__grid">
        <section class="panel customer-home__panel">
            <div class="panel-head">
                <div><span class="eyebrow">ATIVIDADE</span><h3>Último pedido</h3></div>
                <a href="<?= e(url('/minha-conta/pedidos')) ?>">Ver todos →</a>
            </div>

            <?php if (is_array($latestOrder ?? null)): ?>
                <article class="customer-home__latest">
                    <div class="customer-home__latest-top">
                        <div class="customer-home__latest-code">
                            <small>Pedido</small>
                            <strong><?= e((string) $latestOrder['code']) ?></strong>
                        </div>
                        <div class="customer-home__latest-total">
                            <small>Total</small>
                            <strong><?= $money((float) $latestOrder['grand_total']) ?></strong>
                        </div>
                    </div>
                    <div class="customer-home__latest-meta">
                        <div class="customer-home__meta">
                            <small>Data</small>
                            <strong><?= date('d/m/Y', strtotime((string) $latestOrder['created_at'])) ?></strong>
                        </div>
                        <div class="customer-home__meta">
                            <small>Status</small>
                            <div><?php $status = $latestStatus; require dirname(__DIR__) . '/components/dashboard/status-badge.php'; ?></div>
                        </div>
                    </div>
                    <p class="customer-home__latest-message"><?= e($statusMessages[$latestStatus] ?? 'Abra o pedido para conferir todos os detalhes e atualizações.') ?></p>
                    <div class="customer-home__latest-footer">
                        <span><?= (($latestOrder['order_type'] ?? '') === 'wholesale') ? 'Pedido de atacado' : 'Compra no marketplace' ?></span>
                        <a class="button button--primary" href="<?= e(url('/minha-conta/pedidos/' . $latestOrder['code'])) ?>">Acompanhar pedido</a>
                    </div>
                </article>
            <?php else: ?>
                <div class="customer-home__empty">
                    <div>
                        <div class="customer-home__empty-icon">↗</div>
                        <h4>Nenhum pedido por enquanto</h4>
                        <p>Quando você fizer sua primeira compra, o acompanhamento aparecerá aqui.</p>
                        <a class="button button--primary" href="<?= e(url('/produtos')) ?>">Explorar produtos</a>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <aside class="panel customer-home__panel">
            <div class="panel-head"><div><span class="eyebrow">ATALHOS</span><h3>Acesso rápido</h3></div></div>
            <div class="customer-home__quick">
                <a class="customer-home__quick-link" href="<?= e(url('/minha-conta/pedidos')) ?>"><div><strong>Meus pedidos</strong><small>Acompanhe compras e pagamentos</small></div><span>→</span></a>
                <a class="customer-home__quick-link" href="<?= e(url('/minha-conta/enderecos')) ?>"><div><strong>Endereços</strong><small>Atualize seus locais de entrega</small></div><span>→</span></a>
                <a class="customer-home__quick-link" href="<?= e(url('/minha-conta/favoritos')) ?>"><div><strong>Favoritos</strong><small>Volte aos produtos que você salvou</small></div><span>→</span></a>
                <a class="customer-home__quick-link" href="<?= e(url('/minha-conta/mensagens')) ?>"><div><strong>Mensagens</strong><small>Veja suas conversas na Tuffer</small></div><span>→</span></a>
            </div>
            <?php if ((int) ($stats['cancelled'] ?? 0) > 0): ?>
                <div class="customer-home__tip"><strong>Histórico preservado.</strong> Pedidos cancelados continuam disponíveis em “Meus pedidos” para consulta.</div>
            <?php endif; ?>
        </aside>
    </div>
</div>
