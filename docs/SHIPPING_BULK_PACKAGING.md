# Frete: cadastro simples de kit e peso por peça

O cadastro da etapa 5 agora solicita **número de peças por kit** e **peso de uma peça em gramas**, calculando e salvando o peso do kit (kg). O preço continua por kit: o sistema não transforma 10 peças em 10 itens vendidos.

## Exemplo
- Kit com 10 calcinhas, 40 g por calcinha = 400 g por kit.
- 24 kits = 240 calcinhas, 9,6 kg em produtos, SEM contar a caixa.
- As medidas externas informadas na etapa 5 representam **um kit**.
- Quando há vários kits da mesma variação, o cotador monta uma **estimativa conservadora** com altura de empilhamento (altura do kit × quantidade), largura e comprimento originais. A quantidade também multiplica o peso do kit.
- Se passar de 30 kg, 100 cm de altura ou o carrinho misturar produtos diferentes, a cotação automática é bloqueada. É preciso definir embalagens reais e evitar valores fictícios.
- A opção de “dimensões por faixa” foi removida do formulário simples. As faixas legadas permanecem no banco por compatibilidade, mas não são usadas nesta cotação.
- O valor da transportadora pode variar por embalagem externa, peso da caixa, cubagem, agência, serviço e tarifa. **Peso automático não permite calcular dimensão física exata da caixa**.

## Publicação
```bash
git pull origin main
composer migrate
```

A migração 049 adiciona `pieces_per_kit` e `piece_weight_grams` ao cadastro dos produtos. Os registros antigos não recebem uma quantidade de peças adivinhada: começam com 1, e a tela apresenta o peso antigo convertido em gramas para permitir revisão. **Revisar e salvar** o kit com a quantidade real de peças e o peso unitário. O fluxo continua com o checkout Asaas pausado até homologação.
