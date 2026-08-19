<form class="search-bar" action="<?= e(url('/buscar')) ?>" method="get" role="search">
    <label class="sr-only" for="site-search">Buscar produtos</label>
    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
    <input id="site-search" type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Buscar produtos, ofertas e lojas na Tuffer">
    <button class="sr-only" type="submit">Buscar</button>
</form>
