<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\Pagarme\PagarmeRecipientId;
use PHPUnit\Framework\TestCase;

final class PagarmeRecipientIdTest extends TestCase
{
    public function testAcceptsCurrentAndLegacyRecipientPrefixes(): void
    {
        self::assertTrue(PagarmeRecipientId::isValid('re_cmb123ABC36w2'));
        self::assertTrue(PagarmeRecipientId::isValid('rp_legacy123'));
    }

    public function testRejectsMissingUnsafeOrWrongResourceIdentifiers(): void
    {
        self::assertFalse(PagarmeRecipientId::isValid(''));
        self::assertFalse(PagarmeRecipientId::isValid('or_order123'));
        self::assertFalse(PagarmeRecipientId::isValid('re_abc/../secret'));
        self::assertFalse(PagarmeRecipientId::isValid('re_abc?query=1'));
    }
}
