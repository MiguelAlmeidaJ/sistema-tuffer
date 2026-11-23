<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Shipping\MelhorEnvioEnvironment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IntegrationEnvironmentModeTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $original=[];

    protected function setUp(): void
    {
        foreach(['MELHOR_ENVIO_SANDBOX','MELHOR_ENVIO_BASE_URL'] as $key) $this->original[$key]=$_ENV[$key]??null;
    }

    protected function tearDown(): void
    {
        foreach($this->original as $key=>$value) {
            if($value===null)unset($_ENV[$key]);else $_ENV[$key]=$value;
        }
    }

    public function testDefaultModeIsSandbox(): void
    {
        unset($_ENV['MELHOR_ENVIO_SANDBOX'],$_ENV['MELHOR_ENVIO_BASE_URL']);
        self::assertTrue(MelhorEnvioEnvironment::sandbox());
        self::assertSame('https://sandbox.melhorenvio.com.br',MelhorEnvioEnvironment::baseUrl());
    }

    public function testProductionUsesOfficialHost(): void
    {
        $_ENV['MELHOR_ENVIO_SANDBOX']='false';
        $_ENV['MELHOR_ENVIO_BASE_URL']='';
        self::assertSame('https://melhorenvio.com.br',MelhorEnvioEnvironment::baseUrl());
    }

    public function testProductionCannotPointToSandbox(): void
    {
        $_ENV['MELHOR_ENVIO_SANDBOX']='false';
        $_ENV['MELHOR_ENVIO_BASE_URL']='https://sandbox.melhorenvio.com.br';
        $this->expectException(RuntimeException::class);
        MelhorEnvioEnvironment::baseUrl();
    }

    public function testSandboxCannotPointToProduction(): void
    {
        $_ENV['MELHOR_ENVIO_SANDBOX']='true';
        $_ENV['MELHOR_ENVIO_BASE_URL']='https://melhorenvio.com.br';
        $this->expectException(RuntimeException::class);
        MelhorEnvioEnvironment::baseUrl();
    }

    public function testDisallowsThirdPartyHost(): void
    {
        $_ENV['MELHOR_ENVIO_SANDBOX']='false';
        $_ENV['MELHOR_ENVIO_BASE_URL']='https://melhorenvio.com.br.evil.test';
        $this->expectException(RuntimeException::class);
        MelhorEnvioEnvironment::baseUrl();
    }
}
