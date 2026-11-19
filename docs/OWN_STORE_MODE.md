# Tuffer — operação com lojas próprias

A Tuffer encerra o cadastro público de vendedores externos e deixa de depender do recipient/onboarding Pagar.me para apresentar produtos e aceitar itens no carrinho. Loja e responsável precisam continuar ativos.

Mantemos stores, sellers, seller_orders, notas, estoque, cupons e remessas: estas estruturas contêm histórico e logística que não podem ser apagados sem migração de dados. As cotações continuam separadas por origem física para evitar etiquetas e fretes incorretos.

IMPORTANTE: Este commit não integra Asaas e não substitui o gateway de pagamentos. O checkout ainda precisa da integração Pagar.me até ser migrado. Não liberar pagamentos reais antes de testar o novo fluxo.

Publicação: git pull origin main; composer test. Verificar catálogo, carrinho, lojas e frete das duas marcas. Sem migração SQL nova.
