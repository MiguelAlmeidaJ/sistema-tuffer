<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LoginExperienceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testLoginCardKeepsOnlyGoogleAndPlacesLogoBeforeWelcome(): void
    {
        $view = file_get_contents($this->root . '/resources/views/auth/login.php');

        self::assertIsString($view);
        self::assertStringContainsString('class="auth-login__logo"', $view);
        self::assertStringContainsString('class="auth-eyebrow">BEM-VINDO', $view);
        self::assertLessThan(strpos($view, 'class="auth-eyebrow"'), strpos($view, 'class="auth-login__logo"'));
        self::assertSame(1, substr_count($view, 'class="auth-social__button"'));
        self::assertStringContainsString("url('/auth/google')", $view);
        self::assertStringNotContainsString('facebook', mb_strtolower($view));
        self::assertStringNotContainsString('auth-login__brand', $view);
    }

    public function testOnlyExplicitGoogleSocialRoutesRemain(): void
    {
        $routes = file_get_contents($this->root . '/routes/auth.php');
        $controller = file_get_contents($this->root . '/app/Http/Controllers/Auth/SocialAuthController.php');

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertStringContainsString("'/auth/google'", $routes);
        self::assertStringContainsString("'/auth/google/callback'", $routes);
        self::assertStringNotContainsString("'/auth/{provider}'", $routes);
        self::assertStringNotContainsString('facebook.com', mb_strtolower($controller));
    }
}
