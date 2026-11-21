<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HomeDiscoveryExperienceTest extends TestCase
{
    public function testHomeLoadsUpToTwelveRealProductsAndBrands(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/app/Http/Controllers/Public/HomeController.php');
        self::assertStringContainsString('p.created_at DESC LIMIT 12', $source);
        self::assertStringContainsString('FROM brands WHERE', $source);
        self::assertStringNotContainsString("FROM stores st JOIN sellers s ON s.id=st.seller_id WHERE st.status = 'active'", $source);
    }

    public function testCategoriesFollowTheProductCarousel(): void
    {
        $view=file_get_contents(dirname(__DIR__,2).'/resources/views/public/home.php');
        self::assertStringContainsString('data-product-rail', $view);
        self::assertStringContainsString('data-product-track', $view);
        self::assertStringContainsString('data-product-previous', $view);
        self::assertStringContainsString('data-product-next', $view);
        self::assertStringContainsString('home-categories__grid', $view);
        self::assertGreaterThan(strpos($view,'data-product-rail'),strpos($view,'home-categories__grid'));
        self::assertStringContainsString('home-brands__grid',$view);
        self::assertStringContainsString('Linda Flor',$view);
        self::assertStringContainsString('Tuffer',$view);
        self::assertStringNotContainsString('Lojas para explorar',$view);
        self::assertStringNotContainsString('seller-card.php',$view);
    }

    public function testBrandAndCategoryCardsLinkToCatalogFilters(): void
    {
        $view=file_get_contents(dirname(__DIR__,2).'/resources/views/public/home.php');
        self::assertStringContainsString("'/categoria/'.$category['slug']", $view);
        self::assertStringContainsString("'/produtos?brand='", $view);
        self::assertStringContainsString("'products_count'", file_get_contents(dirname(__DIR__,2).'/app/Http/Controllers/Public/HomeController.php'));
    }

    public function testCarouselSupportsTouchAndAccessibleNavigation(): void
    {
        $root=dirname(__DIR__,2);
        $js=file_get_contents($root.'/public/assets/js/home-product-rail.js');
        $css=file_get_contents($root.'/public/assets/css/public/home-refresh.css');
        self::assertStringContainsString("scrollTo(", $js);
        self::assertStringContainsString("event.key==='ArrowRight'", $js);
        self::assertStringContainsString('scroll-snap-type:x mandatory', $css);
        self::assertStringContainsString('@media(max-width:560px)', $css);
        self::assertStringContainsString('home-refresh.css', file_get_contents($root.'/public/assets/css/app.css'));
    }
}
