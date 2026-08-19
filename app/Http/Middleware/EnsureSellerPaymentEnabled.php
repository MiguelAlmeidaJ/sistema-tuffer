<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Sellers\SellerSalesEligibility;
use App\Services\Stores\SellerStoreContext;
use Closure;

final class EnsureSellerPaymentEnabled implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$parameters): string
    {
        $store = (new SellerStoreContext())->current();
        if (!$store || !(new SellerSalesEligibility())->sellerCanSell((int) $store['seller_id'])) {
            Session::flash('error', 'Conclua sua configuração de recebimento antes de cadastrar ou publicar produtos.');
            return Response::redirect('/vendedor/configuracoes/recebimentos');
        }
        return $next();
    }
}
