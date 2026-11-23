# Ambientes de pagamento e frete — Tuffer

O modo de testes não é `APP_DEBUG`. Deixe `APP_ENV=production` e `APP_DEBUG=false` no site público mesmo durante testes controlados.

## Operação real

No `.env` **privado da hospedagem** (não no `.env.example`):
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tuffer.com.br

ASAAS_ENV=production
ASAAS_API_KEY=COLE_A_CHAVE_DE_PRODUCAO_NO_SERVIDOR
ASAAS_WEBHOOK_TOKEN=COLE_O_TOKEN_DO_WEBHOOK_DE_PRODUCAO
ASAAS_CHECKOUT_ENABLED=true
ASAAS_PRODUCTION_APPROVED=true

MELHOR_ENVIO_SANDBOX=false
MELHOR_ENVIO_BASE_URL=
MELHOR_ENVIO_ACCESS_TOKEN=COLE_TOKEN_DE_PRODUCAO_NO_SERVIDOR
```

A Tuffer seleciona automaticamente o host de frete conforme MELHOR_ENVIO_SANDBOX. Se uma URL explícita incompatível for usada, a aplicação recusa a requisição. Asaas exige dupla liberação para escrever na API de produção (inclusive na fila).

**Somente ative** essas flags após comprovar, com credenciais de produção e webhook HTTPS registrado na conta real, que o fluxo de pedidos, recebimentos, notas fiscais e fretes está pronto para funcionar com valores reais. Sandbox não prova que a conta de produção está habilitada ou que a chave tem todas as permissões. A migração do código não altera o `.env` real.

## Testar sem afetar clientes

O modo mais seguro é uma **instância de homologação separada** com banco de dados separado, conta de cliente de testes e chaves e webhooks próprios. Nesse ambiente:
```dotenv
APP_ENV=production
APP_DEBUG=false
ASAAS_ENV=sandbox
ASAAS_API_KEY=CHAVE_SANDBOX
ASAAS_WEBHOOK_TOKEN=TOKEN_WEBHOOK_SANDBOX
ASAAS_CHECKOUT_ENABLED=true
ASAAS_PRODUCTION_APPROVED=false
ASAAS_SANDBOX_TEST_EMAIL=cliente-teste@exemplo.com

MELHOR_ENVIO_SANDBOX=true
MELHOR_ENVIO_BASE_URL=
MELHOR_ENVIO_ACCESS_TOKEN=TOKEN_SANDBOX_MELHOR_ENVIO
```
Não copie credenciais de produção para a homologação. O webhook Sandbox deve apontar para URL da homologação.

## Testes de apenas uma integração

As flags `ASAAS_ENV` e `MELHOR_ENVIO_SANDBOX` são independentes. Para testar frete sem gerar cobrança, use uma cópia da loja e `ASAAS_CHECKOUT_ENABLED=false`; para testar Pix/cartão Asaas sem comprar etiquetas, deixe as ações de compra de etiquetas bloqueadas e teste apenas a cotação de frete. Atenção: o frete de Sandbox não reproduz necessariamente preço, transportadora ou disponibilidade reais.

## Troca de ambiente

1. Faça backup e anote o modo anterior, sem copiar segredos para tickets/logs.
2. Interrompa checkout e workers durante a troca. Concilie cobranças pendentes, webhooks e etiquetas na conta antiga antes de prosseguir; alternar a chave **não migra IDs**, créditos ou pedidos.
3. Atualize as duas credenciais correspondentes ao modo escolhido. Para frete mantenha `MELHOR_ENVIO_BASE_URL=` vazio.
4. Verifique webhook do ambiente e URLs de callback. Reinicie os processos PHP/filas para reler o `.env`.
5. Execute `php scripts/check-integration-modes.php` (somente leitura, sem exibir segredos) e teste em conta autorizada.
6. Não mude a instância pública com pedidos reais pendentes apenas para experimentar: use homologação isolada.

Não coloque o `.env` real no Git, e não confunda `APP_DEBUG=true` com um Sandbox. A opção `APP_DEBUG=true` pode expor detalhes sensíveis de erros e deve ficar desativada no site.
