<?php
$redirectPath = (string) ($redirectPath ?? '');
$registerPath = '/cadastro' . ($redirectPath !== '' ? '?redirect=' . rawurlencode($redirectPath) : '');
?>
<section class="auth-card auth-card--login">
    <div class="auth-login__panel">
        <div class="auth-login__panel-inner">
            <a class="auth-login__logo" href="<?= e(url('/')) ?>" aria-label="<?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>, página inicial"><img src="<?= e(upload_asset($platformSettings['logo_path'] ?? 'platform/logos/tuffer-logo.svg')) ?>" alt="<?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>"></a>
            <div class="auth-card__header">
                <span class="auth-eyebrow">BEM-VINDO</span>
                <h1>Entre na sua conta.</h1>
                <p>Use seu e-mail e senha para continuar<?= $redirectPath !== '' ? ' de onde parou' : '' ?>.</p>
            </div>

<?php if (trim((string) ($_ENV['GOOGLE_CLIENT_ID'] ?? '')) !== '' && trim((string) ($_ENV['GOOGLE_CLIENT_SECRET'] ?? '')) !== ''): ?>
            <a class="account-gate__google" style="display:flex;margin:16px 0;min-height:50px;align-items:center;justify-content:center;gap:10px;border:1px solid #dadce0;border-radius:12px;text-decoration:none;color:#242424;font-weight:750" href="<?=e(url('/auth/google?redirect='.rawurlencode($redirectPath !== '' ? $redirectPath : '/minha-conta')))?>">
              <svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true"><path fill="#4285F4" d="M21.35 12.24c0-.72-.06-1.23-.2-1.77H12v3.43h5.36a4.6 4.6 0 0 1-2 3.03v2.5h3.24c1.9-1.75 2.75-4.33 2.75-7.19Z"/><path fill="#34A853" d="M12 21.75c2.63 0 4.83-.86 6.44-2.32l-3.23-2.5c-.88.59-2.02.94-3.21.94-2.47 0-4.56-1.67-5.31-3.92H3.35v2.57A9.75 9.75 0 0 0 12 21.75Z"/><path fill="#FBBC05" d="M6.69 13.95A5.8 5.8 0 0 1 6.4 12c0-.68.11-1.35.29-1.95V7.48H3.35A9.76 9.76 0 0 0 2.25 12c0 1.58.38 3.08 1.1 4.52l3.34-2.57Z"/><path fill="#EA4335" d="M12 6.13c1.43 0 2.72.49 3.74 1.46l2.88-2.88A9.27 9.27 0 0 0 12 2.25a9.75 9.75 0 0 0-8.65 5.23l3.34 2.57c.75-2.25 2.84-3.92 5.31-3.92Z"/></svg>
              Continuar com Google
            </a>
            <?php endif; ?>
            <form action="<?= e(url('/entrar')) ?>" method="post" class="auth-form" data-auth-login-form>
                <?= csrf_field() ?>
                <?php if ($redirectPath !== ''): ?><input type="hidden" name="redirect" value="<?= e($redirectPath) ?>"><?php endif; ?>
                <div class="form-group">
                    <label for="email">E-mail</label>
                    <div class="auth-input">
                        <svg class="auth-input__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6.5h18v11H3zM3.5 7l8.5 6 8.5-6"/></svg>
                        <input id="email" type="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" required placeholder="voce@exemplo.com" aria-describedby="<?= error('email') ? 'email-error' : '' ?>">
                    </div>
                    <?php if ($message = error('email')): ?><small class="form-error" id="email-error"><?= e($message) ?></small><?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="password">Senha</label>
                    <div class="auth-input password-field">
                        <svg class="auth-input__icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                        <input id="password" type="password" name="password" autocomplete="current-password" required placeholder="Sua senha">
                        <button type="button" class="password-toggle password-toggle--icon" data-toggle-password="password" data-password-icon aria-label="Mostrar senha" aria-pressed="false">
                            <svg class="password-toggle__show" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-5 9.5-5 9.5 5 9.5 5-3.5 5-9.5 5-9.5-5-9.5-5Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                            <svg class="password-toggle__hide" viewBox="0 0 24 24" aria-hidden="true"><path d="m4 4 16 16M10.6 7.1c.5-.1.9-.1 1.4-.1 6 0 9.5 5 9.5 5a16 16 0 0 1-2.1 2.4M6.2 8.2A17 17 0 0 0 2.5 12s3.5 5 9.5 5c1 0 1.9-.1 2.7-.4"/></svg>
                        </button>
                    </div>
                </div>

                <div class="auth-form__options">
                    <label class="auth-checkbox"><input type="checkbox" name="remember" value="1"><span>Manter conectado</span></label>
                    <a class="auth-forgot-link" href="<?= e(url('/esqueci-minha-senha')) ?>">Esqueci a senha</a>
                </div>

                <button class="btn btn-primary btn-block auth-login-submit" type="submit" data-auth-login-submit><span>Entrar na minha conta</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M14 7l5 5-5 5"/></svg><i aria-hidden="true"></i></button>
                <div class="auth-trust">
                    <span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg> Seus dados estão protegidos.</span>
                    <nav aria-label="Links de confiança"><a href="<?= e(url('/politica-de-privacidade')) ?>">Privacidade</a><i>·</i><a href="<?= e(url('/termos-de-compra')) ?>">Termos de uso</a><i>·</i><a href="<?= e(url('/')) ?>">Ajuda</a></nav>
                </div>
            </form>

            <div class="auth-account-actions">
                <section class="auth-create-account">
                    <div class="auth-create-account__copy">
                        <span class="auth-create-account__icon" aria-hidden="true">+</span>
                        <div>
                            <strong>Ainda não tem conta?</strong>
                            <p>Crie agora e continue sua compra sem perder o carrinho.</p>
                        </div>
                    </div>
                    <a class="auth-create-account__cta" href="<?= e(url($registerPath)) ?>" aria-label="Criar minha conta"><span class="auth-create-account__cta-label">Criar minha conta</span><span aria-hidden="true">→</span></a>
                </section>
                <a class="auth-seller-promo" href="<?= e(url('/quero-vender')) ?>"><span><strong>Quer vender na <?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>?</strong><small>Cadastre sua loja e alcance novos clientes.</small></span><b>Conhecer o programa de vendedores →</b></a>
            </div>
        </div>
    </div>
</section>
