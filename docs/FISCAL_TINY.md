# Conector fiscal Tiny / Olist ERP

Este conector usa a API 2.0 do Tiny/Olist ERP com a credencial da própria loja. A Tuffer orquestra o pedido e acompanha a NF-e, mas não assina XML, não armazena certificado A1 e não transmite diretamente à SEFAZ.

## Preparação da loja

1. Cadastre e salve o perfil fiscal da `store` na Tuffer.
2. Confirme que o mesmo CPF/CNPJ está configurado na conta Tiny que será conectada.
3. Gere/obtenha o Token API da conta Tiny.
4. Acesse `/vendedor/fiscal/conectores/tiny`, informe o token e teste a conexão.
5. Mantenha `APP_KEY` estável no servidor; a credencial Tiny é criptografada com AES-256-GCM.
6. Antes de produção, valide os dados do intermediador da Tuffer em `FISCAL_INTERMEDIARY_*`.

A conexão é por loja. Duas lojas do mesmo vendedor podem ter contas Tiny diferentes sem compartilhar credenciais.

## Endpoints utilizados

| Objetivo | API Tiny/Olist |
| --- | --- |
| Validar conta | `POST /api2/info.php` |
| Procurar pedido do marketplace | `POST /api2/pedidos.pesquisa.php` com `numeroEcommerce` |
| Criar pedido | `POST /api2/pedido.incluir.php` |
| Aprovar pedido | `POST /api2/pedido.alterar.situacao` |
| Procurar NF-e | `POST /api2/notas.fiscais.pesquisa.php` com `numeroEcommerce` |
| Gerar NF-e do pedido | `POST /api2/gerar.nota.fiscal.pedido.php` |
| Emitir NF-e | `POST /api2/nota.fiscal.emitir.php` |
| Obter NF-e | `POST /api2/nota.fiscal.obter.php` |
| Obter link da NF-e | `POST /api2/nota.fiscal.obter.link.php` |

Todas as chamadas incluem `token` e `formato=JSON`. O token nunca vai em URL/query gerada pela Tuffer nem é gravado em logs do módulo fiscal.

## Idempotência

O identificador externo é o `seller_order.code`.

Antes de criar um pedido, o conector pesquisa `numeroEcommerce=<seller_order.code>`. Antes de gerar a NF-e, pesquisa o mesmo identificador nas notas. Os IDs retornados pelo Tiny também são persistidos em `fiscal_connector_runs`.

Isso permite repetir um job depois de timeout sem criar deliberadamente uma segunda venda ou uma segunda NF-e.

A camada final de `fiscal_documents` continua bloqueando substituição silenciosa de uma NF-e já autorizada e reutilização da mesma chave em outro pedido.

## Fluxo automático

```text
pagamento aprovado
  -> fiscal.sync_paid_order
  -> FiscalConnectorManager
  -> fiscal.process_connector
  -> pesquisar/criar pedido no Tiny
  -> aprovar pedido
  -> pesquisar/gerar NF-e
  -> emitir NF-e (quando auto_emit=1)
  -> consultar situação
  -> autorização confirmada
  -> registrar chave + número + série + XML na Tuffer
```

Se a autorização estiver apenas em processamento, o job falha de forma recuperável para que a fila aplique retry. Esse estado não marca a conta Tiny como indisponível.

Se `auto_emit=0`, o conector para em `awaiting_manual_emission`. O vendedor emite a nota na própria conta Tiny e usa `Reprocessar` para a Tuffer consultar novamente.

## Situações fiscais

O conector trata situação `6` como autorizada. Situações `5` (rejeitada) e `10` (denegada) encerram a execução como pendência que exige revisão no Tiny.

Outras situações não são promovidas para `authorized` apenas por existência de número de nota. A autorização exige situação autorizada ou evidência de protocolo no XML, além de uma chave de acesso de 44 dígitos.

## Dados enviados

A integração envia somente os dados do `seller_order` da loja autenticada/configurada:

- código do `seller_order` como número do pedido de e-commerce;
- cliente e CPF/CNPJ quando disponível;
- endereço de entrega;
- itens da própria loja, SKU, quantidade e preço;
- frete e desconto daquela fatia da compra;
- identificação `Tuffer` como origem do e-commerce;
- dados do intermediador somente quando configurados no ambiente.

A Tuffer não envia itens de outras lojas que façam parte do mesmo `order` agregado.

## Produtos e tributação

Os campos fiscais mantidos na Tuffer são referência. A criação do pedido envia os itens comerciais; configuração tributária, natureza de operação, NCM efetivo, CFOP, regras de ICMS/PIS/COFINS/IBS/CBS e emissão são responsabilidade da configuração da conta Tiny da loja.

Antes da homologação real é necessário validar que os SKUs/produtos recebidos pela conta Tiny encontram a configuração fiscal esperada, principalmente no cenário em que FFAdmin e Tiny já operam em paralelo.

## XML, link e DANFE

A API de emissão pode retornar XML e `link_acesso`. O XML autorizado é validado e armazenado em `storage/private/fiscal`.

O link retornado é salvo como metadado operacional (`provider_document_url`) e só é apresentado no painel do vendedor quando aponta para `https://tiny.com.br/`.

O conector não assume que esse link é um PDF DANFE. `danfe_storage_path` só deve receber bytes validados como PDF.

## Erros e reprocessamento

- erro de autenticação/conexão: registrado na configuração do conector;
- pesquisa sem registros (código 20): considerada resultado vazio normal;
- processamento fiscal ainda pendente: retry pela fila;
- rejeição/denegação: requer ação da loja;
- reprocessamento manual: cria um novo job com chave de idempotência própria, reutilizando IDs Tiny já conhecidos.

## Homologação antes do uso real

O CI valida sintaxe, migrations e o contrato do cliente Tiny com transporte simulado. Isso não substitui uma emissão real.

Antes de ativar `auto_emit` em produção, faça um teste controlado com a conta Tiny correta da loja, confirme configuração tributária, série, natureza de operação, intermediador, fluxo já existente com FFAdmin e comportamento de estoque/financeiro no Tiny.
