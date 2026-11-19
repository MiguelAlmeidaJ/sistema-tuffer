# Painel vendedor — pedidos e cancelamento de testes Asaas

## Visual
A página de detalhes mostra no início o pedido, o status e o menu compacto **Ações do pedido**. Cliente, entrega e total ficam em três cartões. Fiscal, logística e financeiro ficam recolhidos até serem necessários. A seção de cancelamento é separada, para evitar toques acidentais.

## Cancelar pedido de teste
Disponível **somente** para vendedor autenticado e pedido **aguardando pagamento**, com cobrança Asaas e ambiente `ASAAS_ENV=sandbox`. A ação exige digitar exatamente o código do subpedido e enviar com CSRF.

1. Obter trava MySQL `GET_LOCK('asaas-payment-{id}')`, compartilhada com geração de cobrança, para impedir criação concorrente.
2. Validar pedido, loja selecionada, cobrança pendente, ausência de outras operações do pedido, NF-e autorizada ou etiqueta comprada.
3. Consultar a cobrança pelo ID ou pelo `externalReference` do pedido no Asaas. Exigir o mesmo ID, referência e valor. Se estiver `PENDING` ou `OVERDUE`, solicitar `DELETE /payments/{id}`. Se houver qualquer confirmação de pagamento ou incerteza de criação remota, recusar e exigir reconciliação manual. A exclusão remota não é estorno.
4. Liberar reserva de estoque e marcar pedido, subpedido e pagamento como `cancelled`, no mesmo commit local, registrando o histórico.
5. Se a API falhar, não mudar o banco local. Consultar o painel Asaas antes de tentar novamente.

**Não usar com pedidos pagos, NF-e emitida ou remessa despachada.** A função não reembolsa, não apaga pedidos ou histórico e não oferece cancelamento real fora do Sandbox.

## Implantação
```bash
git pull origin main
```
Não há migração SQL. Os testes automatizados validam restrições; o fluxo de exclusão remota deve ser homologado com pedidos de teste e eventos de webhook.
