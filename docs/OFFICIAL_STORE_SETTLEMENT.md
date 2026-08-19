# Fechamento da loja oficial

## Antes de começar

Confirme:

- sincronização recente da conta global;
- nenhum pagamento pendente no período;
- nenhuma divergência crítica aberta;
- nenhum chargeback aberto;
- custos dos produtos revisados;
- estornos e taxas conciliados;
- reserva percentual e mínima aprovadas.

O fechamento lê exclusivamente `financial_entries`. Não use totais avulsos de pedidos como substituto.

## Gerar

1. Acesse `/admin/financeiro/fechamentos`.
2. Escolha `Loja oficial`.
3. Informe início e fim do período.
4. Clique em `Gerar fechamento`.
5. Confira faturamento, receita, custo, imposto, estornos, chargebacks, reserva, lucro estimado e valor transferível.

Se custo aparecer como `Não calculado`, preencha os custos para vendas futuras. Não altere snapshots antigos.

## Revisar

Compare:

- total do livro com o relatório consolidado;
- estornos e chargebacks com as cobranças corretas;
- taxa esperada com a taxa conciliada;
- cupons por origem;
- frete e subsídios;
- transferências anteriores;
- divergências abertas.

Registre uma observação de revisão. A revisão não muda valores.

## Aprovar

Depois da revisão, clique em `Aprovar fechamento`. Uma divergência crítica aberta impede a aprovação.

Aprovação não movimenta dinheiro.

## Registrar transferência manual

Por padrão, `OFFICIAL_STORE_TRANSFER_ENABLED=false`. A habilitação exige decisão operacional explícita.

Após efetuar a transferência fora da Tuffer:

1. informe o valor já transferido;
2. informe o nome do destino;
3. informe somente a conta mascarada;
4. informe a referência bancária;
5. anexe PDF, JPG ou PNG de até 5 MB;
6. registre a observação;
7. confirme.

O sistema cria um débito `transfer_out`, registra a transferência e reduz o saldo restante. Não executa PIX, TED ou chamada Pagar.me.

Transferências parciais são permitidas. A última parcela muda o fechamento para `transferred`. Valor acima do saldo, repetição da mesma operação e chamadas concorrentes são bloqueados.

## Comprovantes

Comprovantes ficam em armazenamento privado e só podem ser baixados por administrador autenticado na página do fechamento. Nunca use nome de arquivo fornecido pelo usuário e nunca publique `storage/app`.

## Divergências

Execute:

```bash
php scripts/reconcile-marketplace-financial.php 100
php scripts/reconcile-marketplace-financial.php 100 --provider
```

Analise `financial_reconciliation_issues`. Não edite lançamento confirmado. Resolva a causa e use:

- reversão ligada ao lançamento original; ou
- `adjustment_credit`/`adjustment_debit` com origem, justificativa e idempotência.

Depois, marque a divergência como resolvida com usuário, data e observação administrativa.

## Cancelar fechamento

Somente fechamento `awaiting_review` ou `approved`, sem qualquer transferência, pode ser cancelado. Informe o motivo na própria página.

O cancelamento:

- preserva o fechamento;
- preserva os lançamentos anexados;
- preserva a revisão e histórico;
- não apaga dados financeiros.

Fechamentos parciais ou totalmente transferidos não podem ser cancelados.

## Fechamento corretivo

Não edite nem regenere silenciosamente um fechamento existente.

1. registre a divergência;
2. crie lançamentos reversos ou de ajuste no livro;
3. preserve referência ao evento/pagamento original;
4. gere o próximo fechamento incluindo os ajustes;
5. mencione no campo de revisão qual fechamento está sendo corrigido.

Se for necessária uma revisão formal do mesmo período, mantenha o fechamento original cancelado e trate a nova versão como procedimento contábil controlado; não altere os valores históricos diretamente.

## Rotina mensal sugerida

1. sincronizar conta global;
2. reconciliar local e provedor;
3. resolver divergências;
4. revisar custos;
5. gerar fechamento;
6. revisar;
7. aprovar;
8. transferir externamente, se autorizado;
9. registrar a transferência;
10. guardar comprovante;
11. reconciliar novamente.
