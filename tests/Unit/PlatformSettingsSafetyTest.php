<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Admin\SettingsController;
use App\Services\Media\MediaStoragePolicy;
use App\Services\Media\SiteUploadService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class PlatformSettingsSafetyTest extends TestCase
{
    public function testThemeHelpersAcceptOnlyKnownValuesAndSafeColors(): void
    {
        self::assertSame(
            '--brand-red:#123456;--brand-red-dark:#123456;--green-800:#123456;--green-700:#123456;--brand-black:#080808;--green-950:#080808;--brand-gold:#ABCDEF',
            platform_theme_style([
                'primary_color' => '#123456',
                'secondary_color' => 'url(javascript:alert(1))',
                'accent_color' => '#abcdef',
            ])
        );

        self::assertSame(
            'theme-mode-light theme-font-editorial theme-buttons-rounded',
            platform_theme_classes([
                'color_mode' => 'unexpected',
                'typography' => '../invalid',
                'button_style' => '<script>',
            ])
        );
    }

    public function testManagedDeletionCannotReachFilesOutsideGeneratedPlatformAssets(): void
    {
        $service = new SiteUploadService();

        self::assertFalse($service->deleteManaged('../.env'));
        self::assertFalse($service->deleteManaged('platform/logos/logo.svg'));
        self::assertFalse($service->deleteManaged('platform/logos/default-logo.png'));
    }

    public function testManagedGeneratedAssetCanBeDeleted(): void
    {
        $root = MediaStoragePolicy::siteMediaRoot();
        $directory = $root . '/platform/site';
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0755, true) || is_dir($directory));
        }

        $filename = str_repeat('a', 32) . '.png';
        $absolutePath = $directory . '/' . $filename;
        file_put_contents($absolutePath, 'test');

        try {
            self::assertTrue((new SiteUploadService())->deleteManaged('platform/site/' . $filename));
            self::assertFileDoesNotExist($absolutePath);
        } finally {
            if (is_file($absolutePath)) {
                unlink($absolutePath);
            }
        }
    }

    public function testSettingsSanitizationNormalizesOperationalValues(): void
    {
        $sanitize = new ReflectionMethod(SettingsController::class, 'sanitize');
        $controller = new SettingsController();

        self::assertSame('#ABCDEF', $sanitize->invoke($controller, 'primary_color', '#abcdef'));
        self::assertSame('12.50', $sanitize->invoke($controller, 'default_commission', '12,5'));
        self::assertSame('TF-2026', $sanitize->invoke($controller, 'orders_prefix', 'tf-2026'));
        self::assertSame('1440', $sanitize->invoke($controller, 'admin_session_timeout', '99999'));
    }

    public function testSettingsSanitizationRejectsUnsafeColorValue(): void
    {
        $sanitize = new ReflectionMethod(SettingsController::class, 'sanitize');

        $this->expectException(RuntimeException::class);
        $sanitize->invoke(new SettingsController(), 'primary_color', 'red; background:url(x)');
    }

    public function testBannerLinksAcceptInternalAndHttpDestinations(): void
    {
        $sanitize = new ReflectionMethod(SettingsController::class, 'sanitize');
        $controller = new SettingsController();

        self::assertSame('/ofertas?campanha=inverno', $sanitize->invoke($controller, 'home_main_banner_link', '/ofertas?campanha=inverno'));
        self::assertSame('https://parceiro.example/oferta', $sanitize->invoke($controller, 'home_main_banner_link', 'https://parceiro.example/oferta'));
        self::assertSame('/ofertas', platform_link_href('/ofertas', '/produtos'));
        self::assertTrue(platform_link_is_external(platform_link_href('https://parceiro.example', '/produtos')));
    }

    public function testBannerLinksRejectExecutableAndProtocolRelativeDestinations(): void
    {
        $sanitize = new ReflectionMethod(SettingsController::class, 'sanitize');

        foreach (['javascript:alert(1)', '//evil.example/banner'] as $invalid) {
            try {
                $sanitize->invoke(new SettingsController(), 'home_main_banner_link', $invalid);
                self::fail('O link inseguro deveria ter sido rejeitado.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }

        self::assertSame('/produtos', platform_link_href('javascript:alert(1)', '/produtos'));
    }
}
