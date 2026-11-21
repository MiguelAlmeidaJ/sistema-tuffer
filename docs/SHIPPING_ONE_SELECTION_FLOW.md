# Escolha única de frete — produto → carrinho → checkout

## Funcionalidade
- A página de produto utiliza o CEP da conta, quando disponível, ou o CEP já selecionado no carrinho. O visitante pode cotar uma unidade ou várias e clicar **Escolher** na modalidade.
- A preferência feita no produto guarda apenas CEP, variação, quantidade, loja e identificador da modalidade por 30 minutos. Ela só é promovida ao carrinho quando este possui exatamente aquela variação e aquela quantidade (sem misturar produtos).
- No carrinho, se existe conta cliente e o carrinho ainda não possui CEP, o sistema aplica o CEP do endereço padrão, sem exigir digitação. O cliente pode corrigir o CEP se necessário.
- O carrinho mostra as modalidades e permite **escolher** uma delas. A escolha fica registrada na sessão atrelada ao carrinho e a uma assinatura do CEP, lojas, SKUs, quantidades, pesos, dimensões e preços. Alterações invalidam a escolha.
- O checkout exibe somente um resumo do frete escolhido, com link para alterar no carrinho. Ele não apresenta outra lista de modalidades e não pede para escolher novamente.
- A finalização refaz uma cotação válida no servidor e consulta a escolha salva, sem aceitar preço do navegador. Se o serviço deixou de existir, o endereço não coincide com o CEP cotado ou a escolha expirou, volta ao carrinho para revisão. A seleção guarda também a tarifa apresentada, exclusivamente para comparação. Se o preço atual divergir da cotação escolhida, a seleção é invalidada e o cliente precisa reconfirmar o novo valor no carrinho. O total nunca é aceito do navegador.

## Segurança e limitações
- CEP é suficiente apenas para a pré-cotação. O checkout exige um endereço cadastrado cujo CEP coincida; caso não haja esse endereço, o cliente deve criar/corrigir um endereço e escolher novamente no carrinho.
- A opção do produto não fica permanentemente válida após adicionar novos itens.
- Cotações dependem da disponibilidade do Melhor Envio; não há preço fixado pelo clique e nem garantia de preço sem nova verificação.
- Os valores e dimensões continuam sujeitos à aferição real do pacote.
- A escolha reside na sessão por 30 minutos, não em nova tabela SQL, evitando migrações. Se o navegador perder a sessão, o cliente poderá escolher novamente.

## Publicar
```bash
git pull origin main
```
Depois, testar: login com endereço padrão, produto com escolha de frete, carrinho com uma ou várias unidades, mudança de quantidade, mudança de CEP, checkout e pedido Sandbox Asaas. Confirmar no banco que a modalidade da venda corresponde à escolhida e ao valor atualizado da cotação.

O carrinho oferece um atalho para calcular com o endereço principal caso o CEP do carrinho seja diferente. Isso evita alterar silenciosamente uma entrega já escolhida.
