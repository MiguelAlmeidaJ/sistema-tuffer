<header class="commerce-hero commerce-hero--coupons">
    <div><span class="eyebrow"><?= e($currentStore['name']) ?> · PROMOÇÕES</span><h2>Cupons de desconto</h2><p>Crie campanhas, acompanhe o uso e mantenha suas ofertas sob controle.</p></div>
    <a class="button button--primary" href="<?= e(url('/vendedor/cupons/novo')) ?>">＋ Criar novo cupom</a>
</header>

<section class="coupon-stats">
    <article><span>Cupons cadastrados</span><strong><?= (int) $stats['total'] ?></strong><small>Total nesta loja</small></article>
    <article><span>Campanhas ativas</span><strong><?= (int) $stats['active'] ?></strong><small>Disponíveis no checkout</small></article>
    <article><span>Cupons utilizados</span><strong><?= (int) $stats['uses'] ?></strong><small>Aplicações acumuladas</small></article>
    <article><span>Expiram em 7 dias</span><strong><?= (int) $stats['expiring'] ?></strong><small>Precisam de atenção</small></article>
</section>

<section class="coupon-list-panel">
    <header><div><h3>Todos os cupons</h3><p><?= count($coupons) ?> <?= count($coupons) === 1 ? 'campanha cadastrada' : 'campanhas cadastradas' ?></p></div><label class="coupon-search"><span>⌕</span><input type="search" placeholder="Buscar cupom..." data-coupon-search></label></header>
    <div class="coupon-list" data-coupon-list>
        <?php foreach ($coupons as $coupon):
            $expired = $coupon['expires_at'] && strtotime((string) $coupon['expires_at']) < time();
            $usagePercent = $coupon['usage_limit'] ? min(100, ((int) $coupon['usage_count'] / (int) $coupon['usage_limit']) * 100) : 0;
        ?><article class="coupon-row" data-coupon-name="<?= e(mb_strtolower($coupon['code'] . ' ' . $coupon['name'])) ?>">
            <div class="coupon-ticket coupon-ticket--<?= e($coupon['discount_type']) ?>"><small><?= $coupon['discount_type'] === 'percentage' ? 'DESCONTO' : 'CRÉDITO' ?></small><strong><?= $coupon['discount_type'] === 'percentage' ? number_format((float) $coupon['discount_value'], 0) . '%' : 'R$ ' . number_format((float) $coupon['discount_value'], 2, ',', '.') ?></strong></div>
            <div class="coupon-row__identity"><div><code><?= e($coupon['code']) ?></code><?php if ($expired): ?><span class="status-pill status-pill--expired">Expirado</span><?php else: $status = $coupon['status']; require dirname(__DIR__, 2) . '/components/dashboard/status-badge.php'; endif; ?></div><h3><?= e($coupon['name']) ?></h3><p><?= e($coupon['description'] ?: 'Sem descrição adicional.') ?></p></div>
            <div class="coupon-row__usage"><div><span>Uso</span><strong><?= (int) $coupon['usage_count'] ?> / <?= $coupon['usage_limit'] ?: '∞' ?></strong></div><div class="coupon-usage-bar"><i style="width:<?= $coupon['usage_limit'] ? $usagePercent : 0 ?>%"></i></div><small>Compra mínima: <?= (float) $coupon['minimum_total'] > 0 ? 'R$ ' . number_format((float) $coupon['minimum_total'], 2, ',', '.') : 'não exigida' ?></small></div>
            <div class="coupon-row__validity"><span>Validade</span><strong><?= $coupon['expires_at'] ? date('d/m/Y', strtotime((string) $coupon['expires_at'])) : 'Sem limite' ?></strong><small><?= $coupon['starts_at'] ? 'Desde ' . date('d/m/Y', strtotime((string) $coupon['starts_at'])) : 'Disponível imediatamente' ?></small></div>
            <div class="coupon-row__actions"><a href="<?= e(url('/vendedor/cupons/' . $coupon['id'] . '/editar')) ?>">Editar</a><form method="post" action="<?= e(url('/vendedor/cupons/' . $coupon['id'])) ?>" onsubmit="return confirm('Excluir este cupom?')"><?= csrf_field() ?><input type="hidden" name="_method" value="DELETE"><button aria-label="Excluir cupom">×</button></form></div>
        </article><?php endforeach; ?>
        <?php if (!$coupons): ?><div class="coupon-empty"><span>％</span><h3>Crie sua primeira campanha</h3><p>Ofereça um benefício claro e acompanhe os usos em tempo real.</p><a class="button button--primary" href="<?= e(url('/vendedor/cupons/novo')) ?>">Criar cupom</a></div><?php endif; ?>
    </div>
</section>
