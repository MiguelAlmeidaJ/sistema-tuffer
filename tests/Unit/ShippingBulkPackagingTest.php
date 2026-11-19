<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Shipping\ShippingQuoteService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class ShippingBulkPackagingTest extends TestCase
{
    private function volume(array $items): ?array
    {
        $method=(new ReflectionClass(ShippingQuoteService::class))->getMethod('volumeForQuantity');
        $method->setAccessible(true);
        return $method->invoke(new ShippingQuoteService(),$items);
    }

    public function testSingleKitUsesStandardQuote(): void
    {
        self::assertNull($this->volume([['quantity'=>1,'shipping_weight'=>0.4]]));
    }

    public function testTwentyFourKitsCalculateWeightAutomatically(): void
    {
        self::assertSame([
            'height'=>32.0,'width'=>40.0,'length'=>50.0,'weight'=>9.6,
        ],$this->volume([[
            'quantity'=>24,'shipping_weight'=>0.4,
            'shipping_width'=>25,'shipping_height'=>3,'shipping_length'=>24,
            'shipping_box_capacity'=>24,'shipping_box_tare_kg'=>0.5,
            'shipping_box_width'=>40,'shipping_box_height'=>32,'shipping_box_length'=>50,
        ]]));
    }

    public function testMissingRealBoxNeverShowsFictitiousQuote(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ainda não está cadastrada');
        $this->volume([['quantity'=>24,'shipping_weight'=>0.4,
            'shipping_width'=>25,'shipping_height'=>3,'shipping_length'=>24]]);
    }

    public function testCapacitySmallerThanQuantityIsBlocked(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vários volumes');
        $this->volume([['quantity'=>24,'shipping_weight'=>0.4,
            'shipping_box_capacity'=>10]]);
    }

    public function testMixedProductsRequireVerifiedPackaging(): void
    {
        $this->expectException(RuntimeException::class);
        $this->volume([['quantity'=>2],['quantity'=>1]]);
    }

    public function testQuotePostsMeasuredVolumes(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/app/Services/Shipping/ShippingQuoteService.php');
        self::assertStringContainsString("'volumes'",$source);
        self::assertStringContainsString('$unitWeight * $qty',$source);
        self::assertStringNotContainsString('$height=(float)($item[\'shipping_height\']??0)*$quantity',$source);
    }
}
