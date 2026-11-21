<?php
/** Exibido apenas aos visitantes sem sessão, no layout público. */
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$returnPath = \App\Services\Auth\SocialAuthService::safeReturnPath($requestUri);
if ($returnPath === '') $returnPath = '/';
$googleReady = trim((string) ($_ENV['GOOGLE_CLIENT_ID'] ?? '')) !== ''
    && trim((string) ($_ENV['GOOGLE_CLIENT_SECRET'] ?? '')) !== '';
$googleUrl = '/auth/google?redirect=' . rawurlencode($returnPath);
?>
<dialog class="account-gate" data-account-gate aria-labelledby="account-gate-title" aria-describedby="account-gate-description" data-delay="5000">
  <div class="account-gate__content">
    <div class="account-gate__brand"><img src="<?= e(upload_asset($platformSettings['logo_path'] ?? 'platform/logos/tuffer-logo.svg')) ?>" alt="<?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>"></div>
    <div class="account-gate__eyebrow">SUA EXPERIÊNCIA TUFFER</div>
    <h2 id="account-gate-title">Bem-vindo à Tuffer</h2>
    <p id="account-gate-description">Entre ou crie sua conta para continuar explorando ofertas, salvar favoritos e acompanhar pedidos.</p>
    <?php if ($googleReady): ?>
      <a class="account-gate__google" href="<?= e(url($googleUrl)) ?>">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="#4285F4" d="M21.35 12.24c0-.72-.06-1.23-.2-1.77H12v3.43h5.36a4.6 4.6 0 0 1-2 3.03v2.5h3.24c1.9-1.75 2.75-4.33 2.75-7.19Z"/><path fill="#34A853" d="M12 21.75c2.63 0 4.83-.86 6.44-2.32l-3.23-2.5c-.88.59-2.02.94-3.21.94-2.47 0-4.56-1.67-5.31-3.92H3.35v2.57A9.75 9.75 0 0 0 12 21.75Z"/><path fill="#FBBC05" d="M6.69 13.95A5.8 5.8 0 0 1 6.4 12c0-.68.11-1.35.29-1.95V7.48H3.35A9.76 9.76 0 0 0 2.25 12c0 1.58.38 3.08 1.1 4.52l3.34-2.57Z"/><path fill="#EA4335" d="M12 6.13c1.43 0 2.72.49 3.74 1.46l2.88-2.88A9.27 9.27 0 0 0 12 2.25a9.75 9.75 0 0 0-8.65 5.23l3.34 2.57c.75-2.25 2.84-3.92 5.31-3.92Z"/></svg>
        Entrar com Google
      </a>
    <?php endif; ?>
    <div class="account-gate__divider"><span>ou entre com e-mail</span></div>
    <form method="post" action="<?=e(url('/entrar'))?>" class="account-gate__form">
      <?=csrf_field()?>
      <input type="hidden" name="redirect" value="<?=e($returnPath)?>">
      <label for="account-gate-email">E-mail</label>
      <input id="account-gate-email" type="email" name="email" placeholder="seu@email.com" autocomplete="email" required>
      <label for="account-gate-password">Senha</label>
      <input id="account-gate-password" type="password" name="password" placeholder="Sua senha" autocomplete="current-password" required>
      <div class="account-gate__help"><a href="<?=e(url('/esqueci-minha-senha'))?>">Esqueci minha senha</a></div>
      <button type="submit" class="account-gate__primary">Entrar na minha conta</button>
    </form>
    <div class="account-gate__register">
      <span>Ainda não tem conta?</span>
      <a href="<?=e(url('/cadastro?redirect='.rawurlencode($returnPath)))?>">Criar minha conta <span aria-hidden="true">→</span></a>
    </div>
    <small class="account-gate__privacy">Ao continuar, seus dados serão tratados conforme nossa <a href="<?=e(url('/politica-de-privacidade'))?>">Política de Privacidade</a>.</small>
  </div>
</dialog>
