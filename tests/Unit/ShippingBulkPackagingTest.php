<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Shipping\ShippingQuoteService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class ShippingBulkPackagingTest extends TestCase
{
    public function testSingleUnitCanUseStandardProductQuote(): void
    {
        $method=(new ReflectionClass(ShippingQuoteService::class))->getMethod('volumeForQuantity');
        $method->setAccessible(true);
        $service=new ShippingQuoteService();
        self::assertNull($method->invoke($service,[[
            'variant_id'=>9,'quantity'=>1,'shipping_weight'=>0.4,
        ]]));
    }

    public function testMixedBulkCartRequiresManualPackaging(): void
    {
        $method=(new ReflectionClass(ShippingQuoteService::class))->getMethod('volumeForQuantity');
        $method->setAccessible(true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('embalagem final');
        $method->invoke(new ShippingQuoteService(),[
            ['variant_id'=>1,'quantity'=>24],
            ['variant_id'=>2,'quantity'=>1],
        ]);
    }

    public function testBulkUsesQuantityRuleInsteadOfSingleProductDimensions(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/app/Services/Shipping/ShippingQuoteService.php');
        self::assertStringContainsString('product_shipping_rules',$source);
        self::assertStringContainsString("'volumes'",$source);
        self::assertStringContainsString('$explicitVolume === null ? $this->packages',$source);
        self::assertStringContainsString('minimum_quantity<=?',$source);
        self::assertStringContainsString('maximum_quantity>=?',$source);
        self::assertStringContainsString('!$containsBulk',$source);
    }
}
