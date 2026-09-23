<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductPageResponsiveExperienceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testProductPageUsesNewPurchaseAndGalleryExperience(): void
    {
        $view = file_get_contents($this->root . '/resources/views/public/products/show.php');

        self::assertIsString($view);
        self::assertStringContainsString('product-detail--v2', $view);
        self::assertStringContainsString('product-purchase-card', $view);
        self::assertStringContainsString('product-gallery__expand', $view);
        self::assertStringContainsString('product-quantity-control', $view);
        self::assertStringContainsString('shipping-calculator--v2', $view);
        self::assertStringContainsString('product-mobile-purchase', $view);
        self::assertStringContainsString('product-information__specs', $view);
    }

    public function testProductPageKeepsCriticalCommerceBindings(): void
    {
        $view = file_get_contents($this->root . '/resources/views/public/products/show.php');

        self::assertIsString($view);
        self::assertStringContainsString('/carrinho/adicionar', $view);
        self::assertStringContainsString('data-variant-select', $view);
        self::assertStringContainsString('data-product-quantity', $view);
        self::assertStringContainsString('data-add-button', $view);
        self::assertStringContainsString('data-shipping-calculator', $view);
        self::assertStringContainsString("'/frete'", $view);
        self::assertStringContainsString('csrf_field()', $view);
    }

    public function testResponsiveStylesCoverDesktopTabletAndMobile(): void
    {
        $css = file_get_contents($this->root . '/public/assets/css/public/product-experience.css');
        $app = file_get_contents($this->root . '/public/assets/css/app.css');

        self::assertIsString($css);
        self::assertIsString($app);
        self::assertStringContainsString('product-experience.css', $app);
        self::assertStringContainsString('@media(max-width:1180px)', $css);
        self::assertStringContainsString('@media(max-width:950px)', $css);
        self::assertStringContainsString('@media(max-width:720px)', $css);
        self::assertStringContainsString('@media(max-width:480px)', $css);
        self::assertStringContainsString('.product-mobile-purchase', $css);
        self::assertStringContainsString('scroll-snap-type', $css);
    }

    public function testJavascriptSynchronizesVariantPriceStockAndMobileCta(): void
    {
        $javascript = file_get_contents($this->root . '/public/assets/js/app.js');

        self::assertIsString($javascript);
        self::assertStringContainsString('syncProductVariantPresentation', $javascript);
        self::assertStringContainsString('[data-mobile-product-price]', $javascript);
        self::assertStringContainsString('[data-product-quantity-increase]', $javascript);
        self::assertStringContainsString('[data-product-quantity-decrease]', $javascript);
        self::assertStringContainsString('[data-mobile-add]', $javascript);
    }
}
