<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Public\SeoController;
use PHPUnit\Framework\TestCase;

final class SeoControllerTest extends TestCase
{
    public function testRobotsProtectsPrivateAreasAndAdvertisesSitemap(): void
    {
        $previous=$_ENV['APP_URL']??null;$_ENV['APP_URL']='https://www.tuffer.com.br';
        try{$robots=(new SeoController())->robots();self::assertStringContainsString('Disallow: /admin/',$robots);self::assertStringContainsString('Disallow: /vendedor/',$robots);self::assertStringContainsString('Sitemap: https://www.tuffer.com.br/sitemap.xml',$robots);}
        finally{if($previous===null)unset($_ENV['APP_URL']);else $_ENV['APP_URL']=$previous;}
    }
}
