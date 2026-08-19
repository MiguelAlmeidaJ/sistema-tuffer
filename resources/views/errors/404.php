<section class="error-state">
    <span class="eyebrow">ERRO 404</span>
    <h1>Não encontramos essa página.</h1>
    <p>O endereço <code><?= e($path ?? '') ?></code> não existe ou foi movido.</p>
    <a class="button button--primary" href="<?= e(url('/')) ?>">Voltar à loja</a>
</section>
