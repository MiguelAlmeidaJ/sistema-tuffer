# Aprovação manual excepcional de Pix — migração Pagar.me / Asaas

A interface administrativa aparece apenas para pedidos `pending_payment` com **um único** pagamento Pix `payment_link` Pagar.me pendente, sem identificadores de cobrança externa nem QR Code, quando o job já falhou com `The type field is required`. Não é uma alternativa automática ao gateway.

## Publicação
1. Execute `composer migrate` (migração 046) antes de abrir o painel.
2. Entre como administrador e abra `/admin/pedidos/{codigo}`.
3. Confirme **fora da Tuffer**, em extrato de conta, que o valor integral foi realmente recebido. Não aceite apenas uma imagem de comprovante.
4. Informe referência verificável da transação, justificativa e marque a confirmação; use o botão de aprovação.
5. Consulte a tabela imutável `manual_payment_approvals` para auditoria. A indicação “manual” não representa transação Pagar.me/Asaas e não gera split automático.

A aprovação usa transação, bloqueios de linha, cancela a tentativa enfileirada e aplica baixa de reserva, cupom, estados e notificação. Não altera `provider`, IDs externos, `provider_payload` nem confirma lançamentos financeiros de split inexistentes. Reconcilie repasses e contabilidade **separadamente**.

Se ainda existir cobrança válida no gateway, ou o job estiver processando, a ação é bloqueada. Se não houve recebimento externo, **não aprove**: conclua a integração Asaas e apresente nova cobrança ao cliente.
