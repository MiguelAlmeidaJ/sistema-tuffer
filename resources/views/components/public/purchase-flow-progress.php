<?php
/** @var string $purchaseFlowStep cart|checkout|confirmation */
$purchaseFlowStep = $purchaseFlowStep ?? 'cart';
$flowSteps = [
    ['key'=>'cart','label'=>'Carrinho','href'=>url('/carrinho')],
    ['key'=>'checkout','label'=>'Entrega e pagamento','href'=>url('/checkout')],
    ['key'=>'confirmation','label'=>'Confirmação','href'=>null],
];
$currentStep = array_search($purchaseFlowStep, array_column($flowSteps, 'key'), true);
$currentStep = $currentStep === false ? 0 : $currentStep;
?>
<nav class="checkout-progress purchase-flow-progress" aria-label="Etapas da compra"><div class="container">
<?php foreach ($flowSteps as $index=>$step): ?>
    <?php if ($index>0): ?><em aria-hidden="true"></em><?php endif; ?>
    <span class="<?= $index<$currentStep?'is-complete':($index===$currentStep?'is-current':'') ?>" <?= $index===$currentStep?'aria-current="step"':'' ?>>
        <i aria-hidden="true"><?= $index<$currentStep?'✓':($index+1) ?></i>
        <?php if ($index<$currentStep && $step['href']): ?><a href="<?=e($step['href'])?>"><?=e($step['label'])?></a><?php else: ?><b><?=e($step['label'])?></b><?php endif; ?>
    </span>
<?php endforeach; ?>
</div></nav>
