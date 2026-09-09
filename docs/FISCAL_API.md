# API fiscal externa

A API fiscal externa permite que uma loja configurada em `issuance_mode=external` continue emitindo NF-e no próprio ERP e devolva o resultado para a Tuffer. A Tuffer não usa a credencial para emitir nota: ela apenas autentica o ERP e mantém o documento vinculado ao `seller_order` correto.

## Autenticação

Cada loja possui no máximo uma credencial fiscal ativa. O vendedor gera ou rotaciona o token em `/vendedor/fiscal/integracao`. O token completo aparece uma única vez e o banco guarda somente `SHA-256` e um prefixo de identificação.

Todas as chamadas devem usar HTTPS e o cabeçalho:

```http
Authorization: Bearer tf_fiscal_<segredo>
```

Tokens em query string não são suportados. Uma credencial só acessa `seller_orders` da própria `store_id` e `seller_id`.

## Fluxo automático

Quando o pagamento deixa um `seller_order` apto para processamento fiscal, a fila da Tuffer sincroniza o documento e, para lojas em modo `external` com webhook ativo, agenda uma entrega `fiscal.seller_order.ready`.

O webhook é apenas um aviso. Ele não transporta todo o pedido. O ERP recebe o código e a URL do recurso, então usa o Bearer token da própria loja para buscar o payload completo.

Exemplo de webhook:

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "type": "fiscal.seller_order.ready",
  "created_at": "2026-09-09 16:00:00",
  "data": {
    "seller_order_code": "SO-ABC123",
    "resource_url": "https://tuffer.com.br/api/v1/fiscal/seller-orders/SO-ABC123/payload"
  }
}
```

Headers de assinatura:

```http
X-Tuffer-Event: <event-id>
X-Tuffer-Timestamp: <unix-timestamp>
X-Tuffer-Signature: sha256=<hmac>
```

A assinatura é `HMAC-SHA256(timestamp + "." + corpo_bruto, segredo_webhook)`. O ERP deve validar a assinatura antes de processar o evento, rejeitar timestamps antigos e tratar `X-Tuffer-Event` de forma idempotente.

O segredo HMAC é gerado por loja, exibido uma única vez e armazenado criptografado com chave derivada de `APP_KEY`. A URL do webhook deve ser HTTPS público na porta 443; destinos locais, privados e reservados são bloqueados e redirects não são seguidos.

Falhas de entrega são reprocessadas pela fila com backoff. A ativação do webhook e o script de reconciliação também reavaliam pedidos elegíveis sem criar evento duplicado para o mesmo `seller_order`.

## Consultar documento

```http
GET /api/v1/fiscal/seller-orders/{seller_order_code}
```

Retorna status, número, série, chave, protocolo e indicadores de XML/DANFE.

## Buscar payload para emissão

```http
GET /api/v1/fiscal/seller-orders/{seller_order_code}/payload
Authorization: Bearer tf_fiscal_<segredo>
```

Retorna dados do `seller_order`, totais, emissor, destinatário/endereço e itens com as referências fiscais disponíveis (`NCM`, `CEST`, origem, CFOP, ICMS, PIS, COFINS, IPI e IBS/CBS). Esses campos são dados de referência: o cálculo tributário e a emissão continuam sob responsabilidade do ERP/emissor da loja.

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

O webhook possui segredo separado do Bearer token. Alterar a URL pelo painel `/vendedor/fiscal/webhook` rotaciona o segredo HMAC; desativar o webhook interrompe novos envios sem revogar a API do ERP.
