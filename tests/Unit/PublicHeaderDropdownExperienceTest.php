<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PublicHeaderDropdownExperienceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testPrivacyAndPoliciesDoNotTriggerGuestLoginDialog(): void
    {
        $layout=file_get_contents($this->root.'/resources/views/layouts/public.php');
        self::assertStringContainsString("'/politica-de-privacidade'", $layout);
        self::assertStringContainsString("'/politicas/'", $layout);
        self::assertStringContainsString('&& !$isPrivacyPage', $layout);
        self::assertStringContainsString('account-gate.php', $layout);
    }

    public function testAccountDropdownContainsRequestedRoutesAndProtectedLogout(): void
    {
        $header=file_get_contents($this->root.'/resources/views/components/public/header.php');
        self::assertStringContainsString('data-header-menu', $header);
        self::assertStringContainsString('/minha-conta/pedidos', $header);
        self::assertStringContainsString('/minha-conta/enderecos', $header);
        self::assertStringContainsString('/minha-conta/perfil', $header);
        self::assertStringContainsString('csrf_field()', $header);
        self::assertStringContainsString("'/sair'", $header);
        self::assertStringNotContainsString('icon-action--logout', $header);
        self::assertStringContainsString('/minha-conta/notificacoes', $header);
    }

    public function testNotificationDropdownUsesOwnedUserRecords(): void
    {
        $header=file_get_contents($this->root.'/resources/views/components/public/header.php');
        self::assertStringContainsString('FROM user_notifications WHERE user_id=?', $header);
        self::assertStringContainsString("[(int)\$authUser['id']]", $header);
        self::assertStringContainsString('data-header-panel', $header);
    }

    public function testPopupIsCompactAndMenusAreKeyboardAccessible(): void
    {
        $css=file_get_contents($this->root.'/public/assets/css/public/account-gate.css');
        self::assertStringContainsString('overflow:visible', $css);
        self::assertStringContainsString('max-height:calc(100dvh - 24px)', $css);
        $menuJs=file_get_contents($this->root.'/public/assets/js/header-dropdowns.js');
        self::assertStringContainsString("event.key === 'Escape'", $menuJs);
        self::assertStringContainsString("'aria-expanded'", $menuJs);
    }
}
