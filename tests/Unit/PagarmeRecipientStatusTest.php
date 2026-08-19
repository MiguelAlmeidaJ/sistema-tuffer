<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\Pagarme\PagarmeRecipientService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PagarmeRecipientStatusTest extends TestCase
{
    #[DataProvider('statuses')]
    public function testMapsProviderStatusesToLocalOnboarding(
        string $recipientStatus,
        string $kycStatus,
        string $reason,
        string $expected
    ): void {
        self::assertSame(
            $expected,
            PagarmeRecipientService::mapOnboardingStatus($recipientStatus, $kycStatus, $reason)
        );
    }

    /** @return array<string,array{string,string,string,string}> */
    public static function statuses(): array
    {
        return [
            'approved' => ['active', 'approved', 'ok', 'active'],
            'registration' => ['registration', '', '', 'registration_pending'],
            'kyc required' => ['affiliation', 'partially_denied', 'additional_documents_required', 'kyc_pending'],
            'analysis' => ['affiliation', 'pending', 'in_analysis', 'analyzing'],
            'refused' => ['refused', 'denied', 'fully_denied', 'rejected'],
            'blocked' => ['blocked', 'approved', 'ok', 'blocked'],
            'suspended' => ['suspended', 'pending', 'in_analysis', 'blocked'],
        ];
    }
}
