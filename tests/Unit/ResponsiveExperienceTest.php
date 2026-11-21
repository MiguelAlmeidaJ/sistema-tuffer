<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ResponsiveExperienceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testResponsiveLayerLoadsAfterFeatureStyles(): void
    {
        foreach (['public', 'dashboard', 'auth'] as $layout) {
            $view = file_get_contents($this->root . '/resources/views/layouts/' . $layout . '.php');
            self::assertIsString($view);
            self::assertGreaterThan(
                strpos($view, "asset('css/app.css')"),
                strpos($view, "asset('css/responsive.css')")
            );
        }

        self::assertMatchesRegularExpression('~/assets/css/responsive\.css\?v=\d+$~', asset('css/responsive.css'));
    }

    public function testSmallScreenSafeguardsCoverPublicAndInternalAreas(): void
    {
        $css = file_get_contents($this->root . '/public/assets/css/responsive.css');

        self::assertIsString($css);
        self::assertStringContainsString('.home-products.product-grid', $css);
        self::assertStringContainsString('.filters:target', $css);
        self::assertStringContainsString('.dashboard-content', $css);
        self::assertStringContainsString('.metric-chart', $css);
        self::assertStringContainsString('@media (max-width: 360px)', $css);
    }

    public function testMobileCatalogOpensFiltersWithoutJavascript(): void
    {
        $view = file_get_contents($this->root . '/resources/views/public/catalog/index.php');

        self::assertIsString($view);
        self::assertStringContainsString('id="catalog-filters"', $view);
        self::assertStringContainsString('id="catalog-results"', $view);
        self::assertStringContainsString('class="filters__close"', $view);
        self::assertStringContainsString('class="mobile-filters button button--secondary"', $view);
        self::assertStringNotContainsString('<details class="mobile-filters">', $view);
    }

    public function testPublicHeaderProvidesAnAccessibleMobileMenu(): void
    {
        $header = file_get_contents($this->root . '/resources/views/components/public/header.php');
        $javascript = file_get_contents($this->root . '/public/assets/js/app.js');

        self::assertIsString($header);
        self::assertIsString($javascript);
        self::assertStringContainsString('aria-controls="public-mobile-menu"', $header);
        self::assertStringContainsString('data-public-menu-open', $header);
        self::assertStringContainsString('data-public-menu-close', $header);
        self::assertStringContainsString("event.key==='Escape'", $javascript);
        self::assertStringContainsString("document.body.classList.toggle('has-public-menu',open)", $javascript);
    }

    public function testHomeProvidesResponsiveProductCarouselAndCategories(): void
    {
        $view = file_get_contents($this->root . '/resources/views/public/home.php');
        $controller = file_get_contents($this->root . '/app/Http/Controllers/Public/HomeController.php');
        $javascript = file_get_contents($this->root . '/public/assets/js/home-product-rail.js');
        $css = file_get_contents($this->root . '/public/assets/css/public/home-refresh.css');
        self::assertStringContainsString('data-product-rail', $view);
        self::assertStringContainsString('data-product-track', $view);
        self::assertStringContainsString('data-product-next', $view);
        self::assertStringContainsString('data-product-progress', $view);
        self::assertStringContainsString('home-categories__grid', $view);
        self::assertStringContainsString('LIMIT 12', $controller);
        self::assertStringContainsString('scrollTo(', $javascript);
        self::assertStringContainsString("matchMedia('(prefers-reduced-motion:reduce)')", $javascript);
        self::assertStringContainsString('scroll-snap-type:x mandatory', $css);
        self::assertStringContainsString('@media(max-width:560px)', $css);
    }
}
