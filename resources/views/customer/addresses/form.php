<?php
$editing = !empty($address);
$cancel = $returnPath ?: '/minha-conta/enderecos';
?>
<div class="dashboard-heading">
    <div>
        <span class="eyebrow">ENDEREÇO</span>
        <h2><?= $editing ? 'Editar endereço' : 'Novo endereço' ?></h2>
        <p><?= $returnPath === '/checkout' ? 'Cadastre onde deseja receber e volte ao checkout.' : 'Mantenha seus locais de entrega organizados.' ?></p>
    </div>
</div>
<form class="panel resource-form address-form" method="post" action="<?= e($editing ? url('/minha-conta/enderecos/' . $address['id']) : url('/minha-conta/enderecos')) ?>" data-address-form>
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="_method" value="PUT"><?php endif; ?>
    <?php if ($returnPath): ?><input type="hidden" name="return" value="<?= e($returnPath) ?>"><?php endif; ?>
    <input type="hidden" name="city_ibge_code" value="<?= e($address['city_ibge_code'] ?? '') ?>" data-address-ibge>

    <div class="form-grid">
        <label>Identificação
            <input name="label" value="<?= e($address['label'] ?? '') ?>" placeholder="Casa, trabalho..." autocomplete="off">
        </label>
        <label>Destinatário
            <input name="recipient_name" required value="<?= e($address['recipient_name'] ?? $authUser['name'] ?? '') ?>" autocomplete="name">
        </label>
        <label class="address-form__cep">CEP
            <input name="postal_code" data-mask="cep" data-address-cep inputmode="numeric" autocomplete="postal-code" required value="<?= e($address['postal_code'] ?? '') ?>" placeholder="00000-000">
            <small class="cep-lookup-status" data-address-cep-status aria-live="polite">Digite o CEP para preencher rua, bairro, cidade e UF automaticamente.</small>
        </label>
        <label>Rua
            <input name="street" data-address-street required value="<?= e($address['street'] ?? '') ?>" autocomplete="address-line1">
        </label>
        <label>Número
            <input name="number" data-address-number required value="<?= e($address['number'] ?? '') ?>" autocomplete="address-line2">
        </label>
        <label>Complemento
            <input name="complement" value="<?= e($address['complement'] ?? '') ?>" autocomplete="address-line3">
        </label>
        <label>Bairro
            <input name="neighborhood" data-address-neighborhood required value="<?= e($address['neighborhood'] ?? '') ?>" autocomplete="address-level3">
        </label>
        <label>Cidade
            <input name="city" data-address-city required value="<?= e($address['city'] ?? '') ?>" autocomplete="address-level2">
        </label>
        <label>UF
            <input name="state" data-address-state required maxlength="2" value="<?= e($address['state'] ?? '') ?>" autocomplete="address-level1">
        </label>
        <label class="checkbox"><input type="checkbox" name="is_default" value="1" <?= !empty($address['is_default']) ? 'checked' : '' ?>>Endereço principal</label>
    </div>
    <div class="form-actions">
        <a class="button button--secondary" href="<?= e(url($cancel)) ?>">Cancelar</a>
        <button class="button button--primary">Salvar endereço</button>
    </div>
</form>
