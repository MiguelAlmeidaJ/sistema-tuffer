<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PublicRootAccessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testConfiguredPublicSuffixIsRemovedFromGeneratedUrls(): void
    {
        $previous = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://shop.example.test/public/';

        try {
            self::assertSame('https://shop.example.test/auth/google/callback', absolute_url('/auth/google/callback'));
        } finally {
            if ($previous === null) unset($_ENV['APP_URL']); else $_ENV['APP_URL'] = $previous;
        }
    }

    public function testApacheConfigurationsRedirectVisiblePublicPrefix(): void
    {
        foreach (['/.htaccess', '/public/.htaccess'] as $relative) {
            $rules = file_get_contents($this->root . $relative);
            self::assertIsString($rules);
            self::assertStringContainsString('%{THE_REQUEST}', $rules);
            self::assertStringContainsString('^public(?:/(.*))?$', $rules);
            self::assertStringContainsString('[R=301,L,NE]', $rules);
        }
    }
}
