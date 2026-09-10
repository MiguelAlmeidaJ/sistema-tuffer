<?php

declare(strict_types=1);

use App\Services\Fiscal\FiscalProfileValidator;
use PHPUnit\Framework\TestCase;

final class FiscalProfileValidatorTest extends TestCase
{
    private function validSeller(): array{return ['legal_name'=>'Tuffer Ltda','document'=>'12ABC34501DE35','state_registration'=>'123456'];}
    private function validProfile(): array{return ['enabled'=>1,'tax_regime'=>'Simples Nacional','crt'=>'1','state_registration_indicator'=>'contributor','postal_code'=>'30110000','street'=>'Rua A','number'=>'10','neighborhood'=>'Centro','city'=>'Belo Horizonte','state'=>'MG','city_ibge_code'=>'3106200','nfe_series'=>1,'fiscal_email'=>'fiscal@example.com'];}
    public function testAcceptsAlphanumericIssuerDocument(): void{self::assertSame([],(new FiscalProfileValidator())->sellerErrors($this->validSeller(),$this->validProfile()));}
    public function testContributorRequiresStateRegistration(): void{$seller=$this->validSeller();$seller['state_registration']='';self::assertNotEmpty((new FiscalProfileValidator())->sellerErrors($seller,$this->validProfile()));}
    public function testExemptIssuerMayHaveEmptyStateRegistration(): void{$seller=$this->validSeller();$seller['state_registration']='';$profile=$this->validProfile();$profile['state_registration_indicator']='exempt';self::assertSame([],(new FiscalProfileValidator())->sellerErrors($seller,$profile));}
    public function testRtcFieldsAreRequiredWhenEnabled(): void{$item=['sku'=>'SKU-1','ncm'=>'61071100','origin'=>0,'commercial_unit'=>'UN','tributary_unit'=>'UN','cfop_in_state'=>'5102','cfop_out_state'=>'6102','icms_code'=>'102','pis_code'=>'49','cofins_code'=>'49'];$errors=(new FiscalProfileValidator())->itemErrors($item,'MG','SP',true);self::assertTrue((bool)array_filter($errors,static fn(string $error):bool=>str_contains($error,'IBS/CBS')));}
}
