<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CleanCartExperienceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root=dirname(__DIR__,2);
    }

    public function testCartHasNoAssistantOrDuplicateTrustStrip(): void
    {
        $view=file_get_contents($this->root.'/resources/views/public/cart/index.php');
        self::assertStringContainsString('cart-clean-heading',$view);
        self::assertStringNotContainsString("purchase-assistant.php",$view);
        self::assertStringNotContainsString('commerce-assurance-strip',$view);
        self::assertStringNotContainsString('purchase-flow-progress.php',$view);
        self::assertStringNotContainsString('SKU:', $view);
        self::assertStringNotContainsString('Vendido e enviado por', $view);
        self::assertStringNotContainsString("url('/loja/'", $view);
    }

    public function testCoreActionsAndServerQuoteChoicesStayIntact(): void
    {
        $view=file_get_contents($this->root.'/resources/views/public/cart/index.php');
        foreach ([
            '/carrinho/item/', '/carrinho/frete/escolher',
            '/carrinho/frete', '/carrinho/cupom',
            '/checkout', 'csrf_field()', 'cart-shipping-choice__button',
            'selection_complete', 'cart-clean-coupon',
            'cart-clean-staff-note',
        ] as $expected) {
            self::assertStringContainsString($expected, $view);
        }
        $shipping=file_get_contents($this->root.'/app/Services/Shipping/ShippingChoiceService.php');
        self::assertStringContainsString('fingerprint(', $shipping);
    }

    public function testCleanStylesRemainScopedAndResponsive(): void
    {
        $css=file_get_contents($this->root.'/public/assets/css/public/cart-clean.css');
        $app=file_get_contents($this->root.'/public/assets/css/app.css');
        self::assertStringContainsString('.cart-clean-heading~.cart-layout',$css);
        self::assertStringContainsString('@media(max-width:640px)',$css);
        self::assertStringContainsString('cart-clean.css',$app);
    }
}
