<?php
$passwordModalAction = ($dashboardArea ?? '') === 'admin' ? '/admin/minha-conta/senha' : '/vendedor/minha-conta/senha';
?>
<dialog class="password-dialog" id="password-dialog" aria-labelledby="password-dialog-title" aria-describedby="password-dialog-description">
  <div class="password-dialog__heading">
    <div class="password-dialog__heading-copy">
      <h2 id="password-dialog-title">Alterar minha senha</h2>
      <p id="password-dialog-description" class="password-dialog__intro">Defina uma senha segura para sua conta. Depois de salvar, você precisará entrar novamente.</p>
    </div>
    <button type="button" class="password-dialog__close" data-password-modal-close aria-label="Fechar janela">&times;</button>
  </div>
  <?php if (isset($_GET['alterar-senha']) && $flashError): ?><div class="password-dialog__error" role="alert"><?=e($flashError)?></div><?php endif; ?>
  <form method="post" action="<?=e(url($passwordModalAction))?>" class="password-dialog__form" data-password-form>
    <?=csrf_field()?>
    <label class="password-dialog__field"><span>Senha atual</span><span class="password-dialog__input"><input name="current_password" type="password" required autocomplete="current-password" placeholder="Digite sua senha atual"><button type="button" data-password-visibility aria-label="Mostrar senha atual" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></span></label>
    <label class="password-dialog__field"><span>Nova senha</span><span class="password-dialog__input"><input name="password" data-password-new type="password" required autocomplete="new-password" placeholder="Crie uma senha forte" aria-describedby="password-strength-description"><button type="button" data-password-visibility aria-label="Mostrar nova senha" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></span></label>
    <div class="password-strength" aria-live="polite">
      <div class="password-strength__heading"><span>Força da senha</span><strong data-password-strength-label>Digite uma senha</strong></div>
      <div class="password-strength__meter" role="progressbar" aria-label="Força estimada da senha" aria-valuemin="0" aria-valuemax="4" aria-valuenow="0" data-password-strength-meter><i></i><i></i><i></i><i></i></div>
      <p id="password-strength-description" data-password-strength-hint>Use uma senha longa, única e difícil de adivinhar.</p>
    </div>
    <label class="password-dialog__field"><span>Confirmar nova senha</span><span class="password-dialog__input"><input name="password_confirmation" data-password-confirm type="password" required autocomplete="new-password" placeholder="Repita a nova senha"><button type="button" data-password-visibility aria-label="Mostrar confirmação de senha" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></span><small data-password-match aria-live="polite"></small></label>
    <div class="password-dialog__actions"><button type="button" class="password-dialog__cancel" data-password-modal-close>Cancelar</button><button type="submit" class="password-dialog__submit">Salvar nova senha</button></div>
  </form>
</dialog>
