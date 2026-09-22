<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SellerOrderTrackingExperienceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testSellerOrderHasDedicatedTrackingRouteAndController(): void
    {
        $routes = file_get_contents($this->root . '/routes/seller.php');
        $controller = file_get_contents($this->root . '/app/Http/Controllers/Seller/OrderController.php');

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertStringContainsString("/pedidos/{code}/rastreamento", $routes);
        self::assertStringContainsString('public function tracking(string $code): string', $controller);
        self::assertStringContainsString("'seller/orders/tracking'", $controller);
        self::assertStringContainsString("'return_to' ?? ''", $controller);
    }

    public function testOrderDetailPromotesTrackingAndKeepsOperationalActions(): void
    {
        $view = file_get_contents($this->root . '/resources/views/seller/orders/show.php');

        self::assertIsString($view);
        self::assertStringContainsString('seller-order-detail', $view);
        self::assertStringContainsString('Da aprovação à entrega', $view);
        self::assertStringContainsString("'/rastreamento'", $view);
        self::assertStringContainsString('/comprar-etiqueta', $view);
        self::assertStringContainsString('/nota-fiscal', $view);
        self::assertStringContainsString('/sincronizar-rastreio', $view);
    }

    public function testTrackingPageUsesPersistedMovementHistory(): void
    {
        $view = file_get_contents($this->root . '/resources/views/seller/orders/tracking.php');

        self::assertIsString($view);
        self::assertStringContainsString('HISTÓRICO DE MOVIMENTAÇÕES', $view);
        self::assertStringContainsString('$trackingEvents', $view);
        self::assertStringContainsString('name="return_to" value="tracking"', $view);
        self::assertStringContainsString('Abrir rastreio oficial', $view);
        self::assertStringNotContainsString('CTE Campinas', $view);
    }

    public function testDedicatedOrderDetailStylesAreLoaded(): void
    {
        $app = file_get_contents($this->root . '/public/assets/css/app.css');
        $css = file_get_contents($this->root . '/public/assets/css/seller/order-detail.css');

        self::assertIsString($app);
        self::assertIsString($css);
        self::assertStringContainsString('seller/order-detail.css', $app);
        self::assertStringContainsString('.seller-tracking-history', $css);
        self::assertStringContainsString('.seller-order-progress__track', $css);
    }
}
