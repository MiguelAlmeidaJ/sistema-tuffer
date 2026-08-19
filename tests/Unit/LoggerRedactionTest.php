<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LoggerRedactionTest extends TestCase
{
    public function testRedactsSecretsRecursively(): void
    {
        $method=new ReflectionMethod(Logger::class,'sanitize');
        $sanitized=$method->invoke(null,['email'=>'cliente@example.com','password'=>'secret','nested'=>['authorization'=>'Bearer token','safe'=>'ok']]);
        self::assertSame('cliente@example.com',$sanitized['email']);
        self::assertSame('[REDACTED]',$sanitized['password']);
        self::assertSame('[REDACTED]',$sanitized['nested']['authorization']);
        self::assertSame('ok',$sanitized['nested']['safe']);
    }
}
