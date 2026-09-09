<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Response;
use App\Services\Fiscal\FiscalApiTokenService;
use App\Services\Fiscal\FiscalOrchestratorService;
use JsonException;
use RuntimeException;
use Throwable;

final class FiscalIntegrationController
{
    private const MAX_BODY_BYTES = 25 * 1024 * 1024;

    public function show(string $code): string
    {
        $credential = $this->credential();
        if ($credential === null) return $this->unauthorized();
        $sellerOrderId = $this->sellerOrderId($code, $credential);
        if ($sellerOrderId === null) return Response::json(['error'=>'seller_order_not_found','message'=>'Pedido da loja não encontrado para esta credencial.'], 404);
        try {
            $document = (new FiscalOrchestratorService())->documentForSellerOrder($sellerOrderId);
            return Response::json(['data'=>$this->documentPayload($code, $document)]);
        } catch (RuntimeException $e) {
            return Response::json(['error'=>'fiscal_state_invalid','message'=>$e->getMessage()], 422);
        } catch (Throwable $e) {
            Logger::exception($e, ['seller_order_code'=>$code], 'fiscal-api');
            return Response::json(['error'=>'internal_error','message'=>'Não foi possível consultar o documento fiscal.'], 500);
        }
    }

    public function authorize(string $code): string
    {
        $credential = $this->credential();
        if ($credential === null) return $this->unauthorized();
        $sellerOrderId = $this->sellerOrderId($code, $credential);
        if ($sellerOrderId === null) return Response::json(['error'=>'seller_order_not_found','message'=>'Pedido da loja não encontrado para esta credencial.'], 404);
        try {
            $data = $this->jsonBody();
            $data['danfe'] = $this->decodeBase64Field($data, 'danfe_base64');
            unset($data['danfe_base64']);
            $document = (new FiscalOrchestratorService())->registerOutsideDocument($sellerOrderId, $data);
            return Response::json(['data'=>$this->documentPayload($code, $document)], 200);
        } catch (JsonException $e) {
            return Response::json(['error'=>'invalid_json','message'=>'Envie um JSON válido.'], 400);
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'substituição foi bloqueada') ? 409 : 422;
            return Response::json(['error'=>$status===409?'fiscal_conflict':'fiscal_validation_failed','message'=>$e->getMessage()], $status);
        } catch (Throwable $e) {
            Logger::exception($e, ['seller_order_code'=>$code], 'fiscal-api');
            return Response::json(['error'=>'internal_error','message'=>'Não foi possível registrar a NF-e externa.'], 500);
        }
    }

    public function cancel(string $code): string
    {
        $credential = $this->credential();
        if ($credential === null) return $this->unauthorized();
        $sellerOrderId = $this->sellerOrderId($code, $credential);
        if ($sellerOrderId === null) return Response::json(['error'=>'seller_order_not_found','message'=>'Pedido da loja não encontrado para esta credencial.'], 404);
        try {
            $data = $this->jsonBody();
            $document = (new FiscalOrchestratorService())->registerOutsideCancellation($sellerOrderId, $data);
            return Response::json(['data'=>$this->documentPayload($code, $document)]);
        } catch (JsonException) {
            return Response::json(['error'=>'invalid_json','message'=>'Envie um JSON válido.'], 400);
        } catch (RuntimeException $e) {
            return Response::json(['error'=>'fiscal_validation_failed','message'=>$e->getMessage()], 422);
        } catch (Throwable $e) {
            Logger::exception($e, ['seller_order_code'=>$code], 'fiscal-api');
            return Response::json(['error'=>'internal_error','message'=>'Não foi possível registrar o cancelamento.'], 500);
        }
    }

    /** @return array<string,mixed>|null */
    private function credential(): ?array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        return (new FiscalApiTokenService())->authenticate($header);
    }

    /** @param array<string,mixed> $credential */
    private function sellerOrderId(string $code, array $credential): ?int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM seller_orders WHERE code=? AND store_id=? AND seller_id=? LIMIT 1');
        $stmt->execute([$code, (int) $credential['store_id'], (int) $credential['seller_id']]);
        $id = (int) $stmt->fetchColumn();
        return $id > 0 ? $id : null;
    }

    /** @return array<string,mixed> @throws JsonException */
    private function jsonBody(): array
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > self::MAX_BODY_BYTES) throw new RuntimeException('O corpo da requisição fiscal excede 25 MB.');
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') throw new JsonException('empty');
        if (strlen($raw) > self::MAX_BODY_BYTES) throw new RuntimeException('O corpo da requisição fiscal excede 25 MB.');
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new JsonException('not_object');
        return $decoded;
    }

    /** @param array<string,mixed> $data */
    private function decodeBase64Field(array $data, string $field): ?string
    {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') return null;
        if (!is_string($data[$field])) throw new RuntimeException('O DANFE em base64 é inválido.');
        $decoded = base64_decode($data[$field], true);
        if (!is_string($decoded)) throw new RuntimeException('O DANFE em base64 é inválido.');
        if (strlen($decoded) > 15 * 1024 * 1024) throw new RuntimeException('O DANFE deve ter no máximo 15 MB.');
        return $decoded;
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function documentPayload(string $code, array $document): array
    {
        return [
            'seller_order_code'=>$code,
            'document_id'=>(int) $document['id'],
            'status'=>(string) $document['status'],
            'issuance_mode'=>(string) ($document['issuance_mode'] ?? 'external'),
            'provider'=>(string) ($document['provider'] ?? 'external'),
            'environment'=>(string) ($document['environment'] ?? 'homologation'),
            'number'=>$document['number'] !== null ? (int) $document['number'] : null,
            'series'=>$document['series'] !== null ? (int) $document['series'] : null,
            'access_key'=>$document['access_key'] ?? null,
            'protocol'=>$document['protocol'] ?? null,
            'external_reference'=>$document['external_reference'] ?? null,
            'has_xml'=>!empty($document['xml_storage_path']),
            'has_danfe'=>!empty($document['danfe_storage_path']),
            'authorized_at'=>$document['authorized_at'] ?? null,
            'cancelled_at'=>$document['cancelled_at'] ?? null,
            'cancellation_protocol'=>$document['cancellation_protocol'] ?? null,
            'cancellation_reason'=>$document['cancellation_reason'] ?? null,
        ];
    }

    private function unauthorized(): string
    {
        header('WWW-Authenticate: Bearer realm="Tuffer Fiscal API"');
        return Response::json(['error'=>'unauthorized','message'=>'Credencial fiscal inválida, revogada ou incompatível com esta loja.'], 401);
    }
}
