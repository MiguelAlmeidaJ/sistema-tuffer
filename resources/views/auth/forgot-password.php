<section class="auth-card">
    <div class="auth-card__header">
        <span class="auth-eyebrow">RECUPERAR ACESSO</span>
        <h1>Esqueceu sua senha?</h1>
        <p>Informe seu e-mail e enviaremos um código de 8 números para confirmar sua identidade.</p>
    </div>

    <form action="<?= e(url('/esqueci-minha-senha')) ?>" method="post" class="auth-form">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" placeholder="voce@exemplo.com" autocomplete="email" required>
            <?php if ($message = error('email')): ?><small class="form-error"><?= e($message) ?></small><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Enviar código</button>
        <a href="<?= e(url('/entrar')) ?>" class="auth-back-link">← Voltar para o login</a>
    </form>
</section>
