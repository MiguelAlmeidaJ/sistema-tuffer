<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Services\Shipping\ShippingQuoteService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
final class ShippingBulkPackagingTest extends TestCase
{
    public function testUnitsAreSentToTheProviderForPacking(): void
    {
        $method=(new ReflectionClass(ShippingQuoteService::class))->getMethod('volumeForQuantity');
        $method->setAccessible(true);
        self::assertNull($method->invoke(new ShippingQuoteService(),[
            ['quantity'=>24,'shipping_weight'=>0.040,'shipping_width'=>25,'shipping_height'=>3,'shipping_length'=>24]
        ]));
    }
    public function testQuoteUsesProductsAndQuantityAndPreservesProviderPackages(): void
    {
        $src=file_get_contents(dirname(__DIR__,2).'/app/Services/Shipping/ShippingQuoteService.php');
        self::assertStringContainsString("'products'",$src);
        self::assertStringContainsString("'quantity' => min(100",$src);
        self::assertStringContainsString('$quote[\'packages\'] ?? []',$src);
        self::assertStringContainsString('product-unit-dimensions-v6',$src);
    }
}
