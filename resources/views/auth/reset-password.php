<section class="auth-card">
    <div class="auth-card__header">
        <span class="auth-eyebrow">NOVA SENHA</span>
        <h1>Crie uma nova senha.</h1>
        <p>Escolha uma senha segura que ainda não tenha sido usada nesta conta.</p>
    </div>

    <form action="<?= e(url('/redefinir-senha')) ?>" method="post" class="auth-form">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="password">Nova senha</label>
            <div class="password-field">
                <input type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
                <button type="button" class="password-toggle" data-toggle-password="password" aria-label="Mostrar senha" aria-pressed="false">Mostrar</button>
            </div>
            <small class="form-help">Use pelo menos 12 caracteres, com maiúscula, minúscula, número e símbolo.</small>
            <?php if ($message = error('password')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
        </div>
        <div class="form-group">
            <label for="password_confirmation">Confirme a nova senha</label>
            <div class="password-field">
                <input type="password" id="password_confirmation" name="password_confirmation" minlength="8" autocomplete="new-password" required>
                <button type="button" class="password-toggle" data-toggle-password="password_confirmation" aria-label="Mostrar confirmação de senha" aria-pressed="false">Mostrar</button>
            </div>
            <?php if ($message = error('password_confirmation')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Alterar senha</button>
    </form>
</section>
