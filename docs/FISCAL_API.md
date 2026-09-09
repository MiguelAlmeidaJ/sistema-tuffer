# API fiscal externa

A API fiscal externa permite que uma loja configurada em `issuance_mode=external` continue emitindo NF-e no próprio ERP e devolva o resultado para a Tuffer. A Tuffer não usa a credencial para emitir nota: ela apenas autentica o ERP e mantém o documento vinculado ao `seller_order` correto.

## Autenticação

Cada loja possui no máximo uma credencial fiscal ativa. O vendedor gera ou rotaciona o token em `/vendedor/fiscal/integracao`. O token completo aparece uma única vez e o banco guarda somente `SHA-256` e um prefixo de identificação.

Todas as chamadas devem usar HTTPS e o cabeçalho:

```http
Authorization: Bearer tf_fiscal_<segredo>
```

Tokens em query string não são suportados. Uma credencial só acessa `seller_orders` da própria `store_id` e `seller_id`.

## Consultar documento

```http
GET /api/v1/fiscal/seller-orders/{seller_order_code}
```

Retorna status, número, série, chave, protocolo e indicadores de XML/DANFE.

## Registrar NF-e autorizada

```http
POST /api/v1/fiscal/seller-orders/{seller_order_code}/authorize
Content-Type: application/json
```

Exemplo:

```json
{
  "number": 1234,
  "series": 1,
  "access_key": "00000000000000000000000000000000000000000000",
  "protocol": "123456789",
  "external_reference": "erp-nfe-1234",
  "xml": "<?xml version=\"1.0\"?><nfeProc>...</nfeProc>",
  "danfe_base64": "JVBERi0xLjQK..."
}
```

`xml` e `danfe_base64` são opcionais. O XML é validado como NF-e e, quando a chave está presente em `infNFe/@Id`, precisa coincidir com `access_key`. O DANFE precisa ser PDF válido.

Limites: corpo JSON 25 MB, XML 5 MB e DANFE 15 MB já decodificado.

### Idempotência

Repetir exatamente o mesmo número, série e chave é aceito. Se a NF-e já estiver autorizada e uma chamada tentar substituir por outra chave/número/série, a Tuffer retorna conflito e registra evento de auditoria; o documento autorizado não é sobrescrito.

## Registrar cancelamento externo

```http
POST /api/v1/fiscal/seller-orders/{seller_order_code}/cancel
Content-Type: application/json
```

```json
{
  "reason": "Cancelamento autorizado no emissor externo.",
  "cancellation_protocol": "987654321",
  "external_reference": "erp-cancel-1234"
}
```

A razão precisa ter pelo menos 15 caracteres. Apenas NF-e autorizada pode ser marcada como cancelada. Repetir o cancelamento de um documento já cancelado é idempotente.

## Respostas

- `200`: operação concluída ou repetição idempotente.
- `400`: JSON inválido.
- `401`: token ausente, inválido ou revogado.
- `404`: `seller_order` não pertence à loja autenticada ou não existe.
- `409`: tentativa de substituir NF-e já autorizada.
- `422`: estado ou dados fiscais incompatíveis.
- `500`: falha interna não exposta ao ERP.

## Arquivos e cliente

XML e DANFE ficam em `storage/private/fiscal`. O cliente nunca recebe um caminho direto do storage. Os downloads passam por rotas autenticadas de `minha-conta`, que verificam usuário, pedido e `fiscal_document` antes de servir o arquivo.

## Rotação e revogação

Rotacionar uma credencial invalida imediatamente o token anterior. Revogar desabilita o acesso da integração sem alterar documentos fiscais já recebidos. `last_used_at` permite verificar se a integração está efetivamente utilizando o token atual.
