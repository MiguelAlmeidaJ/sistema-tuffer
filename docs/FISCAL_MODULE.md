# Módulo fiscal

O módulo fiscal da Tuffer é orientado ao `seller_order`: cada fatia da compra pertencente a uma loja possui seu próprio documento fiscal. O `order` agregado nunca é usado como uma NF-e única de vários vendedores.

## Emissão configurável por loja

A configuração fiscal efetiva passa a ser da `store`, não apenas do `seller`. Isso permite que o mesmo vendedor opere estabelecimentos com CNPJ, IE, série, endereço fiscal e método de emissão diferentes.

A tabela `store_fiscal_profiles` define três modos:

- `platform`: a Tuffer valida os dados fiscais e encaminha a NF-e ao provider configurado para aquela loja.
- `manual`: a loja emite no sistema que já utiliza e registra na Tuffer número, série e chave de acesso.
- `external`: um ERP/API externa emite e devolve os dados do documento para a Tuffer.

`seller_fiscal_profiles` continua sendo lido apenas como fallback de compatibilidade enquanto uma loja ainda não possui seu próprio perfil. Ao salvar a tela Fiscal da loja, passa a existir um `store_fiscal_profiles` próprio.

Cada `fiscal_document` guarda o `issuance_mode`, `provider` e `environment` utilizados naquele pedido. Assim, mudar a configuração futura da loja não apaga o contexto histórico do documento.

## Estado seguro por padrão

Nenhum provider real foi habilitado nesta etapa. `FiscalProviderFactory` continua retornando um provider desabilitado até que um adapter seja implementado e homologado.

Certificado A1, senha do certificado, tokens e segredos do provider não devem ser armazenados em `provider_settings`. Use variáveis de ambiente ou cofre de segredos. XML e DANFE ficam em `storage/private/fiscal`, nunca em `public/`.

## Fluxos

### Pela Tuffer (`platform`)

1. Pagar.me processa o webhook.
2. O `JobProcessor` agenda `fiscal.sync_paid_order` na fila `fiscal`.
3. `FiscalOrchestratorService` lê a configuração da loja.
4. Emissor, destinatário e itens são fotografados em snapshots.
5. Dados fiscais são validados.
6. Pendências deixam o documento em `validation_failed`.
7. Documento válido fica `ready`.
8. Somente se `auto_issue` estiver habilitado e o provider da loja estiver realmente configurado a emissão é enviada.

### Manual (`manual`)

1. O pagamento cria/atualiza o documento fiscal.
2. O documento fica `awaiting_manual`.
3. A loja emite fora da Tuffer.
4. O vendedor informa número, série, chave de acesso e, opcionalmente, protocolo/referência externa.
5. A Tuffer vincula a NF-e ao `seller_order` e registra um evento de auditoria.

### ERP/API externa (`external`)

1. O documento fica `awaiting_external`.
2. A integração externa deverá emitir a NF-e.
3. O retorno será registrado pelo mesmo núcleo fiscal, mantendo chave, número, eventos e vínculo com o pedido.

Nesta primeira etapa, o endpoint de registro pelo painel já existe; webhook/API autenticada para ERPs é a próxima camada.

## Dados fiscais

Para `platform`, a loja precisa de razão social, documento, indicador/inscrição estadual, regime, CRT, endereço fiscal, código IBGE e série da NF-e.

Produto: NCM, origem, unidades comercial/tributável, CFOP interno/interestadual, ICMS/CSOSN, PIS, COFINS e, enquanto `FISCAL_RTC_REQUIRED=true`, CST e classificação IBS/CBS. CEST e IPI são informados quando aplicáveis.

Nos modos `manual` e `external`, a Tuffer não bloqueia o pedido por ausência da classificação tributária interna, pois o motor emissor está fora da plataforma. Ainda assim, os snapshots e cadastros podem ser mantidos para auditoria e futura migração de provider.

## Reembolso

Documento autorizado nunca é cancelado automaticamente por um reembolso. A Tuffer marca revisão fiscal para decidir entre cancelamento, devolução ou outro evento adequado. Documentos ainda não autorizados podem ser anulados internamente após reembolso integral.

## Operação

O worker padrão inclui a fila `fiscal`. Para reconciliar pedidos pagos existentes:

```bash
php scripts/sync-fiscal-paid-orders.php 200
```

## Próximas camadas

1. Implementar o primeiro provider real (`FocusNfeProvider`, `NuvemFiscalProvider` ou equivalente).
2. Criar credenciais por loja em cofre seguro, sem gravar segredo em texto puro no banco.
3. Criar endpoint autenticado para ERP externo registrar autorização/cancelamento.
4. Permitir anexar/armazenar XML e DANFE emitidos fora da Tuffer.
5. Exibir NF-e/DANFE para o cliente no detalhe do pedido.
6. Criar painel fiscal administrativo e trilha completa de eventos.
