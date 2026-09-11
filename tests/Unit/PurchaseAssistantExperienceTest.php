<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Checkout\PurchaseAssistantService;
use PHPUnit\Framework\TestCase;

final class PurchaseAssistantExperienceTest extends TestCase
{
    public function testCartAssistantGuidesGuestToAuthentication(): void
    {
        $service = new PurchaseAssistantService();
        $assistant = $service->forCart(
            ['items' => [['id' => 1]], 'groups' => [['store_id' => 10]], 'minimums_met' => true],
            ['stores' => [10 => ['options' => [['id' => 'express']]]]],
            ['authenticated' => false, 'profile_complete' => false, 'has_address' => false]
        );

        self::assertSame('Entrar ou criar conta', $assistant['action_label']);
        self::assertSame('/entrar?redirect=/checkout', $assistant['action_url']);
        self::assertSame('current', $assistant['steps'][0]['status']);
    }

    public function testCheckoutAssistantPointsToMissingProfileData(): void
    {
        $service = new PurchaseAssistantService();
        $assistant = $service->forCheckout(
            ['groups' => [['store_id' => 10]]],
            ['stores' => [10 => ['options' => [['id' => 'express']]]]],
            ['authenticated' => true, 'profile_complete' => false, 'has_address' => true],
            true,
            true
        );

        self::assertSame('Completar meus dados', $assistant['action_label']);
        self::assertSame('/minha-conta/perfil?return=/checkout', $assistant['action_url']);
        self::assertSame('current', $assistant['steps'][1]['status']);
    }

    public function testCommerceViewsExposeAssistantHooks(): void
    {
        $root = dirname(__DIR__, 2);
        $cart = file_get_contents($root . '/resources/views/public/cart/index.php');
        $checkout = file_get_contents($root . '/resources/views/public/checkout/index.php');
        $bootstrap = file_get_contents($root . '/bootstrap/app.php');

        self::assertIsString($cart);
        self::assertIsString($checkout);
        self::assertIsString($bootstrap);
        self::assertStringContainsString('purchase-assistant.php', $cart);
        self::assertStringContainsString('purchase-assistant.php', $checkout);
        self::assertStringContainsString('data-profile-complete', $checkout);
        self::assertStringContainsString('id="checkout-shipping"', $checkout);
        self::assertStringContainsString("Session::pullFlash('guide')", $bootstrap);
        self::assertStringContainsString('https://viacep.com.br', $bootstrap);
    }
}
