# Tuffer — homologação restrita do Asaas e modernização do checkout

## Controle de acesso (não abrir vendas ao público)
O checkout só libera a finalização quando `ASAAS_CHECKOUT_ENABLED=true` e a API + webhook estiverem configurados. Enquanto `ASAAS_ENV=sandbox`, **apenas uma conta do tipo `customer`** cujo e-mail corresponda exatamente a `ASAAS_SANDBOX_TEST_EMAIL` pode concluir pedidos. Todos os demais clientes continuam bloqueados, mesmo com a flag ativada. A regra é verificada na renderização do checkout, no POST e novamente em `OrderPlacementService` para impedir bypass.

### Variáveis (no .env do servidor, NUNCA no Git)
```dotenv
ASAAS_ENV=sandbox
ASAAS_API_KEY=CHAVE_SOMENTE_DO_SANDBOX
ASAAS_WEBHOOK_TOKEN=TOKEN_DO_WEBHOOK_SANDBOX
ASAAS_SANDBOX_TEST_EMAIL=EMAIL_DA_SUA_CONTA_CUSTOMER_DE_TESTE
ASAAS_CHECKOUT_ENABLED=false
ASAAS_PRODUCTION_APPROVED=false
```
Não use credenciais de produção no Sandbox. `ASAAS_CHECKOUT_ENABLED=false` mantém todos os checkouts desabilitados até a conferência inicial.

## Procedimento seguro
1. Atualize o código da main e rode `php scripts/check-asaas-sandbox.php` no servidor. O script testa autenticação por consulta de pagamentos somente leitura; não cria cobrança e não imprime credenciais. A saída `CONNECTED` não prova que o webhook está configurado nem que a cobrança está funcionando.
2. Cadastre no painel Asaas Sandbox o webhook HTTPS `https://tuffer.com.br/webhooks/asaas` com o mesmo token (header `asaas-access-token`) e os eventos de pagamento.
3. Use uma conta *customer* controlada por você, com documento e telefone válidos de teste, endereço de teste e produtos com estoque. Defina o e-mail dessa conta em `ASAAS_SANDBOX_TEST_EMAIL`.
4. Quando a API e o webhook estiverem configurados, altere somente `ASAAS_CHECKOUT_ENABLED=true` no servidor **com ASAAS_ENV=sandbox**. Os demais clientes continuam sem finalizar compras.
5. Finalize um pedido pequeno com Pix; verifique na página do pedido se apareceu QR Code e copia-e-cola, sem criar pedido duplicado. Verifique IDs remotos e logs, mas nunca compartilhe payload Pix, documentos, chave da API ou token.
6. Simule o pagamento no painel Sandbox; confira os logs de Webhooks e a mudança `pending_payment → paid` no pedido e nos seller_orders. Reenvie o mesmo webhook e confirme que não houve baixa dupla de estoque ou duplicação de notificações.
7. Teste também falha de rede, boleto, cartão hospedado, faturamento/expiração, estorno e pedidos pendentes antes de liberar uma produção real. O fluxo de estorno automático não está concluído.
8. Para voltar a bloquear imediatamente, defina `ASAAS_CHECKOUT_ENABLED=false`. Depois faça logout da conta de testes e confirme que checkout público não permite gerar cobrança.

## Interface
Carrinho e checkout receberam hierarquia visual, cards mais legíveis, estados do resumo, feedback de integração, layout responsivo, escolha de pagamento clara e selo de teste restrito. O carrinho não afirma mais parcelamento sem juros sem base no Asaas.

## Limitações
Os testes unitários/CI não substituem os testes reais no Sandbox. O assistente não tem acesso à chave nem ao painel da sua conta e **não pode confirmar conexão real sem o script ser executado no servidor**. As tarifas do frete ainda precisam ser validadas para embalagem real de roupas, sobretudo em compras com múltiplas unidades.

## Trava de produção
Em produção, além da configuração do cliente Asaas e de `ASAAS_CHECKOUT_ENABLED=true`, a função exige `ASAAS_PRODUCTION_APPROVED=true`. Mantenha `false` até finalizar os testes e decidir conscientemente reabrir vendas reais. Não use chave de produção para testar.
