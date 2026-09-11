<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use App\Core\Database;

final class PurchaseAssistantService
{
    /** @param array<string,mixed>|null $user
     *  @return array{authenticated:bool,profile_complete:bool,has_address:bool}
     */
    public function customerState(?array $user, ?bool $hasAddress = null): array
    {
        $isCustomer = ($user['type'] ?? null) === 'customer' && !empty($user['id']);
        if (!$isCustomer) {
            return ['authenticated' => false, 'profile_complete' => false, 'has_address' => false];
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT document,phone FROM users WHERE id=? LIMIT 1');
        $statement->execute([(int) $user['id']]);
        $profile = $statement->fetch() ?: [];
        $document = preg_replace('/\D+/', '', (string) ($profile['document'] ?? '')) ?? '';
        $phone = preg_replace('/\D+/', '', (string) ($profile['phone'] ?? '')) ?? '';
        $profileComplete = in_array(strlen($document), [11, 14], true)
            && in_array(strlen($phone), [10, 11], true);

        if ($hasAddress === null) {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id=?');
            $statement->execute([(int) $user['id']]);
            $hasAddress = (int) $statement->fetchColumn() > 0;
        }

        return [
            'authenticated' => true,
            'profile_complete' => $profileComplete,
            'has_address' => (bool) $hasAddress,
        ];
    }

    /** @param array<string,mixed> $cart
     *  @param array<string,mixed> $shipping
     *  @param array{authenticated:bool,profile_complete:bool,has_address:bool} $customer
     *  @return array<string,mixed>
     */
    public function forCart(array $cart, array $shipping, array $customer): array
    {
        $hasItems = !empty($cart['items']);
        $minimumsMet = (bool) ($cart['minimums_met'] ?? true);
        $deliveryReady = $this->deliveryReady($cart, $shipping);

        if (!$hasItems) {
            return $this->build(
                'cart',
                'info',
                'Seu próximo passo é escolher um produto',
                'Adicione o que você gostou ao carrinho. Depois eu acompanho você até o pagamento.',
                'Explorar produtos',
                '/produtos',
                $customer,
                false,
                false,
                'account'
            );
        }

        if (!$minimumsMet) {
            return $this->build(
                'cart',
                'attention',
                'Vamos ajustar o carrinho primeiro',
                'Uma das lojas ainda não atingiu o mínimo da compra. Ajuste as quantidades indicadas e eu libero o próximo passo.',
                'Ver itens para ajustar',
                '#cart-stores',
                $customer,
                $deliveryReady,
                false,
                'account'
            );
        }

        if (!$customer['authenticated']) {
            return $this->build(
                'cart',
                'info',
                'Seu carrinho está salvo',
                'Entre ou crie sua conta para continuar. Depois do acesso, você volta direto para a finalização da compra.',
                'Entrar ou criar conta',
                '/entrar?redirect=/checkout',
                $customer,
                $deliveryReady,
                false,
                'account'
            );
        }

        if (!$customer['profile_complete']) {
            return $this->build(
                'cart',
                'attention',
                'Falta completar seus dados de compra',
                'Informe CPF/CNPJ e telefone com DDD uma única vez. Ao salvar, você volta automaticamente para a compra.',
                'Completar meus dados',
                '/minha-conta/perfil?return=/checkout',
                $customer,
                $deliveryReady,
                false,
                'profile'
            );
        }

        if (!$customer['has_address']) {
            return $this->build(
                'cart',
                'attention',
                'Agora cadastre onde quer receber',
                'Digite o CEP e nós preenchemos os dados disponíveis do endereço para você.',
                'Adicionar endereço',
                '/minha-conta/enderecos/novo?return=/checkout',
                $customer,
                $deliveryReady,
                false,
                'address'
            );
        }

        if (!$deliveryReady) {
            return $this->build(
                'cart',
                'info',
                'Quer conferir a entrega antes de continuar?',
                'Informe seu CEP para visualizar valores e prazos. Você também pode seguir e escolher o endereço no checkout.',
                'Calcular entrega',
                '#cart-delivery',
                $customer,
                false,
                false,
                'shipping'
            );
        }

        return $this->build(
            'cart',
            'success',
            'Tudo certo com o carrinho',
            'Seus dados principais já estão prontos. Agora é só revisar a entrega e escolher o pagamento.',
            'Continuar para o checkout',
            '/checkout',
            $customer,
            true,
            false,
            'payment'
        );
    }

    /** @param array<string,mixed> $cart
     *  @param array<string,mixed> $shipping
     *  @param array{authenticated:bool,profile_complete:bool,has_address:bool} $customer
     *  @return array<string,mixed>
     */
    public function forCheckout(
        array $cart,
        array $shipping,
        array $customer,
        bool $paymentConfigured,
        bool $shippingConfigured
    ): array {
        $deliveryReady = $this->deliveryReady($cart, $shipping);

        if (!$customer['authenticated']) {
            return $this->build('checkout', 'info', 'Vamos continuar de onde você parou', 'Entre ou crie sua conta. Seu carrinho continua salvo e você volta direto para esta etapa.', 'Entrar ou criar conta', '/entrar?redirect=/checkout', $customer, false, false, 'account');
        }
        if (!$customer['profile_complete']) {
            return $this->build('checkout', 'attention', 'Só faltam seus dados de compra', 'Complete CPF/CNPJ e telefone com DDD. Depois de salvar, você volta automaticamente para o checkout.', 'Completar meus dados', '/minha-conta/perfil?return=/checkout', $customer, false, false, 'profile');
        }
        if (!$customer['has_address']) {
            return $this->build('checkout', 'attention', 'Agora escolha onde receber', 'Cadastre seu endereço. Ao informar o CEP, preenchemos automaticamente os dados disponíveis.', 'Adicionar endereço', '/minha-conta/enderecos/novo?return=/checkout', $customer, false, false, 'address');
        }
        if (!$shippingConfigured) {
            return $this->build('checkout', 'attention', 'A entrega está temporariamente indisponível', 'Seu carrinho está salvo. Você não precisa refazer a compra; tente novamente em alguns instantes.', 'Voltar ao carrinho', '/carrinho', $customer, false, false, 'shipping');
        }
        if (!$deliveryReady) {
            return $this->build('checkout', 'attention', 'Escolha a entrega para continuar', 'Revise o endereço e selecione uma modalidade de entrega para cada loja do seu carrinho.', 'Ver opções de entrega', '#checkout-shipping', $customer, false, false, 'shipping');
        }
        if (!$paymentConfigured) {
            return $this->build('checkout', 'attention', 'O pagamento está temporariamente indisponível', 'Seu carrinho e sua entrega continuam salvos. Tente finalizar novamente em alguns instantes.', 'Voltar ao carrinho', '/carrinho', $customer, true, false, 'payment');
        }

        return $this->build('checkout', 'info', 'Falta só revisar e confirmar', 'Confira a entrega, escolha como prefere pagar e aceite os termos. Eu aviso quando estiver tudo pronto.', 'Continuar a revisão', '#checkout-payment', $customer, true, false, 'payment');
    }

    /** @param array<string,mixed> $cart
     *  @param array<string,mixed> $shipping
     */
    private function deliveryReady(array $cart, array $shipping): bool
    {
        $groups = $cart['groups'] ?? [];
        if (!is_array($groups) || $groups === []) return false;

        foreach ($groups as $group) {
            $storeId = (int) ($group['store_id'] ?? 0);
            if ($storeId < 1 || empty($shipping['stores'][$storeId]['options'])) return false;
        }
        return true;
    }

    /** @param array{authenticated:bool,profile_complete:bool,has_address:bool} $customer
     *  @return array<string,mixed>
     */
    private function build(
        string $context,
        string $tone,
        string $title,
        string $message,
        string $actionLabel,
        string $actionUrl,
        array $customer,
        bool $shippingComplete,
        bool $paymentComplete,
        string $current
    ): array {
        $states = [
            'account' => $customer['authenticated'],
            'profile' => $customer['profile_complete'],
            'address' => $customer['has_address'],
            'shipping' => $shippingComplete,
            'payment' => $paymentComplete,
        ];
        $labels = [
            'account' => 'Conta',
            'profile' => 'Dados',
            'address' => 'Endereço',
            'shipping' => 'Entrega',
            'payment' => 'Pagamento',
        ];
        $steps = [];
        $completed = 0;
        foreach ($labels as $key => $label) {
            $done = (bool) ($states[$key] ?? false);
            if ($done) $completed++;
            $steps[] = [
                'key' => $key,
                'label' => $label,
                'status' => $done ? 'done' : ($key === $current ? 'current' : 'pending'),
            ];
        }

        return [
            'context' => $context,
            'tone' => $tone,
            'title' => $title,
            'message' => $message,
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
            'progress' => (int) round(($completed / count($steps)) * 100),
            'steps' => $steps,
        ];
    }
}
