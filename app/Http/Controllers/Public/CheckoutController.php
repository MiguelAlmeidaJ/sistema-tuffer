<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Core\Logger;
use App\Http\Controllers\Controller;
use App\Services\Cart\CartService;
use App\Services\Finance\MarketplaceFinancialLedgerService;
use App\Services\Orders\OrderPlacementService;
use App\Services\Payments\CardInstallmentPricingService;
use App\Services\Payments\Asaas\AsaasClient;
use App\Services\Payments\Asaas\AsaasCheckoutAccess;
use App\Services\Payments\Pagarme\PagarmeCheckoutConfiguration;
use App\Services\Payments\Pagarme\PagarmeCreditCardOrderService;
use App\Services\Payments\Pagarme\PagarmeSplitService;
use App\Services\Queue\JobProcessor;
use App\Services\Queue\JobQueue;
use App\Services\Sellers\SellerSalesEligibility;
use App\Services\Shipping\ShippingQuoteService;
use Throwable;

final class CheckoutController extends Controller
{
    public function index(): string
    {
        $cartService = new CartService();
        if ($cartService->removePaymentBlockedItems() > 0) {
            Session::flash('error', 'Removemos itens de uma loja que não está habilitada para receber pagamentos.');
            return Response::redirect('/carrinho');
        }
        $cart = $cartService->summary();
        if (!$cart['items']) {
            Session::flash('error', 'Adicione ao menos um produto antes de iniciar o checkout.');
            return Response::redirect('/carrinho');
        }
        if (!($cart['minimums_met'] ?? true)) {
            Session::flash('error', 'Complete os mínimos de atacado de cada loja antes de continuar.');
            return Response::redirect('/carrinho');
        }

        $user = Auth::user();
        $addresses = [];
        if (($user['type'] ?? null) === 'customer') {
            $statement = Database::connection()->prepare('SELECT * FROM user_addresses WHERE user_id=? ORDER BY is_default DESC,id DESC');
            $statement->execute([Auth::id()]);
            $addresses = $statement->fetchAll();
        }
        $postalCode = (string) ($addresses[0]['postal_code'] ?? $cart['postal_code'] ?? '');
        $quoteService = new ShippingQuoteService();
        $shipping = $postalCode !== ''
            ? $quoteService->quotes($cart, $postalCode)
            : ['configured' => $quoteService->configured(), 'postal_code' => null, 'stores' => [], 'shipping_total' => 0.0];
        $checkoutAccessStatus = AsaasCheckoutAccess::status($user);

        return $this->page('public/checkout/index', 'layouts/public', [
            'pageTitle' => 'Checkout seguro',
            'cart' => $cart,
            'addresses' => $addresses,
            'isCustomer' => ($user['type'] ?? null) === 'customer',
            'paymentConfigured' => $checkoutAccessStatus === 'allowed',
            'checkoutAccessMessage' => AsaasCheckoutAccess::explanation($checkoutAccessStatus),
            'cardConfigured' => $checkoutAccessStatus === 'allowed',
            'pagarmePublicKey' => '',
            'cardTokenUrl' => '',
            'shipping' => $shipping,
            'shippingConfigured' => $quoteService->configured(),
            'sandboxTestMode' => AsaasCheckoutAccess::sandbox() && $checkoutAccessStatus === 'allowed',
        ]);
    }

    public function quotes(): string
    {
        header('Content-Type: application/json; charset=UTF-8');
        $cart = (new CartService())->summary();
        $postalCode = (string) ($_POST['postal_code'] ?? '');
        return json_encode(
            (new ShippingQuoteService())->quotes($cart, $postalCode),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';
    }

    public function store(): string
    {
        $user = Auth::user();
        if (($user['type'] ?? null) !== 'customer') {
            Session::flash('error', 'Entre como cliente para concluir a compra.');
            return Response::redirect('/entrar?redirect=' . rawurlencode('/checkout'));
        }
        $customerStatement = Database::connection()->prepare('SELECT document,phone FROM users WHERE id=? LIMIT 1');
        $customerStatement->execute([Auth::id()]);
        $customerData = $customerStatement->fetch() ?: [];
        $customerDocument = preg_replace('/\D+/', '', (string) ($customerData['document'] ?? '')) ?? '';
        $customerPhone = preg_replace('/\D+/', '', (string) ($customerData['phone'] ?? '')) ?? '';
        if (!in_array(strlen($customerDocument), [11, 14], true) || !in_array(strlen($customerPhone), [10, 11], true)) {
            Session::flash('guide', 'Complete CPF/CNPJ e telefone com DDD. Depois de salvar, você volta automaticamente para o checkout.');
            return Response::redirect('/minha-conta/perfil?return=' . rawurlencode('/checkout'));
        }

        $cartService = new CartService();
        if ($cartService->removePaymentBlockedItems() > 0) {
            Session::flash('error', 'Removemos itens de uma loja que não está habilitada para receber pagamentos.');
            return Response::redirect('/carrinho');
        }
        $cartId = $cartService->id();
        $cart = $cartService->summary();
        if (!$cartId || !$cart['items']) {
            return Response::redirect('/carrinho');
        }
        if (!($cart['minimums_met'] ?? true)) {
            Session::flash('error', 'Complete os mínimos de atacado antes de finalizar.');
            return Response::redirect('/carrinho');
        }

        $addressStatement = Database::connection()->prepare('SELECT * FROM user_addresses WHERE id=? AND user_id=?');
        $addressStatement->execute([(int) ($_POST['address_id'] ?? 0), Auth::id()]);
        $selectedAddress = $addressStatement->fetch();
        if (!$selectedAddress) {
            Session::flash('guide', 'Selecione um endereço de entrega para continuar.');
            return Response::redirect('/checkout');
        }
        if (empty($_POST['terms'])) {
            Session::flash('guide', 'Confirme os Termos de Compra e a Política de Privacidade para finalizar.');
            return Response::redirect('/checkout');
        }
        $paymentMethod = (string) ($_POST['payment_method'] ?? '');
        if (!in_array($paymentMethod, ['pix', 'card', 'boleto'], true)) {
            Session::flash('guide', 'Selecione uma forma de pagamento.');
            return Response::redirect('/checkout');
        }

        $shipping = (new ShippingQuoteService())->quotes($cart, (string) $selectedAddress['postal_code']);
        if (!$shipping['configured']) {
            Session::flash('guide', 'A entrega está temporariamente indisponível. Seu carrinho continua salvo; tente novamente em alguns instantes.');
            return Response::redirect('/checkout');
        }
        $shippingSelections = [];
        foreach ($cart['groups'] as $group) {
            $storeId = (int) $group['store_id'];
            $selectedId = (string) ($_POST['shipping'][$storeId] ?? '');
            $selectedOption = null;
            foreach ($shipping['stores'][$storeId]['options'] ?? [] as $candidate) {
                if ((string) $candidate['id'] === $selectedId) {
                    $selectedOption = $candidate;
                    break;
                }
            }
            if (!is_array($selectedOption)) {
                Session::flash('guide', 'Selecione uma modalidade de entrega para cada loja.');
                return Response::redirect('/checkout');
            }
            $shippingSelections[$storeId] = $selectedOption;
        }

        if (!AsaasCheckoutAccess::allowed($user)) {
            Session::flash('guide', AsaasCheckoutAccess::explanation(AsaasCheckoutAccess::status($user)) . ' Seu carrinho continua salvo.');
            return Response::redirect('/checkout');
        }

        try {
            $result = OrderPlacementService::make()->place(
                (int) Auth::id(),
                $cartId,
                (int) $selectedAddress['id'],
                $shippingSelections,
                $paymentMethod,
                [
                    'version' => OrderPlacementService::TERMS_VERSION,
                    'ip_hash' => hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), (string) ($_ENV['APP_KEY'] ?? 'tuffer-checkout')),
                ]
            );
            $paymentId = (int) $result['payment_id'];

            $this->processPaymentImmediately($paymentId);
            Session::flash('success', 'Pedido criado. A cobrança do Asaas está sendo preparada com segurança.');
            return Response::redirect('/checkout/pedido/' . rawurlencode((string) $result['order_code']));
        } catch (Throwable $exception) {
            Logger::exception($exception, [], 'checkout');
            Session::flash('guide', 'Não foi possível concluir essa etapa agora. Revise os dados e tente novamente; seu carrinho permanece protegido sempre que a compra ainda não tiver sido criada.');
            return Response::redirect('/checkout');
        }
    }

    private function processPaymentImmediately(int $paymentId): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT provider FROM payments WHERE id=?');
        $statement->execute([$paymentId]);
        if ((string) $statement->fetchColumn() !== 'asaas') return;
        $uniqueKey = 'asaas-payment:' . $paymentId;
        $queue = new JobQueue($pdo);
        $job = $queue->reserveByUniqueKey($uniqueKey, 'checkout:' . session_id());
        if ($job === null) {
            return;
        }

        try {
            (new JobProcessor())->process($job);
            $queue->complete((int) $job['id']);
            Logger::info('Pagamento preparado durante o checkout.', [
                'payment_id' => $paymentId,
                'job_id' => (int) $job['id'],
            ], 'payment');
        } catch (Throwable $exception) {
            $queue->fail($job, $exception);
            Logger::exception($exception, [
                'payment_id' => $paymentId,
                'job_id' => (int) $job['id'],
            ], 'payment');
        }
    }
}
