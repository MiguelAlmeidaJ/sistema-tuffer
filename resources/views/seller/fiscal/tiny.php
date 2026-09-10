<?php
$icon = static function(string $name): string {
    $paths = [
        'bolt' => '<path d="M13 2 5 14h6l-1 8 8-12h-6l1-8Z"/>',
        'cube' => '<path d="m12 2 8 4.5v9L12 20l-8-4.5v-9L12 2Z"/><path d="m4 6.5 8 4.5 8-4.5M12 11v9"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'lock' => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'file' => '<path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21H9.6v-.1A1.7 1.7 0 0 0 8.5 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3V9.6h.1A1.7 1.7 0 0 0 4.6 8.5a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.1A1.7 1.7 0 0 0 15.5 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.15.38.37.72.66 1 .3.28.68.43 1.08.43H21v4h-.1A1.7 1.7 0 0 0 19.4 15Z"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4 20-7Z"/><path d="M22 2 11 13"/>',
        'code' => '<path d="M8 9 5 12l3 3M16 9l3 3-3 3M14 6l-4 12"/>',
        'refresh' => '<path d="M20 11a8 8 0 1 0 2 5M20 4v7h-7"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? $paths['file']) . '</svg>';
};

$isConnected = !empty($connector['enabled']);
?>

<div class="tiny-connector-page">
    <section class="tiny-hero">
        <div class="tiny-hero__copy">
            <span class="tiny-eyebrow">Fiscal · Conector</span>
            <h2>Tiny / Olist ERP</h2>
            <p class="tiny-hero__lead">Conecte a conta fiscal desta loja para enviar pedidos pagos e acompanhar a NF-e automaticamente.</p>
            <div class="tiny-hero__features">
                <span class="tiny-feature"><?=$icon('bolt')?> Emissão automática de NF-e</span>
                <span class="tiny-feature"><?=$icon('cube')?> Integração com Tiny / Olist</span>
                <span class="tiny-feature"><?=$icon('chart')?> Mais controle para sua operação</span>
            </div>
        </div>
        <div class="tiny-hero__status">
            <span class="tiny-logo-mark">TY</span>
            <div>
                <strong><?=e($currentStore['name'])?></strong>
                <small><i class="tiny-status-dot <?=$isConnected?'is-online':''?>"></i><?=$isConnected?'Tiny conectado':'Tiny não conectado'?></small>
            </div>
        </div>
    </section>

    <div class="tiny-toolbar">
        <a class="tiny-back" href="<?=e(url('/vendedor/fiscal'))?>">← Voltar ao Fiscal</a>
    </div>

    <div class="tiny-alert">
        <span class="tiny-alert__icon">i</span>
        <div>A Tuffer não usa certificado A1 da loja. A emissão acontece dentro da conta Tiny/Olist do vendedor usando o token dessa própria conta. O CPF/CNPJ retornado pelo Tiny precisa corresponder ao documento fiscal da loja.</div>
    </div>

    <?php if(!$profile):?>
        <div class="tiny-alert tiny-alert--danger">
            <span class="tiny-alert__icon">!</span>
            <div>Salve primeiro os dados fiscais desta loja em Fiscal antes de conectar o Tiny.</div>
        </div>
    <?php endif;?>

    <section class="tiny-card">
        <header class="tiny-card__head">
            <div class="tiny-card__title">
                <span class="tiny-step-badge">01</span>
                <div>
                    <h3>Conta Tiny desta loja</h3>
                    <p>O token é criptografado e nunca volta a ser exibido depois de salvo.</p>
                </div>
            </div>
            <span class="tiny-secure"><?=$icon('lock')?> Conexão segura e exclusiva desta loja</span>
        </header>

        <?php if($connector):?>
            <div class="tiny-account-summary">
                <div><small>Status</small><strong class="tiny-connection-chip"><i class="tiny-status-dot <?=$isConnected?'is-online':''?>"></i><?=$isConnected?'Conectado':'Desconectado'?></strong></div>
                <div><small>Conta</small><strong><?=e((string)($connector['account_name']??'—'))?></strong></div>
                <div><small>CPF/CNPJ</small><strong><?=e((string)($connector['account_document']??'—'))?></strong></div>
                <div><small>Token salvo</small><code><?=e((string)($connector['credential_prefix']??'—'))?></code></div>
                <div><small>Último teste</small><strong><?=e((string)($connector['last_test_at']??'Nunca'))?></strong></div>
                <div><small>Último sucesso</small><strong><?=e((string)($connector['last_success_at']??'Nunca'))?></strong></div>
            </div>
            <?php if(!empty($connector['last_error'])):?><div class="tiny-connector-error"><strong>Último erro:</strong> <?=e((string)$connector['last_error'])?></div><?php endif;?>
        <?php endif;?>

        <form class="tiny-config" method="post" action="<?=e(url('/vendedor/fiscal/conectores/tiny/conectar'))?>">
            <?=csrf_field()?>
            <div class="tiny-config__form">
                <label class="tiny-label">Token API Tiny
                    <input type="password" name="token" autocomplete="off" required placeholder="Cole o token da conta desta loja">
                    <small>Ao salvar um novo token, a credencial anterior é substituída.</small>
                </label>
                <div>
                    <div class="tiny-automation-title">E-mail pelo Tiny</div>
                    <label class="tiny-check"><input type="checkbox" name="send_email" value="1" <?=!empty($connector['send_email'])?'checked':''?>><span>Pedir ao Tiny para enviar a NF-e ao e-mail do cliente</span></label>
                </div>
            </div>
            <div class="tiny-config__automation">
                <div class="tiny-automation-title">Automação</div>
                <div class="tiny-toggle-row">
                    <label class="tiny-toggle" aria-label="Ativar emissão automática">
                        <input type="checkbox" name="auto_emit" value="1" <?=empty($connector)||!empty($connector['auto_emit'])?'checked':''?>>
                        <span></span>
                    </label>
                    <div class="tiny-toggle-copy">
                        <strong>Gerar e solicitar emissão da NF-e automaticamente</strong>
                        <small>Desmarcado: a Tuffer cria a NF-e no Tiny e aguarda você emitir no próprio Tiny.</small>
                    </div>
                </div>
                <div class="tiny-config__actions">
                    <button class="tiny-primary-action" <?=$profile?'':'disabled'?>><?=$isConnected?'Atualizar conexão':'Conectar Tiny'?> <span>→</span></button>
                </div>
            </div>
        </form>

        <?php if($isConnected):?>
            <div class="tiny-connected-actions">
                <span><i class="tiny-status-dot is-online"></i> Integração ativa nesta loja</span>
                <div>
                    <form method="post" action="<?=e(url('/vendedor/fiscal/conectores/tiny/testar'))?>"><?=csrf_field()?><button class="tiny-secondary-action">Testar conexão</button></form>
                    <form method="post" action="<?=e(url('/vendedor/fiscal/conectores/tiny/desconectar'))?>"><?=csrf_field()?><button class="tiny-secondary-action tiny-secondary-action--danger">Desconectar Tiny</button></form>
                </div>
            </div>
        <?php endif;?>
    </section>

    <section class="tiny-card">
        <header class="tiny-card__head">
            <div class="tiny-card__title">
                <span class="tiny-step-badge">02</span>
                <div>
                    <h3>Fluxo automático</h3>
                    <p>Depois do pagamento, cada seller_order desta loja é tratado de forma independente.</p>
                </div>
            </div>
        </header>
        <div class="tiny-flow">
            <article class="tiny-flow__item"><span class="tiny-flow__number">1</span><?=$icon('search')?><strong>Tuffer procura o pedido no Tiny pelo código do seller_order.</strong></article>
            <article class="tiny-flow__item"><span class="tiny-flow__number">2</span><?=$icon('file')?><strong>Se não existir, cria o pedido como aprovado.</strong></article>
            <article class="tiny-flow__item"><span class="tiny-flow__number">3</span><?=$icon('gear')?><strong>Procura ou gera a NF-e vinculada ao pedido.</strong></article>
            <article class="tiny-flow__item"><span class="tiny-flow__number">4</span><?=$icon('send')?><strong>Solicita emissão e acompanha a autorização.</strong></article>
            <article class="tiny-flow__item"><span class="tiny-flow__number">5</span><?=$icon('code')?><strong>Chave e XML autorizados voltam para o pedido na Tuffer.</strong></article>
        </div>
    </section>

    <section class="tiny-card">
        <header class="tiny-card__head">
            <div class="tiny-card__title">
                <span class="tiny-step-badge">03</span>
                <div>
                    <h3>Sincronizações recentes</h3>
                    <p>Histórico operacional do conector Tiny desta loja.</p>
                </div>
            </div>
            <a class="tiny-refresh" href="<?=e(url('/vendedor/fiscal/conectores/tiny'))?>"><?=$icon('refresh')?> Atualizar</a>
        </header>
        <div class="tiny-table-wrap">
            <table class="tiny-table">
                <thead><tr><th>Pedido</th><th>Status</th><th>Pedido Tiny</th><th>NF-e Tiny</th><th>NF-e Tuffer</th><th>Ação</th></tr></thead>
                <tbody>
                <?php if(!$runs):?>
                    <tr><td colspan="6" class="tiny-empty"><div class="tiny-empty__inner"><span class="tiny-empty__icon"><?=$icon('file')?></span><div><strong>Nenhuma sincronização Tiny ainda.</strong><small>As sincronizações aparecerão aqui após a primeira execução.</small></div></div></td></tr>
                <?php endif;?>
                <?php foreach($runs as $r):$status=(string)$r['status'];$statusClass=preg_replace('/[^a-z0-9_]+/','_',mb_strtolower($status))?:'default';?>
                    <tr>
                        <td><strong><?=e((string)$r['seller_order_code'])?></strong></td>
                        <td><span class="tiny-status tiny-status--<?=e($statusClass)?>"><?=e(str_replace('_',' ',$status))?></span><?php if(!empty($r['last_error'])):?><br><small><?=e((string)$r['last_error'])?></small><?php endif;?></td>
                        <td><?=e((string)($r['provider_order_number']?:$r['provider_order_id']?:'—'))?></td>
                        <td><?=e((string)($r['provider_document_number']?:$r['provider_document_id']?:'—'))?><?php if(!empty($r['provider_status'])):?><br><small><?=e((string)$r['provider_status'])?></small><?php endif;?><?php if(!empty($r['provider_document_url'])&&str_starts_with((string)$r['provider_document_url'],'https://tiny.com.br/')):?><br><a href="<?=e((string)$r['provider_document_url'])?>" target="_blank" rel="noopener noreferrer">Abrir no Tiny ↗</a><?php endif;?></td>
                        <td><?php if(!empty($r['access_key'])):?><strong>Nº <?=e((string)$r['number'])?> · série <?=e((string)$r['series'])?></strong><br><small style="word-break:break-all"><?=e((string)$r['access_key'])?></small><?php else:?><?=e(str_replace('_',' ',(string)$r['document_status']))?><?php endif;?></td>
                        <td><?php if($status!=='completed'):?><form method="post" action="<?=e(url('/vendedor/fiscal/conectores/tiny/processar/'.(int)$r['id']))?>"><?=csrf_field()?><button class="tiny-secondary-action">Reprocessar</button></form><?php else:?><span class="tiny-status tiny-status--completed">Concluído</span><?php endif;?></td>
                    </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
    </section>
</div>
