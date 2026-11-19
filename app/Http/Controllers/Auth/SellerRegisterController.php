<?php
declare(strict_types=1);
namespace App\Http\Controllers\Auth;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
final class SellerRegisterController extends Controller {
    public function create(): string {
        Session::flash('guide','A Tuffer trabalha apenas com lojas próprias.');
        return Response::redirect('/lojas');
    }
    public function store(): string {
        Session::flash('error','O cadastro de vendedores externos está encerrado.');
        return Response::redirect('/lojas');
    }
}
