<section class="auth-card auth-card--wide" data-stepped-card>
    <div class="auth-card__header">
        <span class="auth-eyebrow">VENDA NA TUFFER</span>
        <h1>Sua loja começa aqui.</h1>
        <p>Conte quem você é. Na etapa seguinte, proteja seu acesso e envie o cadastro para análise.</p>
    </div>

    <ol class="auth-steps" aria-label="Etapas do cadastro">
        <li class="is-active" data-step-indicator="1"><span>1</span> Dados da loja</li>
        <li data-step-indicator="2"><span>2</span> Segurança</li>
    </ol>

    <form action="<?= e(url('/quero-vender')) ?>" method="post" class="auth-form" data-stepped-form data-initial-step="<?= (int) old('step', 1) ?>">
        <?= csrf_field() ?>
        <div class="auth-step auth-step--grid" data-auth-step="1">
            <div class="form-group">
                <label for="name">Seu nome</label>
                <input id="name" type="text" name="name" value="<?= e(old('name')) ?>" required minlength="3" autocomplete="name">
                <?php if ($message = error('name')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
            </div>
            <div class="form-group">
                <label for="trade_name">Nome da loja</label>
                <input id="trade_name" type="text" name="trade_name" value="<?= e(old('trade_name')) ?>" required autocomplete="organization">
                <?php if ($message = error('trade_name')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
            </div>
            <div class="form-group">
                <label for="email">E-mail</label>
                <input id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email">
                <?php if ($message = error('email')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
            </div>
            <div class="form-group">
                <label for="document">CNPJ</label>
                <input id="document" type="text" name="document" value="<?= e(old('document')) ?>" required minlength="14" inputmode="numeric">
                <?php if ($message = error('document')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
            </div>
            <button class="btn btn-primary btn-block auth-step__full" type="button" data-auth-next>Continuar <span aria-hidden="true">→</span></button>
        </div>

        <div class="auth-step" data-auth-step="2" hidden>
            <div class="form-group">
                <label for="password">Crie uma senha</label>
                <div class="password-field">
                    <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
                    <button type="button" class="password-toggle" data-toggle-password="password" aria-label="Mostrar senha" aria-pressed="false">Mostrar</button>
                </div>
                <small class="form-help">Use pelo menos 12 caracteres, com maiúscula, minúscula, número e símbolo.</small>
                <?php if ($message = error('password')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
            </div>
            <div class="form-group">
                <label for="password_confirmation">Confirme sua senha</label>
                <div class="password-field">
                    <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
                    <button type="button" class="password-toggle" data-toggle-password="password_confirmation" aria-label="Mostrar confirmação de senha" aria-pressed="false">Mostrar</button>
                </div>
                <?php if ($message = error('password_confirmation')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
            </div>
            <label class="auth-checkbox auth-checkbox--terms"><input type="checkbox" name="terms" value="1" required><span>Li e aceito os termos de uso e a política de privacidade.</span></label>
            <?php if ($message = error('terms')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
            <div class="auth-step__actions">
                <button class="auth-back-button" type="button" data-auth-previous>← Voltar</button>
                <button class="btn btn-primary" type="submit">Enviar cadastro</button>
            </div>
        </div>
    </form>
    <a href="<?= e(url('/entrar')) ?>" class="auth-back-link">Já é vendedor? <strong>Entrar</strong></a>
</section>
