# Liberação operacional manual (independente de gateway)

Na tela `/admin/pedidos/{codigo}`, o administrador pode liberar para a esteira de produção um pedido Pix legado Pagar.me em `pending_payment`, ainda sem cobrança externa ou QR Code. Não depende do texto do erro nem do número de tentativas.

A ação exige motivo e aceitação explícita de que **não existe confirmação financeira**. Ela movimenta `orders` e `seller_orders` para `processing`, baixa a reserva de estoque, utiliza o cupom, bloqueia jobs antigos que estejam pendentes e grava auditoria imutável em `manual_payment_approvals`. O pagamento permanece com seu status original, sem `paid_at`, sem charge, sem split confirmado e sem transferência financeira.

Não é um comprovante de pagamento e não deve ser contabilizado como receita liquidada. O financeiro deverá tratar separadamente o recebimento real. Não use para pedidos que tenham cobrança remota, Pix ativo, múltiplos pagamentos ou jobs em execução.

Publicação: `git pull origin main` e `composer migrate` (migração 046, já presente). O formulário aparece antes das remessas na tela administrativa.
