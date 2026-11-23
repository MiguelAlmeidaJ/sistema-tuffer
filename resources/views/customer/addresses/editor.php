<?php
/** @var array<string,mixed>|null $address */
$address = $address ?? null;
$returnPath = $returnPath ?? '';
$editing = !empty($address);
$addressAction = $editing ? '/minha-conta/enderecos/' . (int)$address['id'] : '/minha-conta/enderecos';
?>
<form class="address-editor" method="post" action="<?=e(url($addressAction))?>" data-address-editor>
    <?=csrf_field()?>
    <?php if($editing):?><input type="hidden" name="_method" value="PUT"><?php endif;?>
    <?php if($returnPath):?><input type="hidden" name="return" value="<?=e($returnPath)?>"><?php endif;?>
    <input type="hidden" name="city_ibge_code" value="<?=e($address['city_ibge_code']??'')?>" data-address-ibge>
    <div class="address-editor__top">
        <div><h3><?= $editing?'Editar endereço':'Dados da entrega' ?></h3><p>Confira as informações para que sua encomenda chegue ao lugar certo.</p></div>
        <button type="button" class="address-editor__location" data-address-location>Usar minha localização</button>
    </div>
    <p class="address-editor__status" data-address-status role="status" aria-live="polite">A localização é opcional e só será solicitada com sua autorização.</p>
    <div class="address-editor__fields">
        <label class="address-editor__field"><span>Nome do endereço <small>opcional</small></span><input name="label" maxlength="80" value="<?=e($address['label']??'')?>" placeholder="Ex.: Casa, trabalho"></label>
        <label class="address-editor__field"><span>Nome de quem vai receber</span><input name="recipient_name" maxlength="150" required autocomplete="name" value="<?=e($address['recipient_name']??($authUser['name']??''))?>"></label>
        <label class="address-editor__field"><span>CEP</span><input name="postal_code" required data-address-cep inputmode="numeric" maxlength="9" autocomplete="postal-code" placeholder="00000-000" value="<?=e($address['postal_code']??'')?>"></label>
        <label class="address-editor__field address-editor__field--wide"><span>Rua ou avenida</span><input name="street" required maxlength="180" data-address-street autocomplete="address-line1" value="<?=e($address['street']??'')?>"></label>
        <label class="address-editor__field"><span>Número</span><input name="number" required maxlength="30" autocomplete="off" data-address-number placeholder="Ex.: 160 ou S/N" value="<?=e($address['number']??'')?>"></label>
        <label class="address-editor__field"><span>Complemento <small>opcional</small></span><input name="complement" maxlength="100" autocomplete="address-line2" placeholder="Apartamento, bloco..." value="<?=e($address['complement']??'')?>"></label>
        <label class="address-editor__field"><span>Bairro</span><input name="neighborhood" required maxlength="100" data-address-neighborhood value="<?=e($address['neighborhood']??'')?>"></label>
        <label class="address-editor__field"><span>Cidade</span><input name="city" required maxlength="100" data-address-city autocomplete="address-level2" value="<?=e($address['city']??'')?>"></label>
        <label class="address-editor__field address-editor__field--state"><span>UF</span><input name="state" required maxlength="2" minlength="2" data-address-state autocomplete="address-level1" placeholder="MG" value="<?=e($address['state']??'')?>"></label>
    </div>
    <label class="address-editor__default"><input type="checkbox" name="is_default" value="1" <?=!$editing||!empty($address['is_default'])?'checked':''?>><span>Definir como meu endereço principal</span></label>
    <p class="address-editor__notice">A localização pode ser aproximada. Confira principalmente CEP, rua e número antes de salvar.</p>
    <div class="address-editor__actions">
        <?php if(!empty($addressEditorModal)):?><button type="button" class="address-editor__cancel" data-address-modal-close>Agora não</button>
        <?php else:?><a class="address-editor__cancel" href="<?=e(url($returnPath?:'/minha-conta/enderecos'))?>">Cancelar</a><?php endif;?>
        <button type="submit" class="address-editor__submit"><?= $editing?'Salvar alterações':'Salvar endereço' ?></button>
    </div>
</form>
