<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;
use App\Services\Auth\SocialAuthService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SocialAuthSafetyTest extends TestCase
{
    public function testGoogleLoginRequiresServerSideCredentials(): void
    {
        $previousId = $_ENV['GOOGLE_CLIENT_ID'] ?? null;
        $previousSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? null;
        $_ENV['GOOGLE_CLIENT_ID'] = '';
        $_ENV['GOOGLE_CLIENT_SECRET'] = '';

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ainda não foi configurado');
            (new SocialAuthService())->authorizationUrl('google');
        } finally {
            if ($previousId === null) unset($_ENV['GOOGLE_CLIENT_ID']); else $_ENV['GOOGLE_CLIENT_ID'] = $previousId;
            if ($previousSecret === null) unset($_ENV['GOOGLE_CLIENT_SECRET']); else $_ENV['GOOGLE_CLIENT_SECRET'] = $previousSecret;
        }
    }

    public function testUnknownSocialProviderIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Provedor de login inválido');
        (new SocialAuthService())->authorizationUrl('unknown');
    }

    public function testFacebookLoginIsNoLongerAccepted(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Provedor de login inválido');
        (new SocialAuthService())->authorizationUrl('facebook');
    }

    public function testExternalRedirectRejectsUntrustedHosts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::redirectExternal('https://example.com/oauth', ['accounts.google.com']);
    }
}
