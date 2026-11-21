<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SingleStorePublicNavigationTest extends TestCase
{
    public function testCategoryCardsAreTextOnly(): void
    {
        $root=dirname(__DIR__,2);
        $home=file_get_contents($root.'/resources/views/public/home.php');
        $start=strpos($home,'<section class="home-categories');
        $end=strpos($home,'</section>',$start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $categories=substr($home,$start,$end-$start);
        self::assertStringContainsString('home-categories__info',$categories);
        self::assertStringNotContainsString('home-categories__media',$categories);
        self::assertStringNotContainsString('category[\'image_path\']',$categories);
        self::assertStringNotContainsString('<img',$categories);
    }

    public function testStoreLinksRemovedFromPublicNavigation(): void
    {
        $root=dirname(__DIR__,2);
        foreach ([
            'resources/views/components/public/header.php',
            'resources/views/components/public/footer.php',
            'resources/views/public/catalog/index.php',
        ] as $file) {
            $source=file_get_contents($root.'/'.$file);
            self::assertStringNotContainsString("url('/lojas')",$source,$file);
        }
        $catalog=file_get_contents($root.'/resources/views/public/catalog/index.php');
        self::assertStringNotContainsString('name="store"',$catalog);
        $search=file_get_contents($root.'/resources/views/components/public/search-bar.php');
        self::assertStringNotContainsString('ofertas e lojas',$search);
    }

    public function testOldPublicStoreUrlsRedirectToUnifiedCatalog(): void
    {
        $root=dirname(__DIR__,2);
        $controller=file_get_contents($root.'/app/Http/Controllers/Public/StoreController.php');
        self::assertSame(2,substr_count($controller,"Response::redirect('/produtos', 301)"));
        $seo=file_get_contents($root.'/app/Http/Controllers/Public/SeoController.php');
        self::assertStringNotContainsString("['path' => '/lojas'",$seo);
        self::assertStringNotContainsString("['path'=>'/loja/'",$seo);
        $routes=file_get_contents($root.'/routes/web.php');
        self::assertStringContainsString("'/lojas'",$routes);
        self::assertStringContainsString("'/loja/{slug}'",$routes);
    }

    public function testInternalStoreManagementIsPreserved(): void
    {
        $root=dirname(__DIR__,2);
        $routes=file_get_contents($root.'/routes/admin.php');
        self::assertStringContainsString("'/lojas'",$routes);
        $controller=file_get_contents($root.'/app/Http/Controllers/Admin/StoreController.php');
        self::assertStringContainsString('final class StoreController',$controller);
    }
}
