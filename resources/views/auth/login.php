<section class="auth-card auth-card--login">
    <div class="auth-login__panel">
        <div class="auth-login__panel-inner">
            <a class="auth-login__logo" href="<?= e(url('/')) ?>" aria-label="<?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>, página inicial"><img src="<?= e(upload_asset($platformSettings['logo_path'] ?? 'platform/logos/tuffer-logo.svg')) ?>" alt="<?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>"></a>
            <div class="auth-card__header">
                <span class="auth-eyebrow">BEM-VINDO</span>
                <h1>Entre na sua conta.</h1>
                <p>Use seu e-mail e senha para continuar.</p>
            </div>

            <form action="<?= e(url('/entrar')) ?>" method="post" class="auth-form" data-auth-login-form>
                <?= csrf_field() ?>
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
                <section class="auth-create-account"><div><strong>Novo por aqui?</strong><p>Crie sua conta para comprar na <?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>.</p></div><a href="<?= e(url('/cadastro')) ?>">Criar conta</a></section>
                <a class="auth-seller-promo" href="<?= e(url('/quero-vender')) ?>"><span><strong>Quer vender na <?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>?</strong><small>Cadastre sua loja e alcance novos clientes.</small></span><b>Conhecer o programa de vendedores →</b></a>
            </div>
        </div>
    </div>
</section>
