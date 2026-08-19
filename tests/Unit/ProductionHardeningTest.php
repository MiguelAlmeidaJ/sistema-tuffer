<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Http\Controllers\Customer\AddressController;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\PasswordPolicy;
use App\Services\Products\ProductExportService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ProductionHardeningTest extends TestCase
{
    public function testPasswordPolicyRequiresEveryCharacterClass(): void
    {
        self::assertNull(PasswordPolicy::error('SenhaForte#2026'));
        self::assertNotNull(PasswordPolicy::error('curta#A1'));
        self::assertNotNull(PasswordPolicy::error('semsimboloA123'));
        self::assertNotNull(PasswordPolicy::error('SEMMINUSCULA#123'));
        self::assertNotNull(PasswordPolicy::error('semmaiuscula#123'));
        self::assertNotNull(PasswordPolicy::error('SemNumeroAlgum#'));
    }

    public function testLoginIsBlockedAfterFiveFailuresAndCanBeCleared(): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $throttle = new LoginThrottle($pdo);
            $email = bin2hex(random_bytes(8)) . '@example.test';
            $ip = '192.0.2.' . random_int(1, 250);
            for ($attempt = 1; $attempt <= 4; $attempt++) {
                $throttle->recordFailure($email, $ip);
                self::assertFalse($throttle->blocked($email, $ip));
            }
            $throttle->recordFailure($email, $ip);
            self::assertTrue($throttle->blocked($email, $ip));
            $throttle->clear($email, $ip);
            self::assertFalse($throttle->blocked($email, $ip));
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    public function testCsvCellsCannotExecuteSpreadsheetFormulas(): void
    {
        $method = new ReflectionMethod(ProductExportService::class, 'spreadsheetCell');
        $service = new ProductExportService();
        self::assertSame("'=HYPERLINK(\"https://example.test\")", $method->invoke($service, '=HYPERLINK("https://example.test")'));
        self::assertSame("'  +1+1", $method->invoke($service, '  +1+1'));
        self::assertSame('Produto normal', $method->invoke($service, 'Produto normal'));
    }

    public function testInternalReturnPathRejectsProtocolRelativeAndBackslashPaths(): void
    {
        $method = new ReflectionMethod(AddressController::class, 'safeReturn');
        $controller = new AddressController();
        self::assertSame('/checkout?etapa=entrega', $method->invoke($controller, '/checkout?etapa=entrega'));
        self::assertSame('', $method->invoke($controller, '//evil.example/path'));
        self::assertSame('', $method->invoke($controller, '/\\evil.example/path'));
        self::assertSame('', $method->invoke($controller, "/checkout\r\nLocation: https://evil.example"));
    }
}
