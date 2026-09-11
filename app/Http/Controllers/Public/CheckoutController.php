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
use App\Services\Payments\PagarmeClient;
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
        $paymentClient = new PagarmeClient();
        $checkoutConfiguration = new PagarmeCheckoutConfiguration();

        return $this->page('public/checkout/index', 'layouts/public', [
            'pageTitle' => 'Checkout seguro',
            'cart' => $cart,
            'addresses' => $addresses,
            'isCustomer' => ($user['type'] ?? null) === 'customer',
            'paymentConfigured' => $paymentClient->configured(),
            'cardConfigured' => $paymentClient->configured() && $this->cardConfiguredForCart($checkoutConfiguration, $cart),
            'pagarmePublicKey' => $checkoutConfiguration->publicKey(),
            'cardTokenUrl' => $checkoutConfiguration->tokenizationUrl(),
            'shipping' => $shipping,
            'shippingConfigured' => $quoteService->configured(),
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

        $checkoutConfiguration = new PagarmeCheckoutConfiguration();
        $cardToken = trim((string) ($_POST['card_token'] ?? ''));
        $cardInstallments = max(1, min(6, (int) ($_POST['card_installments'] ?? 1)));
        if ($paymentMethod === 'card') {
            if (!$this->cardConfiguredForCart($checkoutConfiguration, $cart)) {
                Session::flash('guide', 'O cartão ainda não está disponível neste checkout. Escolha Pix ou boleto por enquanto.');
                return Response::redirect('/checkout');
            }
            if (preg_match('/^token_[A-Za-z0-9_-]+$/', $cardToken) !== 1) {
                Session::flash('guide', 'Valide os dados do cartão novamente antes de finalizar.');
                return Response::redirect('/checkout');
            }
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

        if (!(new PagarmeClient())->configured()) {
            Session::flash('guide', 'O pagamento está temporariamente indisponível. Seu carrinho continua salvo.');
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

            if ($paymentMethod === 'card') {
                $cardState = $this->processCardImmediately($paymentId, $cardToken, $cardInstallments);
                if ($cardState === 'direct') {
                    Session::flash('success', 'Pedido ' . $result['order_code'] . ' criado. Seu cartão foi enviado com segurança e estamos confirmando o pagamento.');
                } elseif ($cardState === 'fallback') {
                    Session::flash('success', 'Pedido ' . $result['order_code'] . ' criado sem duplicação. Continue o pagamento pela alternativa segura preparada para este pedido.');
                } else {
                    Session::flash('guide', 'Recebemos a tentativa com cartão e estamos verificando a resposta da operadora. Não tente pagar novamente enquanto o pedido estiver em processamento.');
                }
            } else {
                $this->processPaymentImmediately($paymentId);
                Session::flash('success', 'Pedido ' . $result['order_code'] . ' criado. Estamos preparando o pagamento com segurança.');
            }
            return Response::redirect('/minha-conta/pedidos/' . rawurlencode((string) $result['order_code']));
        } catch (Throwable $exception) {
            Logger::exception($exception, [], 'checkout');
            Session::flash('guide', 'Não foi possível concluir essa etapa agora. Revise os dados e tente novamente; seu carrinho permanece protegido sempre que a compra ainda não tiver sido criada.');
            return Response::redirect('/checkout');
        }
    }

    /** @return 'direct'|'fallback'|'processing' */
    private function processCardImmediately(int $paymentId, string $cardToken, int $installments): string
    {
        $pdo = Database::connection();
        try {
            (new PagarmeSplitService($pdo))->createSnapshot($paymentId);
            (new MarketplaceFinancialLedgerService($pdo))->createPending($paymentId);
            (new PagarmeCreditCardOrderService(null, $pdo))->create($paymentId, $cardToken, $installments);
            return 'direct';
        } catch (Throwable $exception) {
            Logger::exception($exception, ['payment_id' => $paymentId], 'pagarme_card');
            $statement = $pdo->prepare('SELECT integration_type,status FROM payments WHERE id=? LIMIT 1');
            $statement->execute([$paymentId]);
            $payment = $statement->fetch() ?: [];
            $integrationType = (string) ($payment['integration_type'] ?? '');
            $status = (string) ($payment['status'] ?? '');

            if (!($integrationType === 'payment_link' && $status === 'pending')) {
                return 'processing';
            }

            $this->processPaymentImmediately($paymentId);
            return 'fallback';
        }
    }

    /** @param array<string,mixed> $cart */
    private function cardConfiguredForCart(PagarmeCheckoutConfiguration $configuration, array $cart): bool
    {
        if (!$configuration->cardCheckoutConfigured()) {
            return false;
        }

        $sellerIds = array_values(array_unique(array_filter(array_map(
            static fn(array $item): int => (int) ($item['seller_id'] ?? 0),
            is_array($cart['items'] ?? null) ? $cart['items'] : []
        ), static fn(int $sellerId): bool => $sellerId > 0)));

        if ($sellerIds === []) {
            return false;
        }

        try {
            (new SellerSalesEligibility())->assertAllCanSell($sellerIds);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function processPaymentImmediately(int $paymentId): void
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT integration_type FROM payments WHERE id=?');
        $statement->execute([$paymentId]);
        $integrationType = (string) $statement->fetchColumn();
        if ($integrationType === '') {
            return;
        }

        $uniqueKey = $integrationType === 'orders'
            ? 'pagarme-order:' . $paymentId
            : 'pagarme-payment-link:' . $paymentId;
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
