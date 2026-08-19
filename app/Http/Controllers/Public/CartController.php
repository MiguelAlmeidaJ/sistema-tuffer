<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Cart\CartService;
use App\Services\Shipping\ShippingQuoteService;
use RuntimeException;

final class CartController extends Controller
{
    public function index(): string
    {
        $service=new CartService();$removedPaymentBlocked=$service->removePaymentBlockedItems();$cart=$service->summary();$quoteService=new ShippingQuoteService();$shipping=$cart['postal_code']?$quoteService->quotes($cart,(string)$cart['postal_code']):['configured'=>$quoteService->configured(),'postal_code'=>null,'stores'=>[],'shipping_total'=>0.0];
        return $this->page('public/cart/index', 'layouts/public', ['pageTitle' => 'Carrinho', 'cart' => $cart, 'shipping' => $shipping, 'removedPaymentBlocked' => $removedPaymentBlocked]);
    }

    public function store(): string
    {
        try {
            (new CartService())->add((int) ($_POST['variant_id'] ?? 0), (int) ($_POST['quantity'] ?? 1));
            Session::flash('success', 'Produto adicionado ao carrinho.');
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect('/carrinho');
    }

    public function mode(): string
    {
        try { (new CartService())->switchMode((string) ($_POST['mode'] ?? 'retail')); Session::flash('success', ($_POST['mode'] ?? '') === 'wholesale' ? 'Modo atacado ativado.' : 'Modo varejo ativado.'); }
        catch (RuntimeException $exception) { Session::flash('error', $exception->getMessage()); }
        $return = (string) ($_POST['return'] ?? '/carrinho');
        if (!str_starts_with($return, '/') || str_starts_with($return, '//')) $return = '/carrinho';
        return Response::redirect($return);
    }

    public function update(string $id): string
    {
        try {
            (new CartService())->update((int) $id, (int) ($_POST['quantity'] ?? 1));
            Session::flash('success', 'Quantidade atualizada.');
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect('/carrinho');
    }

    public function destroy(string $id): string
    {
        (new CartService())->remove((int) $id);
        Session::flash('success', 'Item removido do carrinho.');
        return Response::redirect('/carrinho');
    }

    public function saveForLater(string $id): string
    {
        try{(new CartService())->saveForLater((int)$id);Session::flash('success','Produto salvo nos favoritos.');}catch(RuntimeException $exception){Session::flash('error',$exception->getMessage());}return Response::redirect('/carrinho');
    }

    public function shipping(): string
    {
        try{$service=new CartService();$service->setPostalCode((string)($_POST['postal_code']??''));$cart=$service->summary();$quotes=(new ShippingQuoteService())->quotes($cart,(string)$cart['postal_code'],true);$available=array_filter($quotes['stores']??[],static fn(array $store):bool=>!empty($store['options']));if(!$quotes['configured'])Session::flash('error','O cálculo real de frete será habilitado após configurar o Melhor Envio.');elseif(!$available)Session::flash('error','Não encontramos modalidades de entrega para este CEP.');else Session::flash('success','Entrega calculada para '.count($available).' loja(s).');}catch(RuntimeException $exception){Session::flash('error',$exception->getMessage());}return Response::redirect('/carrinho');
    }

    public function coupon(): string
    {
        try{(new CartService())->applyCoupon((string)($_POST['code']??''));Session::flash('success','Cupom aplicado ao carrinho.');}catch(RuntimeException $exception){Session::flash('error',$exception->getMessage());}return Response::redirect('/carrinho');
    }

    public function removeCoupon(string $storeId): string
    {
        (new CartService())->removeCoupon((int)$storeId);Session::flash('success','Cupom removido.');return Response::redirect('/carrinho');
    }
}
