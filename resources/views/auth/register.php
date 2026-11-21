<?php
$redirectPath = (string) ($redirectPath ?? '');
$loginPath = '/entrar' . ($redirectPath !== '' ? '?redirect=' . rawurlencode($redirectPath) : '');
?>
<section class="auth-card auth-card--register" data-stepped-card>
    <div class="auth-card__header">
        <span class="auth-eyebrow">CONTA DE CLIENTE</span>
        <h1>Crie sua conta.</h1>
        <p>Complete seus dados e defina uma senha segura. Leva menos de um minuto.</p>
    </div>

<?php if (trim((string) ($_ENV['GOOGLE_CLIENT_ID'] ?? '')) !== '' && trim((string) ($_ENV['GOOGLE_CLIENT_SECRET'] ?? '')) !== ''): ?>
            <a class="account-gate__google" style="display:flex;margin:16px 0;min-height:50px;align-items:center;justify-content:center;gap:10px;border:1px solid #dadce0;border-radius:12px;text-decoration:none;color:#242424;font-weight:750" href="<?=e(url('/auth/google?redirect='.rawurlencode($redirectPath !== '' ? $redirectPath : '/minha-conta')))?>">
              <svg viewBox="0 0 24 24" width="21" height="21" aria-hidden="true"><path fill="#4285F4" d="M21.35 12.24c0-.72-.06-1.23-.2-1.77H12v3.43h5.36a4.6 4.6 0 0 1-2 3.03v2.5h3.24c1.9-1.75 2.75-4.33 2.75-7.19Z"/><path fill="#34A853" d="M12 21.75c2.63 0 4.83-.86 6.44-2.32l-3.23-2.5c-.88.59-2.02.94-3.21.94-2.47 0-4.56-1.67-5.31-3.92H3.35v2.57A9.75 9.75 0 0 0 12 21.75Z"/><path fill="#FBBC05" d="M6.69 13.95A5.8 5.8 0 0 1 6.4 12c0-.68.11-1.35.29-1.95V7.48H3.35A9.76 9.76 0 0 0 2.25 12c0 1.58.38 3.08 1.1 4.52l3.34-2.57Z"/><path fill="#EA4335" d="M12 6.13c1.43 0 2.72.49 3.74 1.46l2.88-2.88A9.27 9.27 0 0 0 12 2.25a9.75 9.75 0 0 0-8.65 5.23l3.34 2.57c.75-2.25 2.84-3.92 5.31-3.92Z"/></svg>
              Continuar com Google
            </a>
            <?php endif; ?>
    <ol class="auth-steps" aria-label="Etapas do cadastro">
        <li class="is-active" data-step-indicator="1"><span>1</span> Seus dados</li>
        <li data-step-indicator="2"><span>2</span> Segurança</li>
    </ol>

    <form action="<?= e(url('/cadastro')) ?>" method="post" class="auth-form" data-stepped-form data-initial-step="<?= (int) old('step', 1) ?>">
        <?= csrf_field() ?>
        <?php if ($redirectPath !== ''): ?><input type="hidden" name="redirect" value="<?= e($redirectPath) ?>"><?php endif; ?>
        <div class="auth-step auth-register-data" data-auth-step="1">
            <div class="auth-register-grid">
                <div class="form-group auth-register-grid__full">
                    <label for="name">Nome completo</label>
                    <input id="name" type="text" name="name" value="<?= e(old('name')) ?>" required minlength="3" autocomplete="name" placeholder="Como podemos chamar você?">
                    <?php if ($message = error('name')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
                </div>
                <div class="form-group auth-register-grid__full">
                    <label for="email">E-mail</label>
                    <input id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email" placeholder="voce@exemplo.com">
                    <?php if ($message = error('email')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="phone">Telefone com DDD</label>
                    <input id="phone" type="tel" name="phone" value="<?= e(old('phone')) ?>" required autocomplete="tel" inputmode="tel" data-phone-input placeholder="(22) 99999-9999">
                    <?php if ($message = error('phone')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="document">CPF</label>
                    <input id="document" type="text" name="document" value="<?= e(old('document')) ?>" required autocomplete="off" inputmode="numeric" data-cpf-input maxlength="14" placeholder="000.000.000-00">
                    <?php if ($message = error('document')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
                </div>
            </div>
            <button class="btn btn-primary btn-block" type="button" data-auth-next>Continuar <span aria-hidden="true">→</span></button>
        </div>

        <div class="auth-step" data-auth-step="2" hidden>
            <div class="form-group">
                <label for="password">Crie uma senha</label>
                <div class="password-field">
                    <input id="password" type="password" name="password" required minlength="12" autocomplete="new-password">
                    <button type="button" class="password-toggle" data-toggle-password="password" aria-label="Mostrar senha" aria-pressed="false">Mostrar</button>
                </div>
                <small class="form-help">Use pelo menos 12 caracteres, com maiúscula, minúscula, número e símbolo.</small>
                <?php if ($message = error('password')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
            </div>
            <div class="form-group">
                <label for="password_confirmation">Confirme sua senha</label>
                <div class="password-field">
                    <input id="password_confirmation" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">
                    <button type="button" class="password-toggle" data-toggle-password="password_confirmation" aria-label="Mostrar confirmação de senha" aria-pressed="false">Mostrar</button>
                </div>
                <?php if ($message = error('password_confirmation')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
            </div>
            <label class="auth-checkbox auth-checkbox--terms"><input type="checkbox" name="terms" value="1" required><span>Li e aceito os termos de uso e a política de privacidade.</span></label>
            <?php if ($message = error('terms')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
            <div class="auth-step__actions">
                <button class="auth-back-button" type="button" data-auth-previous>← Voltar</button>
                <button class="btn btn-primary" type="submit">Criar minha conta</button>
            </div>
        </div>
    </form>

    <a href="<?= e(url($loginPath)) ?>" class="auth-back-link">Já tem cadastro? <strong>Entrar</strong></a>
</section>
