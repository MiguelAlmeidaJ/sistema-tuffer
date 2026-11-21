<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Core\Auth;
use App\Core\Database;
use App\Services\Shipping\ShippingChoiceService;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Cart\CartService;
use App\Services\Checkout\PurchaseAssistantService;
use App\Services\Shipping\ShippingQuoteService;
use RuntimeException;

final class CartController extends Controller
{
    public function index(): string
    {
        $service = new CartService();
        $removedPaymentBlocked = $service->removePaymentBlockedItems();
        if ($removedPaymentBlocked > 0) {
            Session::flash('guide', 'Alguns itens ficaram indisponíveis para pagamento e foram retirados. Revise o carrinho e eu indico o próximo passo.');
        }
        $cart = $service->summary();
        $quoteService = new ShippingQuoteService();
        $choices = new ShippingChoiceService();
        $address = null;
        if ((Auth::user()['type'] ?? null) === 'customer') {
            $addressQuery = Database::connection()->prepare('SELECT id,label,street,number,city,state,postal_code FROM user_addresses WHERE user_id=? ORDER BY is_default DESC,id DESC LIMIT 1');
            $addressQuery->execute([Auth::id()]);
            $address = $addressQuery->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        $preferred = Session::get('shipping_product_preference');
        $eligiblePreference = false;
        if ($cart['items'] && is_array($preferred)
            && time() - (int)($preferred['created_at'] ?? 0) <= 1800
            && strlen((string)($preferred['postal_code'] ?? '')) === 8
            && count($cart['groups']) === 1 && count($cart['groups'][0]['items']) === 1) {
            $item = $cart['groups'][0]['items'][0];
            $eligiblePreference = (int)$item['variant_id'] === (int)$preferred['variant_id']
                && (int)$item['quantity'] === (int)$preferred['quantity'];
        }
        if ($eligiblePreference && (!$cart['postal_code'] || (string)$cart['postal_code'] !== (string)$preferred['postal_code'])) {
            $service->setPostalCode((string)$preferred['postal_code']);
            $cart = $service->summary();
        } elseif ($cart['items'] && !$cart['postal_code'] && $address) {
            $service->setPostalCode((string)$address['postal_code']);
            $cart = $service->summary();
        }
        $shipping = $cart['postal_code']
            ? $quoteService->quotes($cart, (string)$cart['postal_code'])
            : ['configured'=>$quoteService->configured(),'postal_code'=>null,'stores'=>[],'shipping_total'=>0.0];
        if ($cart['postal_code']) $choices->promoteProduct($cart,$shipping);
        $shipping = $choices->apply($cart,$shipping);

        $assistantService = new PurchaseAssistantService();
        $customerState = $assistantService->customerState(Auth::user());
        $purchaseAssistant = $assistantService->forCart($cart, $shipping, $customerState);
        if (!empty(Auth::user()['id']) && (Auth::user()['type'] ?? '') === 'admin' && !empty($customerState['signed_in']) && empty($customerState['authenticated'])) {
            $purchaseAssistant['action_url'] = '/admin';
        }

        return $this->page('public/cart/index', 'layouts/public', [
            'pageTitle' => 'Carrinho',
            'cart' => $cart,
            'shipping' => $shipping,
            'preferredAddress' => $address,
            'removedPaymentBlocked' => $removedPaymentBlocked,
            'purchaseAssistant' => $purchaseAssistant,
        ]);
    }

    public function store(): string
    {
        try {
            $cartService = new CartService();
            $cartService->add((int) ($_POST['variant_id'] ?? 0), (int) ($_POST['quantity'] ?? 1));
            $preference = Session::get('shipping_product_preference');
            if (is_array($preference) && time()-(int)($preference['created_at']??0)<=1800
                && (int)$preference['variant_id']===(int)($_POST['variant_id']??0)
                && strlen((string)($preference['postal_code']??''))===8) {
                $cartService->setPostalCode((string)$preference['postal_code']);
            }
            Session::flash('success', 'Produto adicionado ao carrinho.');
        } catch (RuntimeException $exception) {
            Session::flash('guide', $exception->getMessage());
        }
        return Response::redirect('/carrinho');
    }

    public function mode(): string
    {
        try {
            (new CartService())->switchMode((string) ($_POST['mode'] ?? 'retail'));
            Session::flash('success', ($_POST['mode'] ?? '') === 'wholesale' ? 'Modo atacado ativado.' : 'Modo varejo ativado.');
        } catch (RuntimeException $exception) {
            Session::flash('guide', $exception->getMessage());
        }
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
            Session::flash('guide', $exception->getMessage());
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
        try {
            (new CartService())->saveForLater((int) $id);
            Session::flash('success', 'Produto salvo nos favoritos.');
        } catch (RuntimeException $exception) {
            Session::flash('guide', $exception->getMessage());
        }
        return Response::redirect('/carrinho');
    }

    public function shipping(): string
    {
        try {
            $service = new CartService();
            $service->setPostalCode((string) ($_POST['postal_code'] ?? ''));
            $cart = $service->summary();
            $quotes = (new ShippingQuoteService())->quotes($cart, (string) $cart['postal_code'], true);
            $available = array_filter($quotes['stores'] ?? [], static fn(array $store): bool => !empty($store['options']));
            if (!$quotes['configured']) {
                Session::flash('guide', 'O cálculo de entrega está temporariamente indisponível. Seu carrinho ficou salvo e você pode tentar novamente em instantes.');
            } elseif (!$available) {
                Session::flash('guide', 'Não encontramos entrega para esse CEP. Confira o número informado ou tente outro endereço.');
            } else {
                Session::flash('success', 'Entrega calculada para ' . count($available) . ' loja(s).');
            }
        } catch (RuntimeException $exception) {
            Session::flash('guide', $exception->getMessage());
        }
        return Response::redirect('/carrinho');
    }

    public function chooseShipping(): string
    {
        $cartService = new CartService();
        $cart = $cartService->summary();
        $postalCode = (string)($cart['postal_code'] ?? '');
        $storeId = (int)($_POST['store_id'] ?? 0);
        $serviceId = trim((string)($_POST['service_id'] ?? ''));
        if (!$cart['items'] || strlen($postalCode)!==8 || $storeId<1 || $serviceId==='') {
            Session::flash('guide','Informe um endereço e escolha uma entrega válida.');
            return Response::redirect('/carrinho');
        }
        $quote = (new ShippingQuoteService())->quotes($cart,$postalCode,true);
        if (!(new ShippingChoiceService())->save($cart,$postalCode,$storeId,$serviceId,$quote)) {
            Session::flash('guide','Esta opção de entrega não está mais disponível. Selecione outra modalidade.');
        } else {
            Session::flash('success','Frete selecionado e salvo para este carrinho.');
        }
        return Response::redirect('/carrinho');
    }

    public function coupon(): string
    {
        try {
            (new CartService())->applyCoupon((string) ($_POST['code'] ?? ''));
            Session::flash('success', 'Cupom aplicado ao carrinho.');
        } catch (RuntimeException $exception) {
            Session::flash('guide', $exception->getMessage());
        }
        return Response::redirect('/carrinho');
    }

    public function removeCoupon(string $storeId): string
    {
        (new CartService())->removeCoupon((int) $storeId);
        Session::flash('success', 'Cupom removido.');
        return Response::redirect('/carrinho');
    }
}
