<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Shipping\ShippingChoiceService;
use PHPUnit\Framework\TestCase;

final class ShippingChoiceExperienceTest extends TestCase
{
    public function testFingerprintInvalidatesChangesInQuantityAndDestination(): void
    {
        $service = new ShippingChoiceService();
        $cart = ['groups'=>[[
            'store_id'=>1,'origin_postal_code'=>'36660000','items'=>[[
                'variant_id'=>2,'quantity'=>1,'unit_price'=>10.0,
                'shipping_weight'=>0.1,'shipping_width'=>11,'shipping_height'=>2,'shipping_length'=>16,
            ]],
        ]]];
        $initial = $service->fingerprint($cart,'36660000');
        $cart['groups'][0]['items'][0]['quantity']=2;
        self::assertNotSame($initial,$service->fingerprint($cart,'36660000'));
        self::assertNotSame($service->fingerprint($cart,'36660000'),$service->fingerprint($cart,'17512752'));
    }

    public function testCheckoutEnforcesCartSelectionInsteadOfClientProvidedPrice(): void
    {
        $root=dirname(__DIR__,2);
        $controller=file_get_contents($root.'/app/Http/Controllers/Public/CheckoutController.php');
        self::assertStringContainsString('ShippingChoiceService', $controller);
        self::assertStringContainsString("quotes(\$cart, \$chosenPostal, true)", $controller);
        self::assertStringContainsString("['selected_option']", $controller);
        self::assertStringNotContainsString("(string) (\$_POST['shipping']", $controller);
        $view=file_get_contents($root.'/resources/views/public/checkout/index.php');
        self::assertStringContainsString('Entrega confirmada no carrinho',$view);
        self::assertStringContainsString('data-shipping-service',$view);
        self::assertStringNotContainsString('class="shipping-options"',$view);
    }

    public function testCartCanSelectOneOfTheQuotedServices(): void
    {
        $root=dirname(__DIR__,2);
        $view=file_get_contents($root.'/resources/views/public/cart/index.php');
        self::assertStringContainsString('carrinho/frete/escolher',$view);
        self::assertStringContainsString('cart-shipping-choice__button',$view);
        $controller=file_get_contents($root.'/app/Http/Controllers/Public/CartController.php');
        self::assertStringContainsString('ShippingChoiceService',$controller);
        self::assertStringContainsString('ORDER BY is_default DESC',$controller);
        $routes=file_get_contents($root.'/routes/web.php');
        self::assertStringContainsString("'/carrinho/frete/escolher'",$routes);
    }

    public function testProductCanRememberChoiceForSameSkuAndQuantity(): void
    {
        $root=dirname(__DIR__,2);
        $controller=file_get_contents($root.'/app/Http/Controllers/Public/ProductController.php');
        self::assertStringContainsString('rememberProduct(',$controller);
        $cart=file_get_contents($root.'/app/Http/Controllers/Public/CartController.php');
        self::assertStringContainsString('shipping_product_preference',$cart);
    }
}
