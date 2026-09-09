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

XML e DANFE ficam em `storage/private/fiscal`, com permissão privada. XML recebido de fora precisa ser uma NF-e reconhecível, não pode declarar DTD/entidades e, quando contém chave em `infNFe/@Id`, ela deve coincidir com a chave registrada. DANFE precisa ser PDF válido. Cliente e admin acessam arquivos somente por controllers autenticados.

## Fluxo `manual`

1. Pagar.me confirma o pagamento.
2. A fila agenda `fiscal.sync_paid_order`.
3. O documento da loja fica `awaiting_manual`.
4. A loja emite a NF-e no próprio sistema.
5. O vendedor registra número, série, chave e, opcionalmente, protocolo, XML e DANFE.
6. Arquivos ficam privados e a NF-e aparece no pedido do cliente.
7. Cancelamento realizado fora da Tuffer pode ser registrado no painel sem apagar o histórico.

## Fluxo `external`

1. Pagar.me confirma o pagamento.
2. A fila agenda `fiscal.sync_paid_order`.
3. O documento da loja fica `awaiting_external`.
4. O vendedor gera uma credencial exclusiva para a loja.
5. O ERP/emissor da loja consulta o `seller_order` e envia a autorização para `/api/v1/fiscal`.
6. A Tuffer bloqueia substituição de NF-e já autorizada, aceita repetições idempotentes e serializa respostas concorrentes.
7. O sistema externo pode enviar XML e DANFE junto da autorização e depois registrar cancelamento.
8. O cliente visualiza e baixa os documentos pelo próprio pedido.

Contrato detalhado: `docs/FISCAL_API.md`.

## Dados fiscais de produto

`product_fiscal_profiles` continua disponível como referência opcional da loja e para futuras integrações/exportações. A Tuffer não usa esses campos para calcular tributos nem para autorizar NF-e.

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

## O que ainda pode evoluir

1. Enriquecer a API externa com payload de pedido/produtos para ERPs que desejem consumir os dados da Tuffer.
2. Criar webhooks de saída para avisar o sistema da loja quando um `seller_order` ficar apto para emissão.
3. Adicionar assinatura HMAC opcional nos webhooks de saída.
4. Criar monitoramento de notas pendentes por SLA sem assumir responsabilidade pela emissão.
5. Validar com a contabilidade quais dados fiscais de referência devem permanecer obrigatórios na Tuffer.
