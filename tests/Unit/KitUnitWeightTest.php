<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
final class KitUnitWeightTest extends TestCase
{
    public function testEditorCollectsPiecesAndPerPieceWeight(): void
    {
        $root=dirname(__DIR__,2);
        $view=file_get_contents($root.'/resources/views/seller/products/form.php');
        $controller=file_get_contents($root.'/app/Http/Controllers/Seller/ProductController.php');
        self::assertStringContainsString('name="pieces_per_kit"',$view);
        self::assertStringContainsString('name="piece_weight_grams"',$view);
        self::assertStringContainsString('$pieceWeight * $pieces / 1000',$controller);
        self::assertStringContainsString("'weight' => $kitWeight",$controller);
    }
    public function testCartAndProductPagePreferCalculatedKitWeight(): void
    {
        $root=dirname(__DIR__,2);
        foreach ([
            '/app/Services/Cart/CartService.php',
            '/app/Http/Controllers/Public/ProductController.php',
            '/app/Services/Shipping/ShippingQuoteService.php',
            '/app/Services/Shipping/MelhorEnvioLabelService.php',
        ] as $file) {
            $contents=file_get_contents($root.$file);
            self::assertStringContainsString('piece_weight_grams IS NOT NULL',$contents,$file);
        }
    }
}
