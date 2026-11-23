<?php
$addressModalAutoOpen = !empty($addressModalAutoOpen);
$address = null;
$returnPath = isset($_GET['return']) && $_GET['return']==='/checkout' ? '/checkout' : '';
$addressEditorModal = true;
?>
<dialog class="customer-address-dialog" data-address-modal <?= $addressModalAutoOpen?'data-address-auto-open':'' ?> aria-labelledby="customer-address-modal-title" aria-describedby="customer-address-modal-description">
    <div class="customer-address-dialog__heading">
        <div><span class="eyebrow">ENDEREÇO DE ENTREGA</span><h2 id="customer-address-modal-title">Onde você quer receber?</h2><p id="customer-address-modal-description">Cadastre seu endereço para agilizar a entrega das suas compras.</p></div>
        <button class="customer-address-dialog__close" type="button" data-address-modal-close aria-label="Fechar">×</button>
    </div>
    <?php if(!empty($flashError)):?><p class="customer-address-dialog__error" role="alert"><?=e($flashError)?></p><?php endif;?>
    <?php require dirname(__DIR__,2).'/customer/addresses/editor.php';?>
</dialog>
