<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Response;
use App\Services\Fiscal\FiscalApiTokenService;
use App\Services\Fiscal\FiscalSellerOrderPayloadService;
use Throwable;

final class FiscalSellerOrderPayloadController
{
    public function show(string $code): string
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $credential = (new FiscalApiTokenService())->authenticate($header);
        if ($credential === null) {
            header('WWW-Authenticate: Bearer realm="Tuffer Fiscal API"');
            return Response::json(['error'=>'unauthorized','message'=>'Credencial fiscal inválida, revogada ou incompatível com esta loja.'],401);
        }

        $stmt = Database::connection()->prepare('SELECT id FROM seller_orders WHERE code=? AND store_id=? AND seller_id=? LIMIT 1');
        $stmt->execute([$code,(int)$credential['store_id'],(int)$credential['seller_id']]);
        $sellerOrderId = (int)$stmt->fetchColumn();
        if ($sellerOrderId < 1) return Response::json(['error'=>'seller_order_not_found','message'=>'Pedido da loja não encontrado para esta credencial.'],404);

        try {
            return Response::json(['data'=>(new FiscalSellerOrderPayloadService())->payload($sellerOrderId)]);
        } catch (Throwable $e) {
            Logger::exception($e,['seller_order_code'=>$code],'fiscal-api');
            return Response::json(['error'=>'internal_error','message'=>'Não foi possível carregar os dados fiscais do pedido.'],500);
        }
    }
}
