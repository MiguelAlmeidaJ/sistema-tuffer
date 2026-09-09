# Módulo fiscal

O módulo fiscal da Tuffer é orientado ao `seller_order`: cada fatia da compra pertencente a uma loja/vendedor possui seu próprio documento fiscal. O `order` agregado nunca é usado como uma NF-e única de vários vendedores.

## Estado seguro por padrão

A implantação nasce com `FISCAL_PROVIDER=disabled`, `FISCAL_ENVIRONMENT=homologation` e `FISCAL_AUTO_ISSUE=false`. Assim, pagamentos podem criar e validar snapshots fiscais sem transmitir documentos. Um adapter real deve implementar `App\Services\Fiscal\FiscalProvider` e ser registrado em `FiscalProviderFactory` somente após homologação.

Certificado A1, senha do certificado, tokens e segredos do provedor não devem ser armazenados em `seller_fiscal_profiles.provider_settings`. Use variáveis de ambiente ou cofre de segredos. XML e DANFE ficam em `storage/private/fiscal`, nunca em `public/`.

## Fluxo

1. Pagar.me processa o webhook.
2. Quando o pedido fica pago/processando/concluído, o `JobProcessor` agenda `fiscal.sync_paid_order` na fila `fiscal`.
3. Para cada `seller_order`, `FiscalDocumentService` cria ou atualiza o snapshot do emissor, destinatário e itens.
4. Se faltar dado fiscal, o documento fica `validation_failed` e `requires_action=1`; nenhuma emissão é tentada.
5. Se tudo estiver válido, fica `ready`.
6. Só com `FISCAL_AUTO_ISSUE=true` e um provider configurado a emissão é transmitida.
7. Documento autorizado é imutável; reembolso gera revisão fiscal em vez de cancelamento cego.

## Dados mínimos

Vendedor: razão social, documento, indicador/inscrição estadual, regime, CRT, endereço fiscal, código IBGE do município e série da NF-e.

Produto: NCM, origem, unidades comercial/tributável, CFOP interno/interestadual, ICMS/CSOSN, PIS, COFINS e, enquanto `FISCAL_RTC_REQUIRED=true`, CST e classificação IBS/CBS. CEST e IPI são informados quando aplicáveis.

Destinatário: o endereço do cliente ganhou `city_ibge_code`. Pedidos antigos tentam recuperar esse código de um endereço atual exatamente correspondente; sem correspondência, ficam pendentes para revisão.

## Operação

O worker padrão inclui a fila `fiscal`. Para reconciliar pedidos pagos existentes:

```bash
php scripts/sync-fiscal-paid-orders.php 200
```

Antes de habilitar produção: escolher o provedor, implementar o adapter, homologar NF-e/cancelamento, validar tributação com a contabilidade, configurar segredos/certificado, testar XML/DANFE e executar cenários de venda, rejeição, reembolso e indisponibilidade do provedor.
