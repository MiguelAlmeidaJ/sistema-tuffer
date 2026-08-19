<section class="newsletter container" id="newsletter">
    <div><span class="eyebrow">NOVIDADES SEM EXCESSO</span>
        <h2>Boas descobertas na sua caixa de entrada.</h2>
        <p>Somente novidades, ofertas e conteúdos da Tuffer. Sem compartilhamento para publicidade de terceiros.</p>
    </div>
    <form method="post" action="<?= e(url('/newsletter/assinar')) ?>"><?= csrf_field() ?><input type="hidden"
            name="source" value="site_footer"><input type="hidden" name="return"
            value="<?= e((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)) ?>"><label><span>Seu
                e-mail</span><input type="email" name="email" maxlength="190" placeholder="seu@email.com"
                required></label><label class="newsletter-consent"><input type="checkbox" name="consent" value="1"
                required><span>Autorizo o uso do meu e-mail para receber novidades e ofertas. Posso cancelar
                gratuitamente a qualquer momento. <a href="<?= e(url('/politica-de-privacidade')) ?>">Política de
                    Privacidade</a>.</span></label><button class="button button--dark" type="submit">Quero
            receber</button></form>
</section>