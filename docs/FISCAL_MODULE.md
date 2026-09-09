# Módulo fiscal

O módulo fiscal da Tuffer é orientado ao `seller_order`: cada fatia da compra pertencente a uma loja possui seu próprio documento fiscal. O `order` agregado nunca é usado como uma NF-e única de vários vendedores.

## Emissão configurável por loja

A configuração fiscal efetiva é da `store`, com fallback temporário para `seller_fiscal_profiles`. Isso permite CNPJ, IE, série, endereço fiscal e método de emissão diferentes entre lojas do mesmo vendedor.

`store_fiscal_profiles` possui três modos:

- `platform`: a Tuffer valida e envia ao provider fiscal da loja.
- `manual`: a loja emite fora da Tuffer e registra autorização/arquivos em `/vendedor/fiscal/integracao`.
- `external`: ERP/API emite e sincroniza autorização/cancelamento pela API autenticada.

O `fiscal_document` preserva `issuance_mode`, `provider` e `environment` do pedido, mesmo se a configuração da loja mudar depois.

## Segurança

Nenhum provider real está habilitado por padrão. `FiscalProviderFactory` continua fail-closed até existir adapter homologado.

Certificado A1, senha e segredos do provider não pertencem a `provider_settings`. A credencial da API externa é gerada por loja e armazenada somente como hash SHA-256 em `store_fiscal_api_credentials`; o token completo aparece uma única vez no painel.

XML e DANFE ficam em `storage/private/fiscal`, com permissão privada. XML recebido de fora precisa ser uma NF-e reconhecível e, quando contém chave em `infNFe/@Id`, ela deve coincidir com a chave registrada. DANFE precisa ser PDF válido. O cliente acessa arquivos somente por controller autenticado.

## Fluxo `platform`

1. Pagar.me confirma o pagamento.
2. A fila agenda `fiscal.sync_paid_order`.
3. `FiscalOrchestratorService` lê o perfil da loja.
4. Emissor, destinatário e itens são fotografados em snapshots.
5. Dados fiscais são validados.
6. Pendência vira `validation_failed`; documento válido vira `ready`.
7. Somente com `auto_issue=1` e provider realmente configurado ocorre transmissão.

## Fluxo `manual`

1. Documento fica `awaiting_manual`.
2. A loja emite no sistema próprio.
3. O vendedor registra número, série, chave e, opcionalmente, protocolo, XML e DANFE.
4. Arquivos ficam privados e a NF-e aparece no pedido do cliente.
5. Cancelamento externo pode ser registrado pelo painel sem apagar o histórico.

## Fluxo `external`

1. Documento fica `awaiting_external`.
2. O vendedor gera uma credencial exclusiva para a loja.
3. O ERP consulta o `seller_order` e envia a autorização para `/api/v1/fiscal`.
4. A Tuffer bloqueia substituição de NF-e já autorizada, mas aceita repetições idempotentes.
5. O ERP pode enviar XML e DANFE junto da autorização e depois registrar cancelamento.
6. O cliente visualiza e baixa os documentos pelo próprio pedido.

Contrato detalhado: `docs/FISCAL_API.md`.

## Reembolso

Reembolso nunca cancela automaticamente uma NF-e autorizada. O documento fica marcado para revisão fiscal para decidir cancelamento, devolução ou outro evento. Documentos ainda não autorizados podem ser anulados internamente após reembolso integral.

## Reconciliação

```bash
php scripts/sync-fiscal-paid-orders.php 200
```

## Ainda necessário antes de emissão real pela Tuffer

1. Escolher e implementar o primeiro provider real (`FocusNfeProvider`, `NuvemFiscalProvider` ou equivalente).
2. Guardar credenciais/certificados do provider em cofre de segredos apropriado.
3. Homologar emissão, consulta e cancelamento no provider escolhido.
4. Validar tributação e Reforma Tributária com a contabilidade.
5. Criar painel fiscal administrativo para operação e auditoria em escala.
