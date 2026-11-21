<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Auth\SocialAuthService;
use PHPUnit\Framework\TestCase;

final class GuestAccountGateTest extends TestCase
{
    public function testGuestModalAppearsOnlyInPublicLayout(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = file_get_contents($root . '/resources/views/layouts/public.php');
        self::assertStringContainsString("if (empty(\$authUser))", $layout);
        self::assertStringContainsString('components/public/account-gate.php', $layout);
        self::assertStringContainsString('js/account-gate.js', $layout);
        self::assertStringNotContainsString('account-gate.php', file_get_contents($root.'/resources/views/layouts/auth.php'));
    }

    public function testModalHasLoginRegistrationAndGoogleLinks(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root . '/resources/views/components/public/account-gate.php');
        self::assertStringContainsString('data-delay="5000"', $view);
        self::assertStringContainsString('csrf_field()', $view);
        self::assertStringContainsString('name="email"', $view);
        self::assertStringContainsString('name="password"', $view);
        self::assertStringContainsString('/cadastro?redirect=', $view);
        self::assertStringContainsString('/auth/google?redirect=', $view);
        self::assertStringNotContainsString('data-account-gate-close', $view);
        $js = file_get_contents($root . '/public/assets/js/account-gate.js');
        self::assertStringContainsString('showModal()', $js);
        self::assertStringContainsString("event.preventDefault()", $js);
    }

    public function testOAuthReturnRedirectRejectsExternalUrls(): void
    {
        self::assertSame('', SocialAuthService::safeReturnPath('//evil.example/'));
        self::assertSame('', SocialAuthService::safeReturnPath('https://evil.example/'));
        self::assertSame('', SocialAuthService::safeReturnPath('/auth/google'));
        self::assertSame('/produto/roupa?color=blue', SocialAuthService::safeReturnPath('/produto/roupa?color=blue'));
    }
}
