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

    public function testLoginKeepsBrandBeforeWelcomeAndSocialLoginIsDisabled(): void
    {
        $view = file_get_contents($this->root . '/resources/views/auth/login.php');
        self::assertIsString($view);
        self::assertStringNotContainsString('auth-social__button', $view);
        self::assertStringNotContainsString('/auth/google', $view);
        self::assertStringNotContainsString('ou entre com seu e-mail', mb_strtolower($view));
        self::assertStringContainsString('auth-login__logo', $view);
        self::assertStringContainsString('Criar minha conta', $view);
        self::assertStringContainsString('auth-create-account__cta-label', $view);
        self::assertLessThan(
            strpos($view, 'BEM-VINDO'),
            strpos($view, 'auth-login__logo')
        );
    }

    public function testSocialAuthenticationRoutesAreNotExposed(): void
    {
        $routes = file_get_contents($this->root . '/routes/auth.php');
        self::assertIsString($routes);
        self::assertStringNotContainsString('/auth/google', $routes);
        self::assertStringNotContainsString('SocialAuthController', $routes);
    }

    public function testLoginStylesKeepTheAccountCreationLabelVisible(): void
    {
        $css = file_get_contents($this->root . '/public/assets/css/customer-flow.css');
        self::assertIsString($css);
        self::assertStringContainsString('.auth-create-account__cta-label', $css);
        self::assertStringContainsString('color: #fff !important', $css);
        self::assertStringContainsString('visibility: visible !important', $css);
    }
}
