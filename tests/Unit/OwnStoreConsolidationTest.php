<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;

final class OwnStoreConsolidationTest extends TestCase
{
    public function testConsolidationPreservesHistoricalSellerOrders(): void
    {
        $sql=file_get_contents(dirname(__DIR__,2).'/database/migrations/048_consolidate_own_stores.sql');
        self::assertIsString($sql);
        self::assertStringContainsString('store_consolidations',$sql);
        self::assertStringContainsString('brand_id',$sql);
        self::assertStringContainsString('UPDATE products p',$sql);
        self::assertStringNotContainsString('UPDATE seller_orders', $sql);
        self::assertStringNotContainsString('DELETE FROM', $sql);
    }

    public function testSellerOrdersAggregateLegacyStoresInOnePanel(): void
    {
        $root=dirname(__DIR__,2);
        foreach ([
            '/app/Http/Controllers/Seller/OrderController.php',
            '/app/Http/Controllers/Seller/DashboardController.php',
            '/app/Services/Stores/SellerStoreContext.php',
        ] as $file) {
            $source=file_get_contents($root.$file);
            self::assertIsString($source);
            self::assertStringContainsString('store_consolidations',$source,$file);
        }
    }

    public function testEveryPublicPageShowsTestEnvironmentWarning(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/resources/views/layouts/public.php');
        self::assertStringContainsString('AMBIENTE DE TESTES',$source);
        self::assertStringContainsString('temporariamente suspensos',$source);
    }
}
