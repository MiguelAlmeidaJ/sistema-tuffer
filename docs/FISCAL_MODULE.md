# Módulo fiscal

O módulo fiscal da Tuffer é orientado ao `seller_order`: cada fatia da compra pertencente a uma loja possui seu próprio documento fiscal. O `order` agregado nunca é usado como uma NF-e única de vários vendedores.

## Princípio de responsabilidade

A Tuffer não assina XML, não guarda certificado A1 de vendedor e não transmite NF-e diretamente à SEFAZ.

Cada loja continua responsável pela própria configuração fiscal e pelo próprio emissor. Quando a loja usa um conector nativo, como Tiny/Olist ERP, a Tuffer pode orquestrar chamadas na conta do próprio vendedor usando uma credencial exclusiva daquela `store`. A emissão fiscal continua acontecendo no provedor conectado da loja, nunca em uma conta fiscal global da Tuffer.

A configuração fiscal efetiva é da `store`, com fallback temporário para `seller_fiscal_profiles`. Isso permite CNPJ, IE, série, endereço fiscal e sistema emissor diferentes entre lojas do mesmo vendedor.

`store_fiscal_profiles` possui três modos:

- `manual`: a loja emite no sistema de sua preferência e registra autorização/arquivos na Tuffer.
- `external`: ERP/API da loja recebe o aviso da Tuffer e sincroniza autorização/cancelamento pela API autenticada da plataforma.
- `connector`: a Tuffer usa um conector nativo e a credencial daquela loja para sincronizar o pedido e acompanhar a NF-e no provedor. O primeiro conector suportado é `tiny`.

O valor legado `platform` não é suportado. A migration `038_remove_platform_fiscal_issuance.sql` converte dados antigos para `manual`. A migration `040_tiny_fiscal_connector.sql` adiciona o modo `connector` e as tabelas genéricas de configuração/execução dos conectores.

## Segurança

Certificado A1 e senha permanecem no emissor escolhido pela loja. O conector Tiny armazena somente o token da API da própria conta do vendedor, criptografado com AES-256-GCM usando chave derivada de `APP_KEY`.

Ao conectar Tiny, a Tuffer consulta as informações da conta e compara o CPF/CNPJ retornado pelo ERP com o documento fiscal cadastrado na `store`. Uma conta Tiny de outro estabelecimento é recusada.

A credencial da API `external` permanece separada: é um Bearer token da Tuffer armazenado somente como SHA-256. O webhook de saída também usa segredo HMAC independente.

XML e DANFE ficam em `storage/private/fiscal`. XML recebido precisa ser uma NF-e reconhecível, não pode declarar DTD/entidades e, quando contém chave em `infNFe/@Id`, ela deve coincidir com a chave registrada.

## Fluxo `manual`

1. Pagar.me confirma o pagamento.
2. A fila agenda `fiscal.sync_paid_order`.
3. O documento fica `awaiting_manual`.
4. A loja emite a NF-e no próprio sistema.
5. O vendedor registra número, série, chave e, opcionalmente, protocolo, XML e DANFE.
6. A NF-e aparece no pedido do cliente.

## Fluxo `external`

1. Pagar.me confirma o pagamento.
2. A Tuffer prepara o documento em `awaiting_external`.
3. A Tuffer envia `fiscal.seller_order.ready` ao webhook da loja.
4. O ERP usa o Bearer token da própria loja para consultar `/api/v1/fiscal/seller-orders/{code}/payload`.
5. O ERP emite no sistema fiscal do vendedor e devolve a autorização por `/authorize`.
6. A Tuffer valida, armazena e disponibiliza a NF-e.

Contrato detalhado: `docs/FISCAL_API.md`.

## Fluxo `connector` — Tiny/Olist ERP

1. A loja cadastra seus dados fiscais e conecta o token da própria conta Tiny em `/vendedor/fiscal/conectores/tiny`.
2. A Tuffer valida a conta e exige correspondência do CPF/CNPJ.
3. Após pagamento, `fiscal.sync_paid_order` prepara o `fiscal_document` e `FiscalConnectorManager` agenda `fiscal.process_connector`.
4. O conector pesquisa o pedido pelo `seller_order.code` antes de criar. Se já existir, reutiliza o pedido do Tiny.
5. Se necessário, cria o pedido no Tiny com `numero_pedido_ecommerce` igual ao código do `seller_order` e o marca como aprovado.
6. Pesquisa uma NF-e pelo mesmo identificador antes de gerar outra. Se não existir, gera a NF-e a partir do pedido.
7. Com `auto_emit=1`, solicita a emissão no Tiny. Com `auto_emit=0`, aguarda o vendedor emitir dentro do Tiny e permite reprocessamento manual.
8. Situações rejeitada ou denegada ficam como pendência para revisão da loja; não são tratadas como autorização.
9. Enquanto a autorização ainda estiver em processamento, a fila reconsulta com retry. Esse estado não é registrado como falha da conexão.
10. Quando o Tiny retorna situação autorizada, chave de acesso válida, número e série, a Tuffer registra o documento usando a mesma camada idempotente do módulo fiscal.
11. XML retornado pelo Tiny é armazenado automaticamente. O `link_acesso`/`link_nfe` fica registrado no histórico operacional do conector para acesso do vendedor.

A documentação do Tiny define o link retornado como acesso à NF-e, não como um PDF DANFE garantido. Por isso o conector não grava esse URL como `danfe_storage_path`.

Detalhes: `docs/FISCAL_TINY.md`.

## Dados do intermediador

O Tiny aceita dados do intermediador do marketplace no pedido/nota. A Tuffer não inventa esses dados. Em produção, eles devem ser definidos com os dados jurídicos corretos da plataforma:

```env
FISCAL_INTERMEDIARY_NAME=
FISCAL_INTERMEDIARY_DOCUMENT=
FISCAL_PAYMENT_INTERMEDIARY_DOCUMENT=
```

`FISCAL_PAYMENT_INTERMEDIARY_DOCUMENT` é opcional e deve ser validado conforme o arranjo de pagamento usado na operação.

## Dados fiscais de produto

`product_fiscal_profiles` é referência opcional para integrações/exportações. A Tuffer não calcula ICMS, PIS, COFINS, IBS/CBS ou decide CFOP para o vendedor. As regras fiscais efetivas permanecem no sistema fiscal da loja.

## Reembolso

Reembolso nunca cancela automaticamente uma NF-e autorizada. O documento fica marcado para revisão para que a loja decida, no próprio emissor, sobre cancelamento, devolução ou outro evento fiscal.

## Reconciliação

```bash
php scripts/sync-fiscal-paid-orders.php 200
```

O comando reconcilia documentos, webhooks `external` e conectores `connector` de forma idempotente.

## Requisitos operacionais

- `APP_KEY` configurada e estável para proteger segredos de webhook e credenciais de conectores.
- worker consumindo a fila `fiscal`.
- perfil fiscal individual por loja.
- para Tiny: token API da conta correta da loja e configurações fiscais válidas dentro do próprio Tiny.
- para `external`: Bearer token e webhook HTTPS da loja.
- dados jurídicos do intermediador revisados antes de produção.

## Próximas evoluções

1. Homologação real do conector Tiny com uma conta de testes/produção controlada e uma NF-e de teste permitida pela operação.
2. Confirmar se o fluxo da conta Tiny usada por Tuffer/Linda Flor exige configurações específicas por causa do FFAdmin existente.
3. Download automático de DANFE apenas quando houver endpoint/documento oficial que garanta PDF.
4. Adicionar novos conectores nativos (Bling etc.) reaproveitando `store_fiscal_connector_configs`, `fiscal_connector_runs` e `FiscalConnectorManager`.
5. Monitoramento/SLA de NF-e pendentes por loja.
