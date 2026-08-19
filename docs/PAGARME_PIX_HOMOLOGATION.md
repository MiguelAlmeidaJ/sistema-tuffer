# Homologação Pagar.me Orders Pix com split

## Estado seguro padrão

Orders Pix só é selecionado quando **todas** as condições abaixo forem verdadeiras:

```dotenv
PAGARME_ORDERS_PIX_ENABLED=true
PAGARME_SPLIT_ENABLED=true
PAGARME_SPLIT_ALLOWED_SELLERS=10,20
PAGARME_PLATFORM_RECIPIENT_ID=re_...
```

O método precisa ser Pix e todos os vendedores do carrinho precisam estar na lista permitida. Qualquer ausência, valor inválido ou vendedor fora da lista mantém o fluxo `payment_link`. O arquivo `.env` real não é modificado pela implantação.

`PAGARME_CHECKOUT_MODE` é mantido somente por compatibilidade operacional. Ele não habilita Orders Pix.

## Diagnóstico somente leitura

```powershell
php scripts/diagnose-pagarme-platform.php
```

O comando:

- valida o formato de `PAGARME_PLATFORM_RECIPIENT_ID`;
- executa apenas `GET /core/v5/recipients/{recipient_id}`;
- confirma `recipient.status`, `kyc_details.status` e a disponibilidade do recipient no ambiente autenticado;
- mascara o recipient;
- não imprime chaves, documentos, contas bancárias ou payload integral;
- não realiza POST, PUT, PATCH ou DELETE na Pagar.me.

Um recipient só pode ser localizado pela credencial do mesmo ambiente. Por isso, a resposta autenticada com ID idêntico é usada como confirmação de correspondência de ambiente.

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

Sugestão de agendamento durante a homologação: a cada cinco minutos, com limite baixo. Em produção, definir frequência conforme volume e limites contratados da API.

## Idempotência do checkout

- o navegador desabilita o botão após o primeiro submit;
- o carrinho é bloqueado no banco durante a conversão;
- o job possui chave única por pagamento;
- `pagarme_order_attempts` possui uma tentativa única por pagamento e uma chave única por tentativa;
- uma trava com expiração impede dois workers simultâneos;
- antes de POST `/orders`, o sistema busca um pedido remoto com o mesmo `code`;
- uma falha depois do POST deixa a tentativa como `uncertain`, permitindo recuperação sem criar outra cobrança;
- o mesmo `Idempotency-Key` é reutilizado nas retentativas.

## Snapshot financeiro

As tabelas `payment_financial_snapshot_lines` e `payment_financial_snapshot_coupons` guardam:

- subtotal e descontos por vendedor;
- cada cupom, sua origem e o valor em centavos;
- frete e destinatário financeiro do frete;
- comissão em basis points e em centavos;
- líquido do vendedor e contribuição da plataforma;
- recipient do vendedor;
- versão da política;
- responsabilidade, taxa de processamento e centavos residuais.

Triggers impedem UPDATE e DELETE desses registros. A política atual é `marketplace-split-v2`.

## Pix

- QR Code, copia-e-cola e expiração são persistidos;
- Pix expirado/cancelado deixa de ser exibido como pagável;
- status é atualizado por webhook ou reconciliação autenticada;
- estorno integral usa `DELETE /core/v5/charges/{charge_id}` com o split imutável original;
- a cobrança paga correta é selecionada entre múltiplos `charge_id`;
- estorno parcial está estruturado, mas lança bloqueio explícito até homologação do recálculo do split;
- a tela administrativa do pedido expõe a ação de estorno integral somente para Pix Orders pago.

## Painel administrativo

A rota `/admin/diagnostico/pagarme` exibe:

- modo atual;
- recipient da plataforma mascarado;
- vendedores elegíveis e quantidade na allowlist;
- Pix pendentes;
- falhas recentes de webhook;
- divergências abertas;
- última execução do reconciliador.

Nenhuma chave, documento, dado bancário ou payload integral é renderizado.

## Checklist — primeira cobrança no sandbox

- [ ] Usar uma chave `sk_test_` e confirmar que o diagnóstico mostra `key_environment: test`.
- [ ] Configurar um `PAGARME_PLATFORM_RECIPIENT_ID` do mesmo sandbox.
- [ ] Executar o diagnóstico e exigir `ok: true`, recipient `active` e KYC `approved`.
- [ ] Confirmar a habilitação contratual de PSP/Marketplace e split na conta de teste.
- [ ] Escolher um vendedor sandbox com recipient `active` + KYC `approved`.
- [ ] Adicionar somente o ID desse vendedor a `PAGARME_SPLIT_ALLOWED_SELLERS`.
- [ ] Manter `PAGARME_ORDERS_PIX_ENABLED=false` e `PAGARME_SPLIT_ENABLED=false` até concluir os itens anteriores.
- [ ] Habilitar primeiro `PAGARME_SPLIT_ENABLED=true` e depois `PAGARME_ORDERS_PIX_ENABLED=true`.
- [ ] Fazer um pedido de baixo valor com um vendedor e conferir soma de itens + frete = cobrança = split.
- [ ] Confirmar QR Code, copia-e-cola e expiração na conta do cliente.
- [ ] Simular pagamento no sandbox e aguardar webhook; não alterar status diretamente no banco.
- [ ] Conferir `order_id`, todos os `charge_id`, `transaction_id` e `gateway_id`.
- [ ] Reenviar o mesmo webhook e confirmar resultado duplicado sem novo efeito.
- [ ] Executar a reconciliação e confirmar ausência de divergências.
- [ ] Repetir com dois vendedores e valores com centavos.
- [ ] Testar cupom do vendedor, cupom da plataforma e as duas políticas de frete.
- [ ] Testar expiração e reprocessamento que gere novo `charge_id`.
- [ ] Testar estorno integral e confirmar o `charge_id` pago correto.
- [ ] Desabilitar novamente Orders Pix após a janela de homologação se houver qualquer divergência.

## Checklist — ativação em produção

- [ ] Encerrar todos os cenários de sandbox sem divergências abertas.
- [ ] Revisar contrato Pagar.me para Marketplace/PSP, split, taxas e responsabilidade financeira.
- [ ] Usar credencial de produção em cofre de segredos; nunca versionar ou exibir a chave.
- [ ] Configurar recipient da plataforma de produção e executar o diagnóstico.
- [ ] Exigir recipient da plataforma `active`, KYC `approved` e ambiente `production`.
- [ ] Confirmar webhook HTTPS, segredo válido, assinatura e retentativas.
- [ ] Agendar reconciliador e monitorar sua última execução.
- [ ] Revisar allowlist inicial e ativar poucos vendedores por vez.
- [ ] Confirmar que todos os vendedores permitidos estão `active` + KYC `approved`.
- [ ] Manter Payment Link como fallback durante a ativação gradual.
- [ ] Fazer a primeira cobrança real de menor valor possível com acompanhamento administrativo.
- [ ] Conferir split na Pagar.me antes de liberar expedição.
- [ ] Confirmar status pago exclusivamente via webhook/reconciliação.
- [ ] Validar um estorno integral acompanhado.
- [ ] Revisar logs sanitizados, falhas de webhook e divergências após a primeira janela.
- [ ] Validar antes de 28/08/2026 que integrações e relatórios aceitam `gateway_id` alfanumérico e novos `charge_id` em reprocessamentos.
- [ ] Manter estorno parcial desabilitado até homologação específica.

## Referências oficiais

- Listar pedidos por código: <https://docs.pagar.me/reference/listar-pedidos>
- Obter pedido: <https://docs.pagar.me/reference/obter-pedido>
- Obter cobrança: <https://docs.pagar.me/reference/obter-cobran%C3%A7a>
- Cancelar/estornar cobrança: <https://docs.pagar.me/reference/cancelar-cobran%C3%A7a>
- Cancelar cobrança com split: <https://docs.pagar.me/reference/cancelar-cobran%C3%A7a-com-split-1>
- Obter recebedor: <https://docs.pagar.me/reference/obter-recebedor-1>
- Pix: <https://docs.pagar.me/reference/pix-2>
