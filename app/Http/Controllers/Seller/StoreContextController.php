<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Response;use App\Core\Session;use App\Http\Controllers\Controller;use App\Services\Stores\SellerStoreContext;

final class StoreContextController extends Controller
{
    public function select(): string{if(!(new SellerStoreContext())->select((int)($_POST['store_id']??0))){Session::flash('error','Loja inválida.');}return Response::redirect('/vendedor');}
}
