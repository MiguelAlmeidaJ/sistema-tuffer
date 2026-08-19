<header class="commerce-hero commerce-hero--seller">
    <div><span class="eyebrow">CONTA EMPRESARIAL</span><h2>Configuração do vendedor</h2><p>Gerencie os dados do responsável e as informações legais da empresa.</p></div>
    <div class="seller-identity"><span><?= e(mb_substr($seller['trade_name'], 0, 1)) ?></span><div><strong><?= e($seller['trade_name']) ?></strong><small>Vendedor desde <?= date('m/Y', strtotime((string) $seller['created_at'])) ?></small></div></div>
</header>

<nav class="settings-navigation" aria-label="Configurações">
    <a href="<?= e(url('/vendedor/configuracoes/loja')) ?>"><span>▣</span><strong>Loja</strong><small>Identidade e integrações</small></a>
    <a class="is-active" href="<?= e(url('/vendedor/configuracoes/vendedor')) ?>"><span>◎</span><strong>Vendedor</strong><small>Dados pessoais e empresa</small></a>
    <a href="<?= e(url('/vendedor/configuracoes/recebimentos')) ?>"><span>◇</span><strong>Recebimentos</strong><small>Recebedor e validação</small></a>
</nav>

<div class="settings-layout">
    <form class="settings-form" method="post" action="<?= e(url('/vendedor/configuracoes/vendedor')) ?>">
        <?= csrf_field() ?><input type="hidden" name="_method" value="PUT">
        <section class="settings-card"><header><span>01</span><div><h3>Responsável pela conta</h3><p>Dados usados para comunicação operacional e segurança.</p></div></header><div class="settings-grid">
            <label class="settings-field">Nome completo<input name="user_name" required minlength="3" value="<?= e($seller['user_name']) ?>" autocomplete="name"></label>
            <label class="settings-field">E-mail de acesso<div class="locked-input"><span><?= e($seller['email']) ?></span><b>Verificado</b></div><small>Para alterar o e-mail, fale com o suporte.</small></label>
            <label class="settings-field">Telefone<input name="phone" required value="<?= e($seller['phone'] ?? '') ?>" autocomplete="tel" placeholder="(11) 99999-9999"><small>Usado como contato do remetente na etiqueta.</small></label>
        </div></section>
        <section class="settings-card"><header><span>02</span><div><h3>Dados da empresa</h3><p>Informações fiscais vinculadas a todas as suas lojas.</p></div></header><div class="settings-grid">
            <label class="settings-field">CPF ou CNPJ<div class="locked-input"><span><?= e($seller['document']) ?></span><b>Validado</b></div><small>Documento protegido após a aprovação do cadastro.</small></label>
            <label class="settings-field">Inscrição estadual<input name="state_registration" required value="<?= e($seller['state_registration'] ?? '') ?>" placeholder="ISENTO, quando aplicável"><small>Obrigatória para a postagem comercial.</small></label>
            <label class="settings-field">Razão social<input name="legal_name" required value="<?= e($seller['legal_name']) ?>"></label>
            <label class="settings-field">Nome comercial<input name="trade_name" required value="<?= e($seller['trade_name']) ?>"></label>
        </div></section>
        <div class="settings-note settings-note--secure"><span>✓</span><p>Seus dados são usados apenas para operação do marketplace, pagamentos e obrigações fiscais.</p></div>
        <footer class="settings-savebar"><span><i></i> Dados protegidos e auditáveis</span><button class="button button--primary">Salvar dados do vendedor</button></footer>
    </form>
    <aside class="settings-aside">
        <section class="seller-status-card"><span class="status-pill status-pill--<?= e($seller['status']) ?>"><?= $seller['status'] === 'active' ? 'Cadastro aprovado' : e(ucfirst(str_replace('_', ' ', $seller['status']))) ?></span><h3><?= e($seller['trade_name']) ?></h3><p><?= e($seller['legal_name']) ?></p><dl><div><dt>Lojas vinculadas</dt><dd><?= (int) $storeCount ?></dd></div><div><dt>Comissão da plataforma</dt><dd><?= number_format((float) $seller['commission_rate'], 2, ',', '.') ?>%</dd></div><div><dt>Aprovação</dt><dd><?= $seller['approved_at'] ? date('d/m/Y', strtotime((string) $seller['approved_at'])) : 'Em análise' ?></dd></div></dl></section>
        <section class="account-checklist"><h3>Segurança da conta</h3><p><span>✓</span> E-mail confirmado</p><p><span>✓</span> Documento validado</p><p><span>✓</span> Cadastro empresarial protegido</p></section>
    </aside>
</div>
