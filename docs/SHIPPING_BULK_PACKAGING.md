# Frete de kits — embalagem real obrigatória para vários kits

## Problema
O peso dos kits é calculado corretamente a partir das peças, mas a estimativa anterior multiplicava a altura do kit por quantidade, sem conhecer a caixa real. A API Melhor Envio devolvia tarifas para um pacote hipotético, inadequadas para cobrar do cliente.

## Solução (migração 050)
O editor continua simples para kits:
- Peças por kit; peso de uma peça; peso calculado de um kit
- Dimensões de UM kit
- Caixa real para lotes: capacidade em kits, peso da caixa vazia, largura/altura/comprimento externos reais

Em cotação de 2 até a capacidade cadastrada, é enviada **uma caixa única** com dimensões reais cadastradas e peso total = (peso do kit × quantidade de kits) + peso da caixa vazia. O Melhor Envio continua responsável pela tarifa. Se não houver caixa cadastrada, se o pedido exceder a capacidade ou se misturar itens distintos, a cotação automática é bloqueada, em vez de inventar medidas. Para uma unidade o fluxo original é mantido.

**Exemplo:** 10 peças de 40 g = 0,400 kg por kit; 24 kits = 9,600 kg de produtos. Se a caixa vazia pesa 0,500 kg, peso cotado é 10,100 kg. Dimensões da caixa devem ser aferidas fisicamente. Não reutilize 25 × 3 × 24 cm (um kit) para a caixa cheia.

Limitações: o peso informado só representa produtos + tara da caixa, não embalagem interna extra; garanta que a tara inclua todos os materiais adicionais. Caso sejam usadas várias caixas, será necessária implementação de múltiplos volumes com etiqueta individual. Fretes de pedidos anteriores não são recalculados automaticamente.

## Implantação
Faça backup, atualize a main e rode `composer migrate`; a migração 050 é aditiva. Revise o produto e informe dimensões da caixa final. Validar a cotação para o mesmo serviço/CEP no Melhor Envio e comparar etiqueta vs preço; valores de balcão podem diferir por contrato ou modalidade. Mantenha checkout pausado até homologação.
