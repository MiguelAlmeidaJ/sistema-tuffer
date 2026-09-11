# Homologação Pagar.me Orders Pix com split

## Estado seguro padrão

Orders Pix só é selecionado quando as configurações globais estão válidas:

```dotenv
PAGARME_ORDERS_PIX_ENABLED=true
PAGARME_SPLIT_ENABLED=true
PAGARME_PLATFORM_RECIPIENT_ID=re_...
```

Não existe allowlist de seller no `.env`. A elegibilidade é dinâmica e vem do onboarding e do estado Pagar.me persistido no banco. Para participar de uma cobrança, cada seller do carrinho precisa estar comercialmente ativo e apto a receber pagamentos, incluindo recipient válido, status/KYC elegíveis e habilitação para vendas. O `OrderPlacementService`, `SellerSalesEligibility` e `PagarmeSplitService` aplicam essas barreiras antes da cobrança.

`PAGARME_CHECKOUT_MODE` é mantido por compatibilidade operacional. Ele não habilita Orders Pix sozinho.

## Diagnóstico somente leitura

```powershell
php scripts/diagnose-pagarme-platform.php
```

O comando:

- valida o formato de `PAGARME_PLATFORM_RECIPIENT_ID`;
- executa apenas `GET /core/v5/recipients/{recipient_id}`;
- confirma `recipient.status`, estado de KYC e a disponibilidade do recipient no ambiente autenticado;
- mascara o recipient;
- não imprime chaves, documentos, contas bancárias ou payload integral;
- não realiza mutações na Pagar.me.

O painel administrativo também mostra quantos sellers estão elegíveis segundo o estado persistido, em vez de comparar IDs com uma configuração manual.

## Reconciliação

```powershell
php scripts/reconcile-pagarme-orders.php 100
```

O sincronizador consulta pedidos Orders/Pix que ainda exigem acompanhamento. Quando o `order_id` local está ausente, procura o pedido remoto pelo `code`, valida valor e código, obtém os detalhes por ID e recupera a persistência.

Regras:

- somente respostas autenticadas da Pagar.me podem produzir transição para pago;
- `charge_id` antigos nunca são apagados ou substituídos;
- cada charge é persistido separadamente;
- webhooks sintéticos da reconciliação têm ID determinístico e são idempotentes;
- eventos antigos da mesma cobrança são ignorados;
- valor, código ou estado incompatível gera uma divergência administrativa;
- cada execução fica registrada em `pagarme_reconciliation_runs`.

## Idempotência do checkout

- o navegador desabilita o botão após o primeiro submit;
- o carrinho é bloqueado no banco durante a conversão;
- o job possui chave única por pagamento;
- `pagarme_order_attempts` coordena tentativas de Orders;
- uma trava com expiração impede workers concorrentes;
- antes de novo POST, o sistema pode localizar o pedido remoto pelo mesmo `code`;
- uma falha depois do POST deixa a tentativa como incerta para recuperação sem cobrança duplicada;
- a mesma chave idempotente é reutilizada nas retentativas compatíveis.

## Snapshot financeiro

As tabelas de snapshot financeiro guardam valores comerciais, descontos, frete, comissão, líquido, recipient e versão da política usada no momento da compra. Esses registros são protegidos contra alteração posterior e o split é validado contra o total da cobrança.

Antes do request transacional, a elegibilidade dos recebedores é validada novamente. Um seller que perder habilitação, recipient ou KYC deixa de poder participar de uma nova cobrança sem que seja necessário alterar `.env`.

## Pix

- QR Code, copia-e-cola e expiração são persistidos;
- Pix expirado/cancelado deixa de ser exibido como pagável;
- status é atualizado por webhook ou reconciliação autenticada;
- a cobrança paga correta é selecionada entre múltiplos `charge_id`;
- estornos seguem o snapshot financeiro original e as proteções de idempotência.

## Cartão

Cartão usa tokenização no navegador com `PAGARME_PUBLIC_KEY`; PAN e CVV não são enviados para a Tuffer. O backend recebe somente o token temporário e cria a cobrança com o cartão seguro da Pagar.me, parcelas e split.

Para disponibilizar cartão, configure globalmente `PAGARME_SPLIT_ENABLED=true`, uma public key válida e o recipient da plataforma. A liberação de cada seller acontece automaticamente quando o onboarding de pagamento fica elegível no banco.

## Painel administrativo

A rota `/admin/diagnostico/pagarme` exibe:

- modo atual;
- recipient da plataforma mascarado;
- quantidade de vendedores elegíveis dinamicamente;
- origem da elegibilidade como cadastro/onboarding;
- Pix pendentes;
- falhas recentes de webhook;
- divergências abertas;
- última execução do reconciliador.

Nenhuma chave, documento, dado bancário ou payload integral é renderizado.

## Checklist — primeira cobrança no sandbox

- [ ] Usar uma chave de teste e confirmar o ambiente no diagnóstico.
- [ ] Configurar um `PAGARME_PLATFORM_RECIPIENT_ID` do mesmo sandbox.
- [ ] Executar o diagnóstico e exigir recipient ativo/elegível.
- [ ] Confirmar a habilitação contratual de Marketplace e split na conta de teste.
- [ ] Concluir o onboarding de um seller de teste até que ele esteja habilitado para vendas e com recipient/KYC elegíveis.
- [ ] Manter `PAGARME_ORDERS_PIX_ENABLED=false` e `PAGARME_SPLIT_ENABLED=false` até concluir os itens anteriores.
- [ ] Habilitar `PAGARME_SPLIT_ENABLED=true` e, para Pix Orders, `PAGARME_ORDERS_PIX_ENABLED=true`.
- [ ] Fazer pedido de baixo valor e conferir soma de itens + frete = cobrança = split.
- [ ] Confirmar QR Code, expiração e atualização por webhook.
- [ ] Reenviar o mesmo webhook e confirmar idempotência.
- [ ] Executar reconciliação e confirmar ausência de divergências.
- [ ] Repetir com dois sellers elegíveis e valores com centavos.
- [ ] Testar seller que perde elegibilidade e confirmar bloqueio automático sem editar `.env`.

## Checklist — ativação em produção

- [ ] Encerrar cenários de sandbox sem divergências abertas.
- [ ] Revisar contrato Pagar.me para Marketplace/PSP, split, taxas e responsabilidade financeira.
- [ ] Usar credenciais de produção em cofre de segredos; nunca versioná-las ou exibi-las.
- [ ] Configurar recipient da plataforma e executar diagnóstico em produção.
- [ ] Confirmar webhook HTTPS, assinatura, retentativas e reconciliador.
- [ ] Ativar `PAGARME_SPLIT_ENABLED=true` somente após homologação.
- [ ] Liberar sellers pelo fluxo normal de onboarding; não manter IDs em variáveis de ambiente.
- [ ] Confirmar que sellers elegíveis estão ativos e com recipient/KYC/habilitação para vendas.
- [ ] Fazer a primeira cobrança real de menor valor possível com acompanhamento administrativo.
- [ ] Conferir split na Pagar.me antes de liberar expedição.
- [ ] Confirmar status pago exclusivamente por evento/reconciliação confiável.
- [ ] Revisar logs sanitizados, falhas de webhook e divergências após a primeira janela.

## Referências oficiais

- Listar pedidos por código: <https://docs.pagar.me/reference/listar-pedidos>
- Obter pedido: <https://docs.pagar.me/reference/obter-pedido>
- Obter cobrança: <https://docs.pagar.me/reference/obter-cobran%C3%A7a>
- Cancelar/estornar cobrança: <https://docs.pagar.me/reference/cancelar-cobran%C3%A7a>
- Obter recebedor: <https://docs.pagar.me/reference/obter-recebedor-1>
- Pix: <https://docs.pagar.me/reference/pix-2>
