<?php
$assistant = $purchaseAssistant ?? null;
if (!is_array($assistant)) return;
$actionUrl = (string) ($assistant['action_url'] ?? '/checkout');
$actionHref = str_starts_with($actionUrl, '#') ? $actionUrl : url($actionUrl);
?>
<section class="purchase-assistant purchase-assistant--<?= e((string) ($assistant['tone'] ?? 'info')) ?>"
         data-purchase-assistant
         data-purchase-assistant-context="<?= e((string) ($assistant['context'] ?? 'cart')) ?>"
         data-login-url="<?= e(url('/entrar?redirect=/checkout')) ?>"
         data-profile-url="<?= e(url('/minha-conta/perfil?return=/checkout')) ?>"
         data-address-url="<?= e(url('/minha-conta/enderecos/novo?return=/checkout')) ?>"
         data-cart-url="<?= e(url('/carrinho')) ?>"
         aria-live="polite">
    <div class="purchase-assistant__mark" aria-hidden="true">T</div>
    <div class="purchase-assistant__body">
        <span class="purchase-assistant__eyebrow">ASSISTENTE DE COMPRA</span>
        <h2 data-assistant-title><?= e((string) ($assistant['title'] ?? 'Vamos concluir sua compra')) ?></h2>
        <p data-assistant-message><?= e((string) ($assistant['message'] ?? 'Eu mostro o próximo passo para você.')) ?></p>
        <div class="purchase-assistant__progress" aria-label="Progresso da compra">
            <span data-assistant-progress style="width: <?= (int) ($assistant['progress'] ?? 0) ?>%"></span>
        </div>
        <ol class="purchase-assistant__steps">
            <?php foreach (($assistant['steps'] ?? []) as $index => $step): ?>
                <li class="is-<?= e((string) ($step['status'] ?? 'pending')) ?>" data-assistant-step="<?= e((string) ($step['key'] ?? '')) ?>">
                    <i aria-hidden="true"><?= ($step['status'] ?? '') === 'done' ? '✓' : (int) $index + 1 ?></i>
                    <span><?= e((string) ($step['label'] ?? 'Etapa')) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
    <a class="purchase-assistant__action" data-assistant-action href="<?= e($actionHref) ?>"><?= e((string) ($assistant['action_label'] ?? 'Continuar')) ?> <span aria-hidden="true">→</span></a>
</section>
