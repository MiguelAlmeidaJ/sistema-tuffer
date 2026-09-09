# Módulo fiscal

O módulo fiscal da Tuffer é orientado ao `seller_order`: cada fatia da compra pertencente a uma loja possui seu próprio documento fiscal. O `order` agregado nunca é usado como uma NF-e única de vários vendedores.

## Princípio de responsabilidade

A Tuffer não emite NF-e, não assina XML, não guarda certificado A1 de vendedor e não transmite documento para provider ou SEFAZ.

Cada loja é responsável pelo próprio emissor fiscal. A Tuffer atua como camada de vínculo, recebimento, armazenamento, auditoria e disponibilização da NF-e associada ao pedido.

A configuração fiscal efetiva é da `store`, com fallback temporário para `seller_fiscal_profiles`. Isso permite CNPJ, IE, série, endereço fiscal e sistema emissor diferentes entre lojas do mesmo vendedor.

`store_fiscal_profiles` possui dois modos:

- `manual`: a loja emite no sistema de sua preferência e registra autorização/arquivos em `/vendedor/fiscal/integracao`.
- `external`: ERP/API da loja emite e sincroniza autorização/cancelamento pela API autenticada da Tuffer.

O valor legado `platform` não é mais suportado. A migration `038_remove_platform_fiscal_issuance.sql` converte perfis/documentos antigos para `manual` e remove `auto_issue` do perfil da loja.

O campo `provider` em `store_fiscal_profiles` é apenas identificação do sistema usado pela loja, como Focus, Nuvem Fiscal, PlugNotas, ERP próprio ou outro emissor. A Tuffer não usa credenciais desse provider para emitir.

O `fiscal_document` preserva `issuance_mode`, `provider` e `environment` do pedido, mesmo se a configuração da loja mudar depois.

## Segurança

Certificado A1, senha e segredos do emissor permanecem sob responsabilidade da loja e não pertencem ao cadastro fiscal da Tuffer.

A credencial da API externa é gerada por loja e armazenada somente como hash SHA-256 em `store_fiscal_api_credentials`; o token completo aparece uma única vez no painel.

O webhook de saída possui um segredo HMAC independente do Bearer token. O segredo completo também aparece uma única vez e fica criptografado com AES-256-GCM usando chave derivada de `APP_KEY`.

A URL do webhook precisa ser HTTPS público na porta 443. A política de saída bloqueia destinos locais, privados ou reservados, não segue redirects e fixa a conexão ao IPv4 público resolvido para reduzir risco de SSRF/DNS rebinding.

XML e DANFE ficam em `storage/private/fiscal`, com permissão privada. XML recebido de fora precisa ser uma NF-e reconhecível, não pode declarar DTD/entidades e, quando contém chave em `infNFe/@Id`, ela deve coincidir com a chave registrada. DANFE precisa ser PDF válido. Cliente e admin acessam arquivos somente por controllers autenticados.

## Fluxo `manual`

1. Pagar.me confirma o pagamento.
2. A fila agenda `fiscal.sync_paid_order`.
3. O documento da loja fica `awaiting_manual`.
4. A loja emite a NF-e no próprio sistema.
5. O vendedor registra número, série, chave e, opcionalmente, protocolo, XML e DANFE.
6. Arquivos ficam privados e a NF-e aparece no pedido do cliente.
7. Cancelamento realizado fora da Tuffer pode ser registrado no painel sem apagar o histórico.

## Fluxo `external` automático

1. Pagar.me confirma o pagamento.
2. A fila agenda `fiscal.sync_paid_order`.
3. O documento da loja fica `awaiting_external`.
4. Se a loja possui webhook ativo, a Tuffer cria uma única entrega `fiscal.seller_order.ready` para o `seller_order`.
5. A fila envia o webhook HTTPS assinado com HMAC. Falhas temporárias são reprocessadas com backoff.
6. O ERP valida `X-Tuffer-Signature`, deduplica `X-Tuffer-Event` e usa o Bearer token da própria loja para consultar `GET /api/v1/fiscal/seller-orders/{code}/payload`.
7. O payload contém pedido, totais, emissor, destinatário e referências fiscais dos itens. A Tuffer não calcula tributos.
8. O ERP emite a NF-e no emissor fiscal do próprio vendedor.
9. Após autorização, o ERP chama `POST /api/v1/fiscal/seller-orders/{code}/authorize`, podendo enviar XML e DANFE.
10. A Tuffer valida, registra e armazena os arquivos de forma privada. Repetições idênticas são idempotentes e uma NF-e autorizada não pode ser substituída silenciosamente.
11. Cancelamento feito no emissor externo é sincronizado por `POST /api/v1/fiscal/seller-orders/{code}/cancel`.
12. O cliente visualiza e baixa os documentos pelo próprio pedido.

O webhook é somente uma notificação. Dados fiscais completos são obtidos pela API autenticada, evitando transportar informações sensíveis desnecessariamente no evento de saída.

A ativação tardia do webhook reconcilia até 100 pedidos elegíveis da loja. O comando de reconciliação também reavalia os webhooks sem duplicar eventos já criados.

Contrato detalhado: `docs/FISCAL_API.md`.

## Dados fiscais de produto

`product_fiscal_profiles` continua disponível como referência opcional da loja e para integrações/exportações. A Tuffer não usa esses campos para calcular tributos nem para autorizar NF-e.

A fonte responsável por cálculo tributário, regras fiscais e emissão é sempre o sistema fiscal escolhido pelo vendedor.

## Operação administrativa

`/admin/fiscal` centraliza a visão de documentos fiscais de todas as lojas. O painel permite filtrar por status, modo e pendências, abrir um documento, consultar snapshots dos itens, baixar XML/DANFE privados e revisar a trilha de `fiscal_events`.

O painel administrativo é deliberadamente read-only para ações fiscais sensíveis: não existe botão para emitir ou cancelar NF-e pela Tuffer. Emissão e cancelamento acontecem no sistema da loja e são apenas sincronizados/registrados na plataforma.

O dashboard administrativo também destaca a quantidade de documentos com `requires_action=1`.

## Reembolso

Reembolso nunca cancela automaticamente uma NF-e autorizada. O documento fica marcado para revisão fiscal para que a loja decida, no próprio emissor, se deve cancelar, emitir devolução ou outro evento fiscal. Depois, o resultado pode ser sincronizado com a Tuffer.

## Reconciliação

```bash
php scripts/sync-fiscal-paid-orders.php 200
```

Além de sincronizar os documentos dos pedidos elegíveis, esse comando agenda o evento automático para lojas `external` com webhook ativo quando ainda não existir uma entrega para aquele `seller_order`.

## Requisitos operacionais do fluxo automático

- `APP_URL` deve apontar para a URL pública HTTPS da Tuffer.
- `APP_KEY` deve estar configurada e estável; ela protege os segredos HMAC armazenados.
- o worker precisa consumir a fila `fiscal`.
- cada loja externa deve possuir Bearer token ativo e webhook configurado.
- o endpoint do ERP deve responder `2xx` após aceitar o evento.

## O que ainda pode evoluir

1. Monitoramento/SLA de NF-e pendentes por loja sem assumir responsabilidade pela emissão.
2. Botão administrativo/vendedor para reprocessar manualmente uma entrega de webhook específica.
3. Alertas após esgotamento das tentativas de entrega.
4. Suporte explícito a endpoints IPv6 públicos após adicionar resolução e pinning equivalentes ao caminho IPv4.
5. Validar com a contabilidade quais dados fiscais de referência devem permanecer obrigatórios na Tuffer.
