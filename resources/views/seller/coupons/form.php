<?php $editing = !empty($coupon); ?>
<header class="commerce-hero commerce-hero--compact"><div><a class="commerce-back" href="<?= e(url('/vendedor/cupons')) ?>">← Voltar para cupons</a><span class="eyebrow"><?= e($currentStore['name']) ?> · PROMOÇÕES</span><h2><?= $editing ? 'Editar cupom' : 'Criar cupom' ?></h2><p>Configure o benefício, os limites e a validade da campanha.</p></div></header>

<form class="coupon-editor" method="post" action="<?= e($editing ? url('/vendedor/cupons/' . $coupon['id']) : url('/vendedor/cupons')) ?>" data-coupon-editor>
    <?= csrf_field() ?><?php if ($editing): ?><input type="hidden" name="_method" value="PUT"><?php endif; ?>
    <main>
        <section class="settings-card"><header><span>01</span><div><h3>Identificação da campanha</h3><p>Escolha um código curto, memorável e fácil de divulgar.</p></div></header><div class="settings-grid">
            <label class="settings-field">Código do cupom<div class="coupon-code-input"><span>％</span><input name="code" required minlength="3" maxlength="40" pattern="[A-Za-z0-9_-]+" value="<?= e($coupon['code'] ?? '') ?>" placeholder="BEMVINDO10" data-coupon-code></div><small>Letras, números, hífen e sublinhado. O código será convertido para maiúsculas.</small></label>
            <label class="settings-field">Nome interno<input name="name" required value="<?= e($coupon['name'] ?? '') ?>" placeholder="Boas-vindas: 10% OFF" data-coupon-name><small>Ajuda sua equipe a identificar a campanha.</small></label>
            <label class="settings-field settings-field--full">Descrição<textarea name="description" rows="3" maxlength="500" data-coupon-description><?= e($coupon['description'] ?? '') ?></textarea></label>
        </div></section>

        <section class="settings-card"><header><span>02</span><div><h3>Benefício oferecido</h3><p>Defina como o desconto será calculado no checkout.</p></div></header>
            <fieldset class="discount-type-options"><legend>Tipo de desconto</legend><label><input type="radio" name="discount_type" value="percentage" <?= ($coupon['discount_type'] ?? 'percentage') === 'percentage' ? 'checked' : '' ?>><span><b>%</b><strong>Percentual</strong><small>Ex.: 10% sobre o pedido</small></span></label><label><input type="radio" name="discount_type" value="fixed" <?= ($coupon['discount_type'] ?? '') === 'fixed' ? 'checked' : '' ?>><span><b>R$</b><strong>Valor fixo</strong><small>Ex.: R$ 20 de desconto</small></span></label></fieldset>
            <div class="settings-grid"><label class="settings-field">Valor do desconto<div class="discount-value-input"><span data-discount-prefix>%</span><input type="number" step="0.01" min="0.01" name="discount_value" required value="<?= e($coupon['discount_value'] ?? '') ?>" data-coupon-value></div></label><label class="settings-field">Compra mínima<div class="discount-value-input"><span>R$</span><input type="number" step="0.01" min="0" name="minimum_total" value="<?= e($coupon['minimum_total'] ?? 0) ?>" data-coupon-minimum></div><small>Use zero para não exigir valor mínimo.</small></label></div>
        </section>

        <section class="settings-card"><header><span>03</span><div><h3>Limites e validade</h3><p>Controle quando e quantas vezes o benefício pode ser usado.</p></div></header><div class="settings-grid">
            <label class="settings-field">Limite total de usos<input type="number" min="1" name="usage_limit" value="<?= e($coupon['usage_limit'] ?? '') ?>" placeholder="Sem limite"><small>Deixe vazio para permitir usos ilimitados.</small></label>
            <label class="settings-field">Status<select name="status"><option value="active">Ativo e disponível</option><option value="inactive" <?= ($coupon['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inativo</option></select></label>
            <label class="settings-field">Início da campanha<input type="datetime-local" name="starts_at" value="<?= !empty($coupon['starts_at']) ? date('Y-m-d\TH:i', strtotime((string) $coupon['starts_at'])) : '' ?>" data-coupon-start></label>
            <label class="settings-field">Encerramento<input type="datetime-local" name="expires_at" value="<?= !empty($coupon['expires_at']) ? date('Y-m-d\TH:i', strtotime((string) $coupon['expires_at'])) : '' ?>" data-coupon-end></label>
        </div></section>
        <footer class="settings-savebar"><a href="<?= e(url('/vendedor/cupons')) ?>">Cancelar</a><button class="button button--primary"><?= $editing ? 'Salvar alterações' : 'Criar cupom' ?></button></footer>
    </main>

    <aside class="coupon-preview-aside"><span class="eyebrow">PRÉVIA DO CUPOM</span><div class="coupon-preview"><div class="coupon-preview__brand">TUFFER <span><?= e($currentStore['name']) ?></span></div><small data-preview-kind>DESCONTO EXCLUSIVO</small><strong data-preview-value>10% OFF</strong><p data-preview-name><?= e($coupon['name'] ?? 'Nome da campanha') ?></p><code data-preview-code><?= e($coupon['code'] ?? 'SEUCODIGO') ?></code><span data-preview-rule>Válido para compras a partir de R$ 0,00</span><i>••••••••••••••••••••</i></div><div class="coupon-preview-notes"><h3>Como o cliente verá</h3><p>O código será aplicado no carrinho e validado automaticamente conforme valor mínimo, período e limite de usos.</p></div></aside>
</form>
