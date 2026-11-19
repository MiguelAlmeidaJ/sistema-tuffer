# Cadastro simples de produto unitário e frete

A etapa 5 tem somente **peso (kg), largura, altura/espessura e comprimento** de **uma unidade vendida**. O valor do item e o estoque também são por unidade; kits antigos podem ser editados e convertidos em unidades, mas **pedidos históricos não são alterados**.

Na gravação, `pieces_per_kit=1`, `piece_weight_grams=weight*1000`; os antigos campos de pacote especial são zerados. Os dados permanecem no banco por compatibilidade, porém não são apresentados no formulário.

A cotação agora usa o array `products` da API Melhor Envio, incluindo `quantity`, peso e dimensões individuais. A própria API monta volumes estimados e retorna os `packages`, que são preservados para a emissão posterior da etiqueta. Veja a documentação da Melhor Envio sobre cálculo com múltiplos produtos.

**Atenção:** 0,040 kg × 10 unidades = 0,400 kg de produtos, sem tara de plástico, fita ou proteção. Dimensões UNITÁRIAS **não garantem** o tamanho do pacote fechado e, portanto, o preço retornado pode ser diferente do cobrado ao postar. Compare um pedido de teste com pacote real e cotação do mesmo serviço antes de reabrir vendas. A operação Asaas permanece pausada.

A atualização não exige migração adicional, pois preserva as colunas 049 e 050. Execute `git pull origin main`.
