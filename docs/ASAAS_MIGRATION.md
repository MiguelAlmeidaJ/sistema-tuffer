# Migração Tuffer → Asaas (lojas próprias)

**A operação de novos pagamentos permanece parada por padrão.** Atualizar o código **não** ativa cobranças: `ASAAS_CHECKOUT_ENABLED=false` é o valor recomendado até homologar o Sandbox. Não há fallback para a Pagar.me para **novos** pedidos. Pedidos antigos continuam com seu provedor registrado.

## Instalação

1. Atualize a branch `main` e execute `composer migrate` para a migração 047 (imagem Pix e idempotência de webhook).
2. Configure no `.env` do servidor:
   - `ASAAS_ENV=sandbox`
   - `ASAAS_API_KEY=` (chave secreta do Sandbox, somente no servidor)
   - `ASAAS_WEBHOOK_TOKEN=` (token forte, exclusivo do webhook, com 16+ caracteres)
   - `ASAAS_CHECKOUT_ENABLED=false`
3. No painel Asaas, configure um webhook HTTPS com URL `https://SEU_DOMINIO/webhooks/asaas` e **o mesmo token** da variável `ASAAS_WEBHOOK_TOKEN`. Assine os eventos de pagamentos.
4. Gere uma chave Pix na conta Asaas antes da homologação (o QR Code sem chave cadastrada pode ter validade limitada).
5. Valide cliente, emissão de cobrança Pix, QR e copia-e-cola, cartão via `invoiceUrl`, boleto via `invoiceUrl`, os eventos recebidos, pagamento duplicado, indisponibilidade, cancelamento, estorno e cenário de webhook repetido.
6. Apenas após homologar os fluxos, mude para `ASAAS_ENV=production` e a **respectiva chave de produção**; faça testes controlados e então defina `ASAAS_CHECKOUT_ENABLED=true`.

**Nunca coloque credenciais no Git, prints, scripts públicos ou browser.** O checkout não pede número de cartão: o cliente é encaminhado à página da fatura hospedada no Asaas.

## Estrutura da integração

- `POST /v3/customers` e `GET /v3/customers?externalReference=tuffer-user-ID` vinculam o cliente.
- `POST /v3/payments` cria cobrança avulsa por pedido usando `externalReference=order.code`, `billingType=PIX|BOLETO|CREDIT_CARD` e total em reais.
- `GET /v3/payments/{id}/pixQrCode` fornece código e imagem, exibidos no pedido dentro da Tuffer.
- `invoiceUrl` apresenta boleto/cartão e alternativa de consulta para Pix.
- `GET /v3/payments/{id}` autenticado é obrigatório antes de confirmar qualquer evento do webhook.
- O novo job `asaas.create_payment` tem chave única por pagamento; antes de criar, busca cobrança por referência. Um marcador persistente antes do POST **bloqueia nova criação automática se a resposta anterior for incerta**; isso exige revisão manual quando necessário.
- O evento é registrado na tabela `asaas_webhook_events` com chave única. Eventos RECEIVED/CONFIRMED, com valor, referência e ID íntegros, permitem liquidar o pedido e reservar a fila fiscal. Estornos e disputas são sinalizados como `review_required` para tratamento específico, não estornados automaticamente.
- Não existe split de marketplace nos novos pagamentos; o recebimento vai para a conta Asaas configurada. As estruturas legadas de vendedor/loja seguem para notas, estoque, frete e histórico.

## Pontos operacionais ainda a homologar

O Asaas deve ser habilitado para Pix, cartão e boleto em sua conta. Parcelamento no cartão não é oferecido pela aplicação nesta etapa: conferir política e taxas da fatura Asaas antes de ativá-lo. Eventos de estorno/disputa **não** implementam reversão automática de estoque ou financeiro; devem ser tratados com auditoria. Rotinas de expiração de pedidos existentes continuam operando e devem ser revisadas no Sandbox.

## Comandos

```bash
git pull origin main
composer migrate
composer test
```
