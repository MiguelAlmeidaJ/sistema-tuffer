<?php
$sectionId = static function (int $index, string $title): string {
    $normalized = strtr(mb_strtolower($title), [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);
    $normalized = (string) preg_replace('/^\d+\s*[.\-:]?\s*/', '', $normalized);
    return 'secao-' . ($index + 1) . '-' . trim((string) preg_replace('/[^a-z0-9]+/', '-', $normalized), '-');
};
?>
<div class="policy-page">
    <section class="policy-page__hero container">
        <nav class="commerce-breadcrumb" aria-label="Navegação estrutural"><a href="<?= e(url('/')) ?>">Início</a><span>›</span><a href="<?= e(url('/politicas')) ?>">Políticas</a><span>›</span><strong><?= e($policy['short_title']) ?></strong></nav>
        <div class="policy-page__heading">
            <div><span class="eyebrow"><?= e(mb_strtoupper($policy['audience'])) ?></span><h1><?= e($policy['title']) ?></h1><p><?= e($policy['summary']) ?></p></div>
            <aside><span>ÚLTIMA ATUALIZAÇÃO</span><strong><?= e($policy['updated']) ?></strong><?php if (!empty($policy['essential'])): ?><b>Documento essencial</b><?php endif; ?></aside>
        </div>
    </section>

    <div class="policy-page__layout container">
        <aside class="policy-toc">
            <a class="policy-toc__back" href="<?= e(url('/politicas')) ?>">← Todas as políticas</a>
            <strong>Nesta página</strong>
            <nav><?php foreach ($policy['sections'] as $index => $contentSection): ?><a href="#<?= e($sectionId($index, $contentSection['title'])) ?>"><?= e($contentSection['title']) ?></a><?php endforeach; ?></nav>
            <small>Versão <?= e($policy['updated']) ?></small>
        </aside>

        <article class="policy-document">
            <div class="policy-document__notice"><span>i</span><p>Este documento integra as regras da plataforma. Leia também as políticas relacionadas à sua atividade como comprador ou vendedor.</p></div>
            <?php foreach ($policy['sections'] as $index => $contentSection): ?>
                <section id="<?= e($sectionId($index, $contentSection['title'])) ?>">
                    <span><?= e(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)) ?></span>
                    <div><h2><?= e($contentSection['title']) ?></h2>
                        <?php foreach ($contentSection['paragraphs'] ?? [] as $paragraph): ?><p><?= e($paragraph) ?></p><?php endforeach; ?>
                        <?php if (!empty($contentSection['items'])): ?><ul><?php foreach ($contentSection['items'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul><?php endif; ?>
                        <?php if (!empty($contentSection['table'])): ?><div class="policy-example"><span>EXEMPLO ILUSTRATIVO</span><?php foreach ($contentSection['table'] as $rowIndex => $row): ?><div class="<?= $rowIndex === count($contentSection['table']) - 1 ? 'is-total' : '' ?>"><span><?= e($row[0]) ?></span><strong><?= e($row[1]) ?></strong></div><?php endforeach; ?></div><?php endif; ?>
                        <?php if (!empty($contentSection['note'])): ?><div class="policy-note"><b>Importante</b><p><?= e($contentSection['note']) ?></p></div><?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <?php if (!empty($policy['references'])): ?><section class="policy-references"><span>↗</span><div><h2>Referências oficiais</h2><p>Consulte as fontes públicas que orientam este documento.</p><nav><?php foreach ($policy['references'] as $reference): ?><a href="<?= e($reference['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($reference['label']) ?> <i>↗</i></a><?php endforeach; ?></nav></div></section><?php endif; ?>

            <footer class="policy-document__footer"><div><strong>Ainda ficou com dúvida?</strong><p>Nosso atendimento pode orientar sobre a aplicação desta política ao seu caso.</p></div><a href="mailto:<?= e($platformSettings['support_email'] ?? 'contato@tuffer.com.br') ?>">Falar com atendimento →</a></footer>
        </article>
    </div>
</div>
