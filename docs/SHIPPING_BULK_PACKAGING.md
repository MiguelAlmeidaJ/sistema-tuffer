# Frete de kits — embalagem real obrigatória para vários kits

## Problema
O peso dos kits é calculado corretamente a partir das peças, mas a estimativa anterior multiplicava a altura do kit por quantidade, sem conhecer a embalagem real. A API Melhor Envio devolvia tarifas para um pacote hipotético, inadequadas para cobrar do cliente.

## Solução (migração 050)
O editor continua simples para kits:
- Peças por kit; peso de uma peça; peso calculado de um kit
- Dimensões de UM kit
- Pacote real para lotes: capacidade em kits, peso da embalagem vazia, largura/altura/comprimento externos reais

Em cotação de 2 até a capacidade cadastrada, é enviada **uma pacote único** com dimensões reais cadastradas e peso total = (peso do kit × quantidade de kits) + peso da embalagem vazia. O Melhor Envio continua responsável pela tarifa. Se não houver pacote cadastrada, se o pedido exceder a capacidade ou se misturar itens distintos, a cotação automática é bloqueada, em vez de inventar medidas. Para uma unidade o fluxo original é mantido.

**Exemplo:** 10 peças de 40 g = 0,400 kg por kit; 24 kits = 9,600 kg de produtos. Se a embalagem vazia pesa 0,500 kg, peso cotado é 10,100 kg. Dimensões da pacote devem ser aferidas fisicamente. Não reutilize 25 × 3 × 24 cm (um kit) para a pacote cheio.

Limitações: o peso informado só representa produtos + tara da pacote, não embalagem interna extra; garanta que a tara inclua todos os materiais adicionais. Caso sejam usadas várias pacotes, será necessária implementação de múltiplos volumes com etiqueta individual. Fretes de pedidos anteriores não são recalculados automaticamente.

## Implantação
Faça backup, atualize a main e rode `composer migrate`; a migração 050 é aditiva. Revise o produto e informe dimensões da pacote final. Validar a cotação para o mesmo serviço/CEP no Melhor Envio e comparar etiqueta vs preço; valores de balcão podem diferir por contrato ou modalidade. Mantenha checkout pausado até homologação.

## Operação com roupas em pacote plástico
A Tuffer não exige caixa de papelão. Para sacos ou envelopes de roupas, meça **largura, comprimento e espessura (altura)** do pacote **fechado** contendo os kits e informe também o peso do plástico/lacre/proteções (tara). Não use 0 cm de espessura: algumas modalidades exigem dimensão mínima, que pode ser aplicada pelo cotador. O preço calculado é válido apenas para o volume e o serviço informados, sujeito às regras da transportadora. Se houver mais de um pacote, não informe todos como um pacote único.
