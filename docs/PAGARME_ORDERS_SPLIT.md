# Pagar.me Orders com split

## Escopo implantado

O checkout mantém flags globais no ambiente e obtém a elegibilidade de cada seller automaticamente do banco. Não existe mais lista manual de IDs em `.env`.

- `PAGARME_SPLIT_ENABLED=true` habilita a infraestrutura global de split.
- `PAGARME_PLATFORM_RECIPIENT_ID` identifica o recebedor da plataforma.
- `PAGARME_ORDERS_PIX_ENABLED=true` habilita Pix via Orders.
- Cartão direto exige também `PAGARME_PUBLIC_KEY` válida para tokenização segura no navegador.

Um seller só participa de uma cobrança com split quando o cadastro comercial e a conta Pagar.me estiverem aptos: seller ativo, loja ativa, pagamentos habilitados, onboarding ativo, recipient válido, recipient ativo, KYC elegível e `enabled_for_sales=1`. Essa verificação é feita por `SellerSalesEligibility` e é repetida antes da cobrança por `PagarmeSplitService`.

A loja oficial usa a conta Pagar.me da plataforma conforme as regras de elegibilidade próprias. `PAGARME_CHECKOUT_MODE` não substitui essas validações.

## Política financeira

A política está centralizada em `MarketplaceFinancialPolicy`:

- comissão sobre produtos após descontos;
- frete conforme a política financeira configurada;
- cupom do vendedor reduz o líquido do vendedor;
- cupom da plataforma reduz a parcela da Tuffer;
- taxa de processamento, responsabilidade e centavos residuais seguem a política da plataforma;
- split fixo (`flat`) e sempre em centavos.

Cupons criados pelo painel do vendedor são financiados pelo vendedor. Uma campanha financiada pela plataforma deve ser criada por fluxo administrativo confiável com `coupons.funding_source=platform`. Se o desconto da plataforma superar a receita disponível da Tuffer no pedido, o checkout é bloqueado para impedir parcela negativa.

## Snapshot imutável

O pedido persiste os valores comerciais primeiro. Depois, `PagarmeSplitService` grava os snapshots financeiros, agregando lojas do mesmo vendedor quando necessário.

O processamento usa esse snapshot; comissão e split não são recalculados livremente. Antes do request transacional, os recebedores são revalidados e precisam continuar elegíveis, incluindo recipient correto e KYC/status aceitos.

## Idempotência e cobranças

- checkout: `payments.idempotency_key`, job único e header `Idempotency-Key` quando aplicável;
- pedido externo: `pagarme_orders.external_order_id` e `idempotency_key` únicos;
- webhook: `provider_event_id` único e conflito detectado pelo SHA-256;
- cobrança: cada `charge_id` possui uma linha própria em `pagarme_charges`;
- tentativas de Orders usam coordenação idempotente e recuperação de estado incerto.

Um reprocessamento pode produzir outro `charge_id` para o mesmo `order_id`. O registro anterior não é sobrescrito.

## Webhooks

Além dos eventos de recebedor, habilite os eventos de pedido e cobrança usados pela integração, incluindo pagamento, falha, cancelamento, reembolso e chargeback. Os payloads persistidos são sanitizados e não devem armazenar PAN, CVV, documentos completos ou dados bancários desnecessários.

## Cartão

O cartão é preenchido no checkout, mas PAN e CVV não são enviados ao backend da Tuffer. O navegador tokeniza o cartão diretamente com a Pagar.me usando somente a chave pública. O backend recebe o token temporário, cria/obtém o cartão seguro na Pagar.me e envia a cobrança com `card_id`, parcelas e split.

A opção de cartão só é exibida quando as configurações globais estão válidas e todos os sellers do carrinho passam pela elegibilidade dinâmica do banco.

## Ativação em sandbox

1. Aplicar as migrations e configurar a Pagar.me de teste.
2. Configurar `PAGARME_PLATFORM_RECIPIENT_ID`, chave pública, chave secreta e webhook.
3. Concluir o onboarding Pagar.me dos sellers de teste e confirmar recipient/KYC/habilitação para vendas.
4. Habilitar `PAGARME_SPLIT_ENABLED=true`.
5. Para Pix Orders, habilitar também `PAGARME_ORDERS_PIX_ENABLED=true`.
6. Manter worker e rotinas de expiração/reconciliação ativos.
7. Testar criação, pagamento, expiração, falha, estorno, cartão parcelado e cenários com múltiplos sellers.

Não é necessário editar `.env` quando um novo seller termina o onboarding: o próprio estado persistido da conta de pagamento passa a controlar a elegibilidade.

Referências oficiais:

- https://docs.pagar.me/reference/criar-pedido-com-split-1
- https://docs.pagar.me/reference/pix-2
- https://docs.pagar.me/reference/eventos-de-webhook-1
- https://docs.pagar.me/docs/pagamentos
