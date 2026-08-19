# Pagar.me Orders com split

## Escopo implantado

O checkout possui dois modos:

- Sem as três flags explícitas de homologação: fluxo legado integral por Payment Link.
- Com `PAGARME_ORDERS_PIX_ENABLED=true`, `PAGARME_SPLIT_ENABLED=true` e todos os vendedores presentes em `PAGARME_SPLIT_ALLOWED_SELLERS`: Pix usa `POST /core/v5/orders` com split; cartão e boleto continuam no Payment Link.

O modo `orders_pix_limited` exige `PAGARME_PLATFORM_RECIPIENT_ID`. A conta Pagar.me também precisa estar contratualmente habilitada para PSP/Marketplace e split. `PAGARME_CHECKOUT_MODE` não habilita o fluxo sozinho.

## Política financeira v1

A política está centralizada em `MarketplaceFinancialPolicy`:

- comissão sobre produtos após descontos;
- frete fora da base percentual e destinado ao vendedor;
- cupom do vendedor reduz o líquido do vendedor;
- cupom da plataforma reduz a parcela da Tuffer;
- taxa de processamento, responsabilidade e centavos residuais ficam com a plataforma;
- split fixo (`flat`) e sempre em centavos.

Cupons criados pelo painel do vendedor são financiados pelo vendedor. Uma campanha financiada pela plataforma deve ser criada por fluxo administrativo confiável com `coupons.funding_source=platform`. Se o desconto da plataforma superar toda a receita da Tuffer no pedido, o checkout é bloqueado porque a API não aceita uma parcela negativa.

## Snapshot imutável

O pedido persiste os valores comerciais primeiro. Depois, dentro da mesma transação, `PagarmeSplitService` grava `payment_split_snapshots`, agregando lojas do mesmo vendedor em uma única entrada.

O worker só lê esse snapshot; comissão e split não são recalculados. Triggers impedem `UPDATE` e `DELETE`. Antes de chamar a Pagar.me, todos os recebedores são sincronizados novamente e precisam permanecer com:

- `recipient.status=active`;
- `kyc_details.status=approved`;
- o mesmo `recipient_id` gravado no snapshot.

## Idempotência e cobranças

- checkout: `payments.idempotency_key`, job único e header `Idempotency-Key`;
- pedido externo: `pagarme_orders.external_order_id` e `idempotency_key` únicos;
- webhook: `provider_event_id` único e conflito detectado pelo SHA-256;
- cobrança: cada `charge_id` possui uma linha própria em `pagarme_charges`.

Um reprocessamento pode criar outro `charge_id` para o mesmo `order_id`. O registro anterior não é sobrescrito. `charge_id`, `transaction_id`, `order_id` e `gateway_id` são strings; os dois `gateway_id` aceitam valores alfanuméricos de até 128 caracteres.

## Webhooks

Além dos eventos de recebedor, habilite:

- `order.created`, `order.updated`, `order.closed`, `order.paid`, `order.payment_failed`, `order.canceled`;
- `charge.created`, `charge.updated`, `charge.pending`, `charge.processing`, `charge.paid`, `charge.payment_failed`, `charge.refunded`;
- `chargeback.received`.

`charge.chargedback` permanece aceito temporariamente durante a migração indicada pela Pagar.me. Os payloads persistidos são minimizados e não contêm cliente, documento, banco, cartão nem QR Pix completo.

## Cartão e boleto

Os DTOs aceitam a evolução dos meios de pagamento, mas a API de Pedidos está habilitada apenas para Pix nesta fase. O backend rejeita o ponto de entrada de cartão até existir uma estratégia aprovada de `card_id`/tokenização e conformidade PCI. Boleto permanece no fallback.

## Ativação em sandbox

1. Aplicar as migrations.
2. Configurar chave `sk_test_`, `PAGARME_PLATFORM_RECIPIENT_ID` e webhook.
3. Confirmar que vendedores do carrinho estão ativos e aprovados no ambiente de teste.
4. Incluir os vendedores de teste em `PAGARME_SPLIT_ALLOWED_SELLERS`.
5. Habilitar explicitamente `PAGARME_SPLIT_ENABLED=true` e `PAGARME_ORDERS_PIX_ENABLED=true`.
5. Manter o worker e `scripts/expire-pending-orders.php` agendados.
6. Testar criação, pagamento, expiração, falha, estorno e reprocessamento com outro `charge_id`.

Referências oficiais:

- https://docs.pagar.me/reference/criar-pedido-com-split-1
- https://docs.pagar.me/reference/pix-2
- https://docs.pagar.me/reference/eventos-de-webhook-1
- https://docs.pagar.me/docs/pagamentos
- https://docs.pagar.me/docs/mudan%C3%A7as-de-apis
