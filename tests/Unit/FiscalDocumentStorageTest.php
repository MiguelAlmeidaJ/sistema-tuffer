<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Fiscal\FiscalConfiguration;
use App\Services\Fiscal\FiscalDocumentStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FiscalDocumentStorageTest extends TestCase
{
    private string $relativeStorage;
    private string $absoluteStorage;
    private mixed $previousStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousStorage = $_ENV['FISCAL_PRIVATE_STORAGE'] ?? null;
        $this->relativeStorage = 'storage/private/fiscal-test-' . bin2hex(random_bytes(6));
        $this->absoluteStorage = dirname(__DIR__, 2) . '/' . $this->relativeStorage;
        $_ENV['FISCAL_PRIVATE_STORAGE'] = $this->relativeStorage;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->absoluteStorage);
        if ($this->previousStorage === null) unset($_ENV['FISCAL_PRIVATE_STORAGE']);
        else $_ENV['FISCAL_PRIVATE_STORAGE'] = $this->previousStorage;
        parent::tearDown();
    }

    public function testStoresNamespacedNfeXmlAndDanfePrivately(): void
    {
        $key = str_repeat('1', 44);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><NFe><infNFe Id="NFe' . $key . '"><ide/></infNFe></NFe></nfeProc>';
        $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

        $storage = new FiscalDocumentStorage(new FiscalConfiguration());
        $paths = $storage->store(987654, 123456, $key, $xml, $pdf);

        self::assertNotNull($paths['xml_storage_path']);
        self::assertNotNull($paths['danfe_storage_path']);
        self::assertFileExists(dirname(__DIR__, 2) . '/' . $paths['xml_storage_path']);
        self::assertFileExists(dirname(__DIR__, 2) . '/' . $paths['danfe_storage_path']);
        self::assertStringStartsWith($this->relativeStorage . '/seller-987654/', (string) $paths['xml_storage_path']);
    }

    public function testRejectsXmlWhoseAccessKeyDoesNotMatch(): void
    {
        $xmlKey = str_repeat('1', 44);
        $requestedKey = str_repeat('2', 44);
        $xml = '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe' . $xmlKey . '"><ide/></infNFe></NFe>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não corresponde ao XML');
        (new FiscalDocumentStorage(new FiscalConfiguration()))->store(1, 1, $requestedKey, $xml, null);
    }

    public function testRejectsDoctypeAndEntitiesBeforeParsing(): void
    {
        $key = str_repeat('1', 44);
        $xml = '<!DOCTYPE NFe [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><NFe><infNFe Id="NFe' . $key . '">&xxe;</infNFe></NFe>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não pode declarar DTD');
        (new FiscalDocumentStorage(new FiscalConfiguration()))->store(1, 1, $key, $xml, null);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        $items = scandir($directory);
        if (!is_array($items)) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) $this->removeDirectory($path);
            else @unlink($path);
        }
        @rmdir($directory);
    }
}
