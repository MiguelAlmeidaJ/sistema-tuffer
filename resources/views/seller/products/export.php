<?php
$headers = array_values(array_filter($stage['headers'] ?? [], 'is_string'));
$labels = is_array($stage['labels'] ?? null) ? $stage['labels'] : [];
$rows = is_array($stage['rows'] ?? null) ? $stage['rows'] : [];
$previewHeaders = array_slice($headers, 0, 7);
$isUpload = ($stage['source_type'] ?? '') === 'upload';
?>
<header class="commerce-hero export-hero">
    <div>
        <span class="eyebrow">CATÁLOGO · <?= e($currentStore['name']) ?></span>
        <h2>Importação e exportação</h2>
        <p>Prepare, confira e organize produtos para levar dados da Tuffer a outros sistemas ou trazer um novo catálogo.</p>
    </div>
    <div class="commerce-hero__actions"><a class="button button--secondary" href="<?= e(url('/vendedor/produtos')) ?>">← Voltar aos produtos</a></div>
</header>

<?php if (is_array($importResult)): ?>
<section class="import-result <?= ($importResult['failed'] ?? 0) ? 'has-errors' : '' ?>">
    <div><span><?= ($importResult['failed'] ?? 0) ? '!' : '✓' ?></span><div><small>RESULTADO DA IMPORTAÇÃO</small><h3><?= ($importResult['failed'] ?? 0) ? 'Importação concluída com ressalvas' : 'Produtos processados com sucesso' ?></h3></div></div>
    <dl><div><dt>Criados</dt><dd><?= (int) ($importResult['created'] ?? 0) ?></dd></div><div><dt>Atualizados</dt><dd><?= (int) ($importResult['updated'] ?? 0) ?></dd></div><div><dt>Ignorados</dt><dd><?= (int) ($importResult['skipped'] ?? 0) ?></dd></div><div><dt>Com erro</dt><dd><?= (int) ($importResult['failed'] ?? 0) ?></dd></div></dl>
    <?php if (!empty($importResult['errors'])): ?><details><summary>Ver linhas que precisam de correção</summary><ul><?php foreach ($importResult['errors'] as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
    <?php if (!empty($importResult['limited'])): ?><p>Foram processadas as primeiras 2.000 linhas. Divida o arquivo para importar o restante.</p><?php endif; ?>
</section>
<?php endif; ?>

<section class="export-step export-source">
    <header class="export-step__header"><span>01</span><div><small>ORIGEM DOS DADOS</small><h3>Escolha o que deseja organizar</h3><p>Use o catálogo atual ou envie um arquivo externo para preparar uma nova estrutura.</p></div></header>
    <div class="export-source__grid">
        <article class="export-source-card <?= !$isUpload ? 'is-active' : '' ?>">
            <div class="export-source-card__icon">▦</div>
            <div><span class="export-source-card__status"><?= !$isUpload ? 'Em uso' : 'Disponível' ?></span><h4>Catálogo da loja</h4><p>Carrega produtos, variações, preços, estoque e dimensões diretamente da Tuffer.</p></div>
            <form method="post" action="<?= e(url('/vendedor/produtos/exportar/preparar')) ?>"><?= csrf_field() ?><input type="hidden" name="source" value="catalog"><button class="button button--secondary" type="submit">Carregar catálogo</button></form>
        </article>
        <article class="export-source-card <?= $isUpload ? 'is-active' : '' ?>">
            <div class="export-source-card__icon">↑</div>
            <div><span class="export-source-card__status"><?= $isUpload ? 'Em uso' : 'CSV, SQL ou XML' ?></span><h4>Enviar arquivo</h4><p>O arquivo é normalizado em uma área privada. Instruções SQL são somente lidas, nunca executadas.</p></div>
            <form class="export-upload" method="post" enctype="multipart/form-data" action="<?= e(url('/vendedor/produtos/exportar/preparar')) ?>" data-export-upload>
                <?= csrf_field() ?><input type="hidden" name="source" value="upload">
                <label class="button button--secondary"><span data-export-file-label>Escolher CSV, SQL ou XML</span><input type="file" name="product_file" accept=".csv,.sql,.xml,text/csv,application/sql,application/xml,text/xml" required hidden data-export-file></label>
                <button class="button button--dark" type="submit">Preparar arquivo</button>
            </form>
        </article>
    </div>
    <?php if (!empty($stage['sql_tables']) && is_array($stage['sql_tables'])): ?>
        <form class="sql-table-picker" method="post" action="<?= e(url('/vendedor/produtos/exportar/preparar')) ?>">
            <?= csrf_field() ?><input type="hidden" name="source" value="sql_table">
            <div><span>SQL</span><div><strong>Escolha a tabela antiga</strong><small>O arquivo possui <?= count($stage['sql_tables']) ?> tabela(s). Selecione qual representa os produtos que deseja mapear.</small></div></div>
            <label>Tabela para organizar<select name="sql_table" required><?php foreach ($stage['sql_tables'] as $table => $dataset): ?><option value="<?= e($table) ?>" <?= ($stage['selected_table'] ?? '') === $table ? 'selected' : '' ?>><?= e($table) ?> · <?= count($dataset['rows'] ?? []) ?> registro(s)</option><?php endforeach; ?></select></label>
            <button class="button button--dark" type="submit">Carregar tabela</button>
        </form>
    <?php endif; ?>
</section>

<form method="post" action="<?= e(url('/vendedor/produtos/exportar/baixar')) ?>" data-export-organizer>
    <?= csrf_field() ?>
    <section class="export-step">
        <header class="export-step__header"><span>02</span><div><small>ORGANIZAÇÃO</small><h3>Revise colunas e produtos</h3><p>Desmarque o que não precisa e mova as colunas para definir a ordem do arquivo final.</p></div><div class="export-stage-chip"><i></i><span><b><?= e($stage['source_name'] ?? 'Dados preparados') ?></b><?= count($rows) ?> linha(s) · <?= count($headers) ?> coluna(s)</span></div></header>
        <div class="export-organizer">
            <aside class="export-columns">
                <div class="export-columns__heading"><div><small>ESTRUTURA</small><h4>Colunas do arquivo</h4></div><button type="button" data-export-columns-toggle>Desmarcar todas</button></div>
                <ol data-export-columns-list>
                    <?php foreach ($headers as $index => $header): ?>
                        <li data-export-column>
                            <label><input type="checkbox" name="columns[]" value="<?= e($header) ?>" checked><span><?= e($labels[$header] ?? $header) ?></span><small><?= e($header) ?></small></label>
                            <div><button type="button" data-column-up aria-label="Mover coluna para cima">↑</button><button type="button" data-column-down aria-label="Mover coluna para baixo">↓</button></div>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <p class="export-private-note"><span>✓</span> A organização temporária expira automaticamente em 24 horas.</p>
            </aside>
            <div class="export-preview">
                <div class="export-preview__heading">
                    <div><small>PRÉVIA DOS DADOS</small><h4>Produtos preparados</h4></div>
                    <div class="export-row-mode">
                        <label><input type="radio" name="row_mode" value="all" checked> Todas as <?= count($rows) ?> linhas</label>
                        <label><input type="radio" name="row_mode" value="selected"> Somente selecionadas</label>
                    </div>
                </div>
                <div class="table-wrap export-preview__table"><table>
                    <thead><tr><th><input type="checkbox" checked data-export-rows-all aria-label="Selecionar linhas visíveis"></th><?php foreach ($previewHeaders as $header): ?><th><?= e($labels[$header] ?? $header) ?></th><?php endforeach; ?></tr></thead>
                    <tbody><?php foreach ($previewRows as $index => $row): ?><tr><td><input type="checkbox" name="rows[]" value="<?= (int) $index ?>" checked data-export-row></td><?php foreach ($previewHeaders as $header): $value=(string)($row[$header]??''); ?><td title="<?= e($value) ?>"><?= $value !== '' ? e(mb_strimwidth($value, 0, 70, '…')) : '<span class="export-empty">—</span>' ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
                </table></div>
                <?php if (count($rows) > count($previewRows)): ?><p class="export-preview__limit">A prévia mostra as primeiras <?= count($previewRows) ?> linhas. A opção “Todas” inclui as <?= count($rows) ?> linhas no arquivo.</p><?php endif; ?>
                <?php if (!$rows): ?><div class="export-no-rows"><strong>Nenhum produto preparado</strong><p>Carregue o catálogo ou envie um arquivo para continuar.</p></div><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="export-step">
        <header class="export-step__header"><span>03</span><div><small>ARQUIVO FINAL</small><h3>Escolha o formato</h3><p>A exportação respeitará exatamente a seleção e a ordem definidas acima.</p></div></header>
        <div class="export-format-grid">
            <label class="export-format-card"><input type="radio" name="format" value="csv" checked><span class="export-format-card__icon">CSV</span><span><b>Planilha</b><small>CSV UTF-8 separado por ponto e vírgula, compatível com Excel e Google Planilhas.</small></span><i></i></label>
            <label class="export-format-card"><input type="radio" name="format" value="sql"><span class="export-format-card__icon">SQL</span><span><b>Banco de dados</b><small>Cria uma tabela independente com os registros organizados e comandos INSERT.</small></span><i></i></label>
            <label class="export-format-card"><input type="radio" name="format" value="xml"><span class="export-format-card__icon">XML</span><span><b>Integrações</b><small>Estrutura hierárquica UTF-8 para ERPs, hubs e outros sistemas.</small></span><i></i></label>
        </div>
        <footer class="export-finalize">
            <label>Nome do arquivo<input name="file_name" maxlength="80" value="produtos-<?= e($currentStore['slug']) ?>" placeholder="produtos-da-loja"></label>
            <div><small>O download será gerado agora, sem alterar o catálogo.</small><button class="button button--primary" type="submit" <?= !$rows ? 'disabled' : '' ?>>Gerar e baixar arquivo ↓</button></div>
        </footer>
    </section>
</form>

<section class="export-step import-step">
    <header class="export-step__header"><span>04</span><div><small>IMPORTAR PARA A TUFFER</small><h3>Transforme as linhas em produtos</h3><p>Mapeie as colunas do arquivo para os campos do catálogo e defina como tratar SKUs existentes.</p></div></header>
    <?php if (!$isUpload): ?>
        <div class="import-empty"><span>↑</span><div><h4>Envie um arquivo para habilitar a importação</h4><p>Use o cartão “Enviar arquivo” no primeiro passo. Aceitamos CSV, XML e dumps SQL com instruções INSERT, até 5 MB.</p></div></div>
    <?php else: ?>
        <form method="post" action="<?= e(url('/vendedor/produtos/importar')) ?>" class="import-form" data-product-import>
            <?= csrf_field() ?>
            <div class="import-map">
                <div class="import-map__heading"><div><small>DE-PARA DAS COLUNAS</small><h4>Campos do catálogo</h4></div><span><?= e($stage['source_name']) ?> · <?= count($rows) ?> linha(s)</span></div>
                <div class="import-map__grid">
                    <?php foreach ($importFields as $field => $definition): ?>
                        <label class="import-map-field <?= $definition['required'] ? 'is-required' : '' ?>">
                            <span><?= e($definition['label']) ?><?= $definition['required'] ? ' *' : '' ?></span>
                            <select name="mapping[<?= e($field) ?>]" <?= $definition['required'] ? 'required' : '' ?>>
                                <option value="">Não importar este campo</option>
                                <?php foreach ($headers as $header): ?><option value="<?= e($header) ?>" <?= ($importSuggestions[$field] ?? '') === $header ? 'selected' : '' ?>><?= e($labels[$header] ?? $header) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <aside class="import-options">
                <div><small>REGRAS DA IMPORTAÇÃO</small><h4>Antes de confirmar</h4><p>Cada linha cria um produto simples. Nome, SKU e preço são obrigatórios.</p></div>
                <fieldset><legend>Quando o SKU já existir</legend><label><input type="radio" name="conflict" value="skip" checked><span><b>Ignorar a linha</b><small>Mantém o produto atual sem alterações.</small></span></label><label><input type="radio" name="conflict" value="update"><span><b>Atualizar o produto</b><small>Atualiza dados, preços e estoque da mesma loja.</small></span></label></fieldset>
                <fieldset><legend>Status dos novos produtos</legend><label><input type="radio" name="new_status" value="draft" checked><span><b>Salvar como rascunho</b><small>Recomendado para revisar imagens e detalhes.</small></span></label><label><input type="radio" name="new_status" value="active"><span><b>Publicar imediatamente</b><small>Os produtos ficam disponíveis na loja sem aprovação.</small></span></label></fieldset>
                <label class="import-confirm"><input type="checkbox" name="confirm_import" value="1" required><span>Revisei a prévia, o mapeamento e as regras acima.</span></label>
                <button class="button button--primary button--block" type="submit">Importar <?= count($rows) ?> produto(s)</button>
                <p class="import-safety"><span>⚠</span> SKUs pertencentes a outra loja nunca serão modificados. Imagens não são importadas por este arquivo.</p>
            </aside>
        </form>
    <?php endif; ?>
</section>
