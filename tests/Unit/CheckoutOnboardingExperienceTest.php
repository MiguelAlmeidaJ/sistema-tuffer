<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CheckoutOnboardingExperienceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testCustomerRegistrationCollectsCheckoutIdentityAndLogsIn(): void
    {
        $view = file_get_contents($this->root . '/resources/views/auth/register.php');
        $controller = file_get_contents($this->root . '/app/Http/Controllers/Auth/RegisterController.php');

        self::assertIsString($view);
        self::assertIsString($controller);
        self::assertStringContainsString('name="phone"', $view);
        self::assertStringContainsString('name="document"', $view);
        self::assertStringContainsString('data-cpf-input', $view);
        self::assertStringContainsString('data-phone-input', $view);
        self::assertStringContainsString('INSERT INTO users (name, email, phone, document, password_hash', $controller);
        self::assertStringContainsString('Auth::login($user)', $controller);
        self::assertStringContainsString("return Response::redirect(\$redirect !== '' ? \$redirect : '/minha-conta');", $controller);
    }

    public function testLoginAndRegistrationPreserveThePurchaseReturnPath(): void
    {
        $login = file_get_contents($this->root . '/app/Http/Controllers/Auth/LoginController.php');
        $registerView = file_get_contents($this->root . '/resources/views/auth/register.php');
        $loginView = file_get_contents($this->root . '/resources/views/auth/login.php');

        self::assertIsString($login);
        self::assertIsString($registerView);
        self::assertIsString($loginView);
        self::assertStringContainsString("'redirectPath' => \$redirect", $login);
        self::assertStringContainsString('name="redirect"', $loginView);
        self::assertStringContainsString('name="redirect"', $registerView);
        self::assertStringContainsString("if (\$user['type'] === 'customer' && \$redirect !== '')", $login);
    }

    public function testRegistrationStepCanActuallyHideInactivePanel(): void
    {
        $css = file_get_contents($this->root . '/public/assets/css/commerce-ux.css');

        self::assertIsString($css);
        self::assertStringContainsString('.auth-step[hidden]', $css);
        self::assertStringContainsString('display: none !important;', $css);
    }

    public function testAddressFormUsesCepLookupAndKeepsIbgeTechnicalFieldHidden(): void
    {
        $view = file_get_contents($this->root . '/resources/views/customer/addresses/form.php');
        $javascript = file_get_contents($this->root . '/public/assets/js/commerce-ux.js');

        self::assertIsString($view);
        self::assertIsString($javascript);
        self::assertStringContainsString('data-address-cep', $view);
        self::assertStringContainsString('type="hidden" name="city_ibge_code"', $view);
        self::assertStringNotContainsString('Código IBGE do município', $view);
        self::assertStringContainsString('viacep.com.br/ws/', $javascript);
        self::assertStringContainsString('data.ibge', $javascript);
    }
}
