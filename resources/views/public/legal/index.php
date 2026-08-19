<div class="policy-hub">
    <section class="policy-hub__hero container">
        <nav class="commerce-breadcrumb" aria-label="Navegação estrutural"><a href="<?= e(url('/')) ?>">Início</a><span>›</span><strong>Central de Políticas</strong></nav>
        <div class="policy-hub__hero-grid">
            <div><span class="eyebrow">TRANSPARÊNCIA E CONFIANÇA</span><h1>Central de Políticas</h1><p>Encontre regras claras para comprar, vender e utilizar a <?= e($platformSettings['platform_name'] ?? 'Tuffer') ?> com segurança.</p></div>
            <aside><strong><?= count($policies) ?></strong><span>documentos organizados por público</span><small>Última revisão geral em 21 de julho de 2026</small></aside>
        </div>
    </section>

    <section class="policy-hub__quick container" aria-label="Políticas essenciais">
        <div><span>COMECE POR AQUI</span><strong>Documentos essenciais</strong></div>
        <nav><?php foreach ($policies as $slug => $policy): if (empty($policy['essential'])) continue; ?><a href="<?= e(url('/politicas/' . $slug)) ?>"><?= e($policy['short_title']) ?></a><?php endforeach; ?></nav>
    </section>

    <div class="policy-hub__groups container">
        <?php foreach ($policyGroups as $groupKey => $group): ?>
            <section class="policy-group" id="<?= e($groupKey) ?>">
                <header><span><?= e(str_pad((string) (array_search($groupKey, array_keys($policyGroups), true) + 1), 2, '0', STR_PAD_LEFT)) ?></span><div><h2><?= e($group['label']) ?></h2><p><?= e($group['description']) ?></p></div></header>
                <div class="policy-grid">
                    <?php foreach ($policies as $slug => $policy): if ($policy['group'] !== $groupKey) continue; ?>
                        <a class="policy-card" href="<?= e(url('/politicas/' . $slug)) ?>">
                            <div><span><?= e($policy['audience']) ?></span><?php if (!empty($policy['essential'])): ?><b>ESSENCIAL</b><?php endif; ?></div>
                            <h3><?= e($policy['title']) ?></h3>
                            <p><?= e($policy['summary']) ?></p>
                            <small>Consultar política <i>→</i></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <section class="policy-hub__contact container"><div><span>PRECISA DE AJUDA?</span><h2>Não encontrou o que procurava?</h2><p>Fale com o atendimento para esclarecer regras sobre sua conta, pedido ou loja.</p></div><a class="button button--primary" href="mailto:<?= e($platformSettings['support_email'] ?? 'contato@tuffer.com.br') ?>">Entrar em contato</a></section>
</div>
