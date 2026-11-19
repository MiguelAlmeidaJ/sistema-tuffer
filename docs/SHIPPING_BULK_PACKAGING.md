# Correção de frete para lotes e atacado

## Problema identificado
A Tuffer oferecia `Dimensões por quantidade` no editor, mas não utilizava `product_shipping_rules` para calcular os fretes. A integração enviava apenas dimensões unitárias e quantidade, de modo que uma caixa real de 24 kits podia ficar diferente do empacotamento estimado pelo Melhor Envio. Fretes antigos não são alterados retroativamente.

## Correção
- Pedidos com uma unidade continuam com cotação por `products`.
- Pedidos com múltiplas unidades da **mesma variação** exigem uma faixa de embalagem em `product_shipping_rules`, cobrindo a quantidade total. A faixa representa **a caixa física final inteira**, inclusive embalagem, e o valor de peso precisa cobrir ao menos o peso dos itens.
- O serviço envia as medidas da caixa por `volumes` à API do Melhor Envio e grava as mesmas medidas nas opções retornadas, para a criação posterior da etiqueta.
- Se não houver faixa de embalagem, o checkout não apresenta preço baixo fictício; orienta a cadastrar a faixa.
- Carrinho com mistura de produtos e quantidade total maior que um exige conferência manual de embalagem nesta primeira etapa de segurança. Não oferecemos preço de frete automático até existir empacotamento confiável para o conjunto.
- Cache de frete de pedidos de múltiplas unidades não é reutilizado, pois alterações nas faixas devem aparecer imediatamente.

## Como corrigir o cadastro mostrado no incidente
Para o kit que vende 24 unidades, confirme primeiro qual é a **caixa real que chegará à transportadora** e pese a caixa cheia. No editor do produto, etapa 5, cadastre uma faixa cobrindo 24 unidades e informe dimensões externas e peso final. A tela básica de peso e medidas pode continuar descrevendo 1 kit, mas a faixa de 24 deve descrever o pacote completo.

Se o embarque real for em **2 ou mais caixas**, NÃO use o modelo de caixa única: contate a operação para cotar e emitir etiquetas individuais por volume. Para frete na transportadora, confirme se houve adicional por divergência de peso/dimensões, seguro, coleta ou praça/serviço.

A mudança não reembolsa diferenças de frete anteriores e não garante coincidência com tarifa de balcão, que pode diferir do preço negociado na plataforma. O custo final deve ser confirmado nas condições do serviço contratado.
