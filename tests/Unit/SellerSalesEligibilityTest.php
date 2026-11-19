<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Services\Sellers\SellerSalesEligibility;
use PHPUnit\Framework\TestCase;
final class SellerSalesEligibilityTest extends TestCase {
    public function testActiveStoreNoLongerDependsOnPagarme(): void {
        $service=new SellerSalesEligibility();
        self::assertTrue($service->canSell(['status'=>'active','payment_enabled'=>0,'pagarme_recipient_id'=>null]));
        self::assertFalse($service->canSell(['status'=>'pending']));
        self::assertFalse($service->canSell(['status'=>'active','store_status'=>'draft']));
    }
    public function testCartHasNoPagarmeRecipientFilter(): void {
        $source=file_get_contents(dirname(__DIR__,2).'/app/Services/Cart/CartService.php');
        self::assertStringNotContainsString('pagarme_recipient_id',$source);
    }
}
