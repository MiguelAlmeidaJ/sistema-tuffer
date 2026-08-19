<?php

declare(strict_types=1);

namespace App\Services\Payments\Pagarme;

final class PagarmeRecipientEligibility
{
    public const LEGACY_KYC_NOT_REQUIRED = 'legacy_not_required';

    public static function effectiveKycStatus(string $recipientStatus, ?string $kycStatus): ?string
    {
        $recipientStatus = strtolower(trim($recipientStatus));
        $kycStatus = strtolower(trim((string) $kycStatus));
        if ($kycStatus !== '') {
            return $kycStatus;
        }
        return $recipientStatus === 'active' ? self::LEGACY_KYC_NOT_REQUIRED : null;
    }

    public static function isEligible(string $recipientStatus, ?string $kycStatus): bool
    {
        return strtolower(trim($recipientStatus)) === 'active'
            && in_array(
                self::effectiveKycStatus($recipientStatus, $kycStatus),
                ['approved', self::LEGACY_KYC_NOT_REQUIRED],
                true
            );
    }
}
