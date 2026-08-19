# Arquitetura financeira da Tuffer

## Objetivo e limites

A Tuffer usa um recebedor Pagar.me global para duas atividades economicamente distintas:

- loja oficial: venda própria da Tuffer;
- plataforma: comissão, taxas de serviço e subsídios do marketplace.

Vendedores externos continuam usando seus próprios recebedores aprovados. O banco da Pagar.me mostra o total do recebedor global; o livro financeiro interno explica a composição desse total.

Esta fase não ativa cartão, não altera o modo atual do checkout, não faz cobrança e não executa transferência bancária. `payment_link` continua disponível como fallback. Transferências automáticas não existem.

## Princípio: detalhar primeiro, consolidar depois

O fluxo é:

1. o pedido persiste os valores finais;
2. o snapshot financeiro copia esses valores, a política, custos e recipients;
3. o livro cria créditos e débitos detalhados por centro financeiro;
4. somente os lançamentos marcados como componentes do split são agrupados por `recipient_id`;
5. a soma consolidada é validada contra a cobrança;
6. a Pagar.me recebe no máximo uma regra de split por recipient.

Assim, receita da loja oficial e receita da plataforma permanecem separadas internamente, embora ambas sejam consolidadas em `PAGARME_PLATFORM_RECIPIENT_ID`.

## Loja oficial e conta global

`sellers.is_official_store` identifica explicitamente a loja oficial. Não há identificação por nome, slug, e-mail ou ID fixo. Uma coluna gerada com índice único impede duas lojas oficiais ativas.

`OfficialStoreResolver` localiza e valida a loja, verifica a existência de loja operacional ativa e sempre retorna o recipient global configurado.

`marketplace_payment_accounts` guarda somente o estado operacional não sensível da conta global:

- ambiente;
- recipient;
- status;
- status do KYC;
- habilitação;
- banco e conta mascarados;
- resultado e data da última sincronização.

A loja oficial não cria `seller_payment_accounts`, recebedor ou KYC próprios. O ID global é espelhado no seller apenas para compatibilidade com consultas legadas de catálogo; a fonte de verdade continua sendo a conta global.

## Elegibilidade

Vendedor externo:

- seller e ao menos uma loja ativos;
- recipient próprio preenchido;
- recipient ativo;
- KYC aprovado;
- conta habilitada para venda.

Loja oficial:

- seller e ao menos uma loja ativos;
- integração global Pagar.me habilitada e configurada;
- recipient global igual ao configurado;
- status remoto sincronizado como `active`;
- KYC sincronizado como `approved`;
- conta global habilitada.

A elegibilidade é verificada no catálogo/carrinho e novamente imediatamente antes da criação do pedido remoto. O segundo controle consulta a Pagar.me.

Sincronização segura:

```bash
php scripts/diagnose-pagarme-platform.php
php scripts/sync-pagarme-platform-account.php
```

O primeiro comando é totalmente read-only. O segundo faz somente GET remoto e atualiza o status sanitizado local. Nenhum deles cria cobrança ou modifica dados na Pagar.me.

## Snapshot financeiro

`payment_financial_snapshot_lines`, `payment_financial_snapshot_items` e `payment_financial_snapshot_coupons` preservam:

- seller, tipo e indicação de loja oficial;
- recipient;
- produtos, descontos e origem de cupons;
- frete e seu destinatário financeiro;
- comissão percentual e em centavos;
- taxa fixa e taxa esperada do provedor;
- imposto provisionado;
- custo unitário/total conhecido ou ausente;
- reserva e valor transferível;
- versão, data e regras de responsabilidade da política.

Triggers proíbem edição e exclusão. Mudanças posteriores de comissão, custo, frete ou política não afetam pedidos antigos.

## Livro financeiro

`financial_entries` é o livro imutável em centavos. Os centros são:

- `official_store`;
- `marketplace`;
- `external_seller`;
- `payment_provider`;
- `shipping`;
- `tax`;
- `reserve`.

Os lançamentos nascem `pending`, tornam-se `confirmed` quando a Pagar.me confirma o pagamento e viram `void` se o Pix falhar, expirar ou for cancelado. Estorno e chargeback criam lançamentos reversos ligados ao original; nada é apagado.

A chave natural e a chave de idempotência impedem repetição por pedido, tipo, seller, origem e sequência. A versão da política copiada do snapshot também é copiada para o livro.

O livro separa faturamento, receita, custo, despesa, lucro estimado, saldo disponível e valor transferível. “Faturamento” nunca é tratado como “lucro”.

## Custos e lucro estimado

O custo atual permanece em `product_variants.cost_price`, e alterações são registradas em `product_cost_history`. No pedido, custo unitário e total são copiados para o snapshot.

Se qualquer item oficial não possuir custo, o relatório mostra `Não calculado`. O sistema não assume zero. A ausência de custo não bloqueia venda nesta versão.

## Split

`MarketplaceFinancialLedgerService` gera os detalhes. `FinancialSplitConsolidator` lê somente componentes de split pendentes/confirmados, agrupa por recipient e aplica as responsabilidades da plataforma.

Validações:

- valor inteiro positivo;
- nenhum recipient vazio ou inválido;
- nenhuma parcela zero/negativa;
- uma regra por recipient;
- soma exata igual à cobrança;
- snapshot anterior à chamada;
- revalidação remota de todos os recebedores.

## Webhooks, estorno e chargeback

Webhooks continuam sanitizados e idempotentes. Eventos duplicados ou antigos não recriam lançamentos.

- pagamento: confirma o livro;
- Pix expirado/falho: invalida somente lançamentos pendentes;
- estorno total: cria reversões com tipos de refund;
- chargeback: cria reversões com tipos de chargeback e bloqueia transferência do fechamento;
- reprocessamento: mantém todos os `charge_id` e usa o charge correto.

Estorno parcial continua estruturalmente preparado, mas desabilitado.

## Relatórios e painéis

`/admin/financeiro` possui:

- Loja oficial: faturamento, receita líquida, custo, taxas, imposto, reserva, transferências, lucro estimado e valor transferível.
- Plataforma: comissão, receita líquida, subsídios, taxas, chargebacks e reserva.
- Consolidado: composição do recipient, transferências, divergências e fechamentos pendentes.

`/admin/financeiro/fechamentos` lista, filtra, gera, revisa e aprova fechamentos.

## Fechamentos, reserva e valor transferível

Fechamentos são construídos exclusivamente a partir do livro confirmado/revertido. O cálculo conceitual da loja oficial é:

```text
receita líquida original
- custo dos produtos
- imposto provisionado
- estornos
- chargebacks
- reserva
+/- ajustes
- transferências anteriores
= valor transferível
```

A reserva é o maior valor entre percentual e mínimo fixo. As configurações ficam no painel e em `.env.example`; o `.env` real nunca é alterado pelo sistema.

Não é possível aprovar ou transferir quando há divergência crítica. Transferências também são bloqueadas por chargeback, fechamento não aprovado, saldo insuficiente, duplicidade e concorrência.

## Transferência manual

O registro manual apenas documenta uma movimentação que já ocorreu fora do sistema. Ele:

- exige habilitação explícita;
- bloqueia a linha do fechamento durante a operação;
- valida saldo;
- grava destino mascarado, referência e comprovante privado;
- cria `transfer_out`;
- atualiza o saldo restante;
- preserva histórico.

Nenhuma API bancária ou Pagar.me é chamada.

## Conciliação

```bash
# Somente verificações locais
php scripts/reconcile-marketplace-financial.php 100

# Inclui consultas GET à Pagar.me
php scripts/reconcile-marketplace-financial.php 100 --provider
```

`MarketplaceReconciliationService` compara pagamento, pedido, cobranças, split e estados remotos. Divergências são registradas em `financial_reconciliation_issues` e nunca corrigem silenciosamente lançamentos confirmados.

## Segurança

- chaves, CPF/CNPJ e dados bancários completos nunca são registrados;
- conta e destino são sempre mascarados;
- comprovantes ficam em armazenamento privado com nomes aleatórios;
- lançamentos e fechamentos não são apagados;
- correções financeiras usam reversão/ajuste;
- cartão permanece bloqueado;
- Payment Link permanece como fallback;
- Orders Pix e transferências não são ativados automaticamente.

## Homologação

1. Aplicar migrations e executar a suíte.
2. Selecionar explicitamente a loja oficial no painel financeiro.
3. Configurar credenciais sandbox e recipient global sandbox fora do código.
4. Manter `OFFICIAL_STORE_TRANSFER_ENABLED=false`.
5. Executar o diagnóstico read-only.
6. Sincronizar a conta global e validar `active/approved`.
7. Testar pedido oficial, externo e misto em sandbox.
8. Conferir snapshot, livro detalhado e split consolidado.
9. Simular pagamento, expiração, estorno, chargeback, duplicidade e reprocessamento.
10. Executar conciliação com `--provider`.
11. Gerar fechamento de teste, revisar e aprovar.
12. Não registrar transferência até validar o procedimento administrativo.

## Checklist de produção

- backup e plano de rollback operacional;
- migrations aplicadas e checksum intacto;
- loja oficial escolhida explicitamente, sem segunda marcação;
- `PAGARME_PLATFORM_RECIPIENT_ID` de produção validado;
- chave e recipient no mesmo ambiente;
- status `active`, KYC `approved`, sincronização recente;
- fallback Payment Link testado;
- Orders Pix mantido desligado até decisão formal;
- nenhuma configuração de cartão;
- custos dos produtos oficiais revisados;
- reserva percentual e mínima aprovadas;
- conciliação sem divergência crítica;
- webhooks de produção validados;
- alertas e rotina de sincronização agendados;
- transferência manual ainda desabilitada, salvo aprovação operacional formal;
- primeira venda acompanhada até conciliação e fechamento.
