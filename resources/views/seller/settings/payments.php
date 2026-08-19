<?php
$status = (string) ($account['onboarding_status'] ?? 'not_started');
$statusLabels = [
    'platform_pending' => 'Conta global pendente',
    'not_started' => 'Cadastro não iniciado',
    'registration_pending' => 'Aguardando validação',
    'kyc_pending' => 'Validação necessária',
    'analyzing' => 'Em análise',
    'active' => 'Aprovado',
    'rejected' => 'Reprovado',
    'blocked' => 'Bloqueado',
];
$statusLabel = $statusLabels[$status] ?? 'Aguardando validação';
$canGenerateKyc = ($account['recipient_status'] ?? null) === 'affiliation'
    && ($account['kyc_status'] ?? null) === 'partially_denied';
$field = static fn(string $name, mixed $default = ''): string => e(old($name, $default));
?>
<header class="commerce-hero commerce-hero--seller">
    <div><span class="eyebrow">RECEBIMENTOS</span><h2>Configuração para recebimento</h2><p>Cadastre o recebedor da sua empresa e acompanhe a validação exigida pela Pagar.me.</p></div>
    <div class="seller-identity"><span><?= e(mb_substr($seller['trade_name'], 0, 1)) ?></span><div><strong><?= e($seller['trade_name']) ?></strong><small>Ambiente <?= $environment === 'test' ? 'de teste' : 'de produção' ?></small></div></div>
</header>

<nav class="settings-navigation" aria-label="Configurações">
    <a href="<?= e(url('/vendedor/configuracoes/loja')) ?>"><span>▣</span><strong>Loja</strong><small>Identidade e frete</small></a>
    <a href="<?= e(url('/vendedor/configuracoes/vendedor')) ?>"><span>◎</span><strong>Vendedor</strong><small>Dados pessoais e empresa</small></a>
    <a class="is-active" href="<?= e(url('/vendedor/configuracoes/recebimentos')) ?>"><span>◇</span><strong>Recebimentos</strong><small>Recebedor e validação</small></a>
</nav>

<?php if (!empty($officialStore)): ?>
<div class="settings-note settings-note--secure"><span>✓</span><p>Recebimento centralizado: a loja oficial usa exclusivamente a conta global da Tuffer e não pode criar um segundo recebedor ou KYC.</p></div>
<?php endif; ?>
<?php if ($syncWarning): ?><div class="settings-note"><span>!</span><p><?= e($syncWarning) ?></p></div><?php endif; ?>
<?php if (!$paymentConfigured): ?><div class="settings-note"><span>!</span><p>A integração Pagar.me precisa estar configurada e ativada pelo administrador antes de criar o recebedor.</p></div><?php endif; ?>

<div class="settings-layout">
    <div class="settings-form">
        <section class="settings-card">
            <header><span>01</span><div><h3>Situação do recebimento</h3><p>A liberação depende do recebedor ativo e do KYC aprovado.</p></div></header>
            <div class="settings-grid">
                <div class="settings-field"><strong>Status</strong><div class="locked-input"><span><?= e($statusLabel) ?></span><b><?= $status === 'active' ? 'Liberado' : 'Bloqueado' ?></b></div></div>
                <div class="settings-field"><strong>Código do recebedor</strong><div class="locked-input"><span><?= e($account['recipient_id'] ?? 'Ainda não criado') ?></span><b><?= e($account['recipient_status'] ?? '—') ?></b></div></div>
                <div class="settings-field"><strong>Validação de identidade</strong><div class="locked-input"><span><?= e($account['kyc_status'] ?? 'Aguardando disponibilidade') ?></span><b><?= e($account['kyc_status_reason'] ?? '—') ?></b></div></div>
                <div class="settings-field"><strong>Conta bancária</strong><div class="locked-input"><span><?= $account ? e(trim(($account['bank_code'] ?? '') . ' · Ag. ' . ($account['bank_branch_masked'] ?? '') . ' · Conta ' . ($account['bank_account_masked'] ?? ''))) : 'Não cadastrada' ?></span><b>Dados mascarados</b></div></div>
            </div>
            <?php if ($account): ?>
                <div class="form-actions">
                    <form method="post" action="<?= e(url('/vendedor/configuracoes/recebimentos/status')) ?>"><?= csrf_field() ?><button class="button button--secondary">Atualizar status</button></form>
                    <?php if ($canGenerateKyc): ?><form method="post" action="<?= e(url('/vendedor/configuracoes/recebimentos/kyc')) ?>"><?= csrf_field() ?><button class="button button--primary">Concluir validação na Pagar.me</button></form><?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!$account && empty($officialStore)): ?>
        <form class="settings-form" method="post" action="<?= e(url('/vendedor/configuracoes/recebimentos')) ?>" autocomplete="off">
            <?= csrf_field() ?>
            <section class="settings-card"><header><span>02</span><div><h3>Dados da empresa</h3><p>O CNPJ, a razão social e o nome fantasia vêm do cadastro aprovado.</p></div></header><div class="settings-grid">
                <label class="settings-field">CNPJ<div class="locked-input"><span><?= e($seller['document']) ?></span><b>Cadastro Tuffer</b></div></label>
                <label class="settings-field">Razão social<div class="locked-input"><span><?= e($seller['legal_name']) ?></span><b>Cadastro Tuffer</b></div></label>
                <label class="settings-field">Nome fantasia<div class="locked-input"><span><?= e($seller['trade_name']) ?></span><b>Cadastro Tuffer</b></div></label>
                <label class="settings-field">E-mail financeiro<input type="email" name="financial_email" required value="<?= $field('financial_email', $seller['user_email']) ?>"></label>
                <label class="settings-field">Site da empresa<input type="url" name="site_url" required value="<?= $field('site_url', absolute_url('/loja/' . ($currentStore['slug'] ?? ''))) ?>" placeholder="https://empresa.com.br"></label>
                <label class="settings-field">Faturamento anual estimado<input type="number" name="annual_revenue" required min="1" step="1" value="<?= $field('annual_revenue') ?>"></label>
                <label class="settings-field">Tipo societário<input name="corporation_type" required value="<?= $field('corporation_type', 'LTDA') ?>" placeholder="LTDA, SA..."></label>
                <label class="settings-field">Data de abertura<input type="date" name="founding_date" required value="<?= $field('founding_date') ?>"></label>
                <label class="settings-field">Telefone empresarial<input name="company_phone" required value="<?= $field('company_phone', $seller['user_phone']) ?>" placeholder="(11) 99999-9999"></label>
            </div></section>

            <section class="settings-card"><header><span>03</span><div><h3>Endereço da empresa</h3><p>Use o endereço cadastral do CNPJ.</p></div></header><div class="settings-grid">
                <label class="settings-field">CEP<input name="company_zip_code" required value="<?= $field('company_zip_code') ?>"></label>
                <label class="settings-field">Rua<input name="company_street" required value="<?= $field('company_street') ?>"></label>
                <label class="settings-field">Número<input name="company_number" required value="<?= $field('company_number') ?>"></label>
                <label class="settings-field">Complemento<input name="company_complement" required value="<?= $field('company_complement', 'Sem complemento') ?>"></label>
                <label class="settings-field">Bairro<input name="company_neighborhood" required value="<?= $field('company_neighborhood') ?>"></label>
                <label class="settings-field">Cidade<input name="company_city" required value="<?= $field('company_city') ?>"></label>
                <label class="settings-field">UF<input name="company_state" maxlength="2" required value="<?= $field('company_state') ?>"></label>
                <label class="settings-field">Ponto de referência<input name="company_reference_point" required value="<?= $field('company_reference_point') ?>"></label>
            </div></section>

            <section class="settings-card"><header><span>04</span><div><h3>Representante legal</h3><p>Deve ser um único sócio registrado e qualificado no QSA da empresa.</p></div></header><div class="settings-grid">
                <label class="settings-field">Nome completo<input name="partner_name" required value="<?= $field('partner_name', $seller['user_name']) ?>"></label>
                <label class="settings-field">CPF<input name="partner_document" required inputmode="numeric" value=""></label>
                <label class="settings-field">E-mail<input type="email" name="partner_email" required value="<?= $field('partner_email', $seller['user_email']) ?>"></label>
                <label class="settings-field">Telefone<input name="partner_phone" required value="<?= $field('partner_phone', $seller['user_phone']) ?>"></label>
                <label class="settings-field">Data de nascimento<input type="date" name="partner_birthdate" required value="<?= $field('partner_birthdate') ?>"></label>
                <label class="settings-field">Nome da mãe<input name="partner_mother_name" required value="<?= $field('partner_mother_name') ?>"></label>
                <label class="settings-field">Renda mensal estimada<input type="number" name="partner_monthly_income" min="1" step="1" required value="<?= $field('partner_monthly_income') ?>"></label>
                <label class="settings-field">Ocupação<input name="partner_occupation" required value="<?= $field('partner_occupation') ?>"></label>
                <label class="settings-field">CEP<input name="partner_zip_code" required value="<?= $field('partner_zip_code') ?>"></label>
                <label class="settings-field">Rua<input name="partner_street" required value="<?= $field('partner_street') ?>"></label>
                <label class="settings-field">Número<input name="partner_number" required value="<?= $field('partner_number') ?>"></label>
                <label class="settings-field">Complemento<input name="partner_complement" required value="<?= $field('partner_complement', 'Sem complemento') ?>"></label>
                <label class="settings-field">Bairro<input name="partner_neighborhood" required value="<?= $field('partner_neighborhood') ?>"></label>
                <label class="settings-field">Cidade<input name="partner_city" required value="<?= $field('partner_city') ?>"></label>
                <label class="settings-field">UF<input name="partner_state" maxlength="2" required value="<?= $field('partner_state') ?>"></label>
                <label class="settings-field">Ponto de referência<input name="partner_reference_point" required value="<?= $field('partner_reference_point') ?>"></label>
            </div></section>

            <section class="settings-card"><header><span>05</span><div><h3>Conta bancária</h3><p>Os dados são enviados diretamente à Pagar.me e não ficam armazenados integralmente na Tuffer.</p></div></header><div class="settings-grid">
                <label class="settings-field">Banco<input name="bank_code" required inputmode="numeric" placeholder="341"></label>
                <label class="settings-field">Agência<input name="branch_number" required inputmode="numeric"></label>
                <label class="settings-field">Dígito da agência<input name="branch_check_digit" maxlength="2"></label>
                <label class="settings-field">Conta<input name="account_number" required inputmode="numeric"></label>
                <label class="settings-field">Dígito da conta<input name="account_check_digit" required maxlength="2"></label>
                <label class="settings-field">Tipo de conta<select name="bank_account_type"><option value="checking">Conta corrente</option><option value="savings">Conta poupança</option></select></label>
                <label class="settings-field">Nome do titular<input name="bank_holder_name" required></label>
                <label class="settings-field">CPF ou CNPJ do titular<input name="bank_holder_document" required inputmode="numeric"></label>
            </div></section>
            <div class="settings-note settings-note--secure"><span>✓</span><p>Ao continuar, você declara que os dados são verdadeiros e que o representante informado está autorizado a concluir a validação.</p></div>
            <footer class="settings-savebar"><span><i></i> Envio seguro para a Pagar.me</span><button class="button button--primary" <?= !$paymentConfigured ? 'disabled' : '' ?>>Criar configuração para recebimento</button></footer>
        </form>
        <?php endif; ?>
    </div>

    <aside class="settings-aside">
        <section class="seller-status-card"><span class="status-pill status-pill--<?= $status === 'active' ? 'active' : 'pending' ?>"><?= e($statusLabel) ?></span><h3><?= e($seller['trade_name']) ?></h3><p>Somente recebedores ativos e com KYC aprovado podem vender.</p><dl><div><dt>Recebedor</dt><dd><?= e($account['recipient_status'] ?? '—') ?></dd></div><div><dt>KYC</dt><dd><?= e($account['kyc_status'] ?? '—') ?></dd></div><div><dt>Última consulta</dt><dd><?= !empty($account['last_synced_at']) ? date('d/m/Y H:i', strtotime((string) $account['last_synced_at'])) : 'Ainda não consultado' ?></dd></div></dl></section>
        <section class="account-checklist"><h3>Liberação da loja</h3><p><span><?= $seller['status'] === 'active' ? '✓' : '○' ?></span> Cadastro aprovado pela Tuffer</p><p><span><?= !empty($account['recipient_id']) ? '✓' : '○' ?></span> Recebedor criado</p><p><span><?= in_array(($account['kyc_status'] ?? null), ['approved','legacy_not_required'], true) ? '✓' : '○' ?></span> KYC aprovado ou dispensado</p><p><span><?= !empty($seller['payment_enabled']) ? '✓' : '○' ?></span> Venda habilitada</p></section>
    </aside>
</div>
