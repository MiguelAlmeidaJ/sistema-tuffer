<section class="newsletter-status container">
    <?php if ($newsletterState === 'confirmed'): ?>
        <span class="eyebrow">INSCRIÇÃO CONFIRMADA</span><h1>Você está na lista.</h1><p>Agora você poderá receber novidades, ofertas e conteúdos da Tuffer. Todo envio terá uma opção simples de cancelamento.</p>
    <?php elseif ($newsletterState === 'unsubscribed'): ?>
        <span class="eyebrow">PREFERÊNCIA ATUALIZADA</span><h1>Inscrição cancelada.</h1><p>Seu e-mail não receberá novos envios de marketing. Você poderá se inscrever novamente quando quiser.</p>
    <?php else: ?>
        <span class="eyebrow">LINK INVÁLIDO</span><h1>Não foi possível concluir.</h1><p>O link pode ter expirado ou já ter sido utilizado.</p>
    <?php endif; ?>
    <a class="button button--primary" href="<?= e(url('/')) ?>">Voltar à página inicial</a>
</section>
