<section class="auth-card">
    <div class="auth-card__header">
        <span class="auth-eyebrow">CONFIRME SUA IDENTIDADE</span>
        <h1>Digite o código.</h1>
        <p>Enviamos um código para <strong><?= e($maskedEmail) ?></strong>. Ele expira em 15 minutos.</p>
    </div>

    <form action="<?= e(url('/redefinir-senha/codigo')) ?>" method="post" class="auth-form" data-code-form>
        <?= csrf_field() ?>
        <div class="verification-code" data-code-inputs>
            <?php for ($i = 0; $i < 8; $i++): ?>
                <input type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>" aria-label="Número <?= $i + 1 ?> do código">
            <?php endfor; ?>
        </div>
        <input type="hidden" name="code" data-complete-code>
        <?php if ($message = error('code')): ?><small class="form-error form-error--center"><?= e($message) ?></small><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-block">Confirmar código</button>
    </form>

    <form action="<?= e(url('/esqueci-minha-senha')) ?>" method="post" class="auth-resend" data-resend-form>
        <?= csrf_field() ?>
        <span>Não recebeu?</span>
        <button type="submit" data-resend-code>Enviar novamente</button>
        <small data-resend-status aria-live="polite"></small>
    </form>
</section>
