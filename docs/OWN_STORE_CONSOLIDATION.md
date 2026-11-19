# Consolidação Tuffer + Linda Flor em uma loja operacional

## Objetivo
- **Uma loja operacional Tuffer**, para catálogo, gestão de produtos e pedidos.
- **Duas marcas comerciais**: Tuffer e Linda Flor, com registros na tabela `brands` e `products.brand_id`.
- **Histórico imutável**: pedidos antigos, seller_orders, itens, estoque, remessas, notas fiscais, custos, repasses e lançamentos financeiros não são fundidos nem renumerados.
- **Um painel de pedidos**: as linhas históricas das antigas lojas são consultadas pela tabela `store_consolidations`, mantendo o ID e os documentos fiscais da operação original.
- **Aviso visível em todas as páginas públicas**: ambiente de testes e checkout temporariamente suspenso.

## Migração 048
Ela encontra a loja operacional por nome/slug `Tuffer` ou `Tuffer Oficial` e a antiga loja `Linda Flor`. Registra o mapeamento, cria ambas as marcas, associa produtos sem marca original e transfere produtos e carrinhos à loja Tuffer. Inativa a loja Linda Flor, **sem excluir** seus registros.

**Antes de executar em produção:** faça backup SQL completo, confirme que os nomes/slug das duas lojas correspondem aos esperados e que o mesmo titular controla ambas. Confirme que a origem de frete, o depósito, a emissão fiscal e o estoque da operação unificada estão corretos. Como a migração modifica registros de produtos, não é uma mudança reversível automaticamente com `git revert`: rollback requer restauração planejada do banco.

## Implantação
```bash
git pull origin main
composer migrate
```
Depois confira no painel:
1. Uma única loja ativa, catálogo com produtos das duas marcas e filtros por marca.
2. Pedidos antigos das duas lojas, com todos os itens, remessas, rastreios, notas e ações administrativas.
3. Estoque, expedição, CEP de origem, etiquetas, configurações fiscais e cupons.
4. Mensagem de ambiente de testes em home, produtos, carrinho e checkout.

**O Asaas permanece desativado** no `.env` usando `ASAAS_CHECKOUT_ENABLED=false`. Este commit não habilita pagamentos nem altera webhooks.

## Observações
Consolidar pedidos históricos em um único registro fiscal seria incorreto, especialmente quando há notas e remessas por loja. Por isso, os pedidos antigos são **unificados na visão operacional**, não na integridade contábil do banco. As marcas são a identificação comercial dos produtos; os filtros existentes por `brand` continuam disponíveis.
