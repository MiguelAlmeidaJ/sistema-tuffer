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

    public function testSingleKitUsesOriginalDimensions(): void
    {
        self::assertNull($this->volume([['quantity'=>1,'shipping_weight'=>0.4]]));
    }

    public function testTwentyFourKitsMultiplyWeightAndStackHeight(): void
    {
        $volume=$this->volume([[
            'quantity'=>24,'shipping_weight'=>0.4,
            'shipping_width'=>25,'shipping_height'=>3,'shipping_length'=>24
        ]]);
        self::assertSame(9.6,$volume['weight']);
        self::assertSame(72.0,$volume['height']);
        self::assertSame(25.0,$volume['width']);
        self::assertSame(24.0,$volume['length']);
    }

    public function testMixedProductCartDoesNotGuessPackaging(): void
    {
        $this->expectException(RuntimeException::class);
        $this->volume([
            ['quantity'=>2,'shipping_weight'=>0.4],
            ['quantity'=>1,'shipping_weight'=>0.8],
        ]);
    }

    public function testExcessiveSingleCartonIsNotQuoted(): void
    {
        $this->expectException(RuntimeException::class);
        $this->volume([[
            'quantity'=>40,'shipping_weight'=>1,
            'shipping_width'=>25,'shipping_height'=>3,'shipping_length'=>24,
        ]]);
    }
}
