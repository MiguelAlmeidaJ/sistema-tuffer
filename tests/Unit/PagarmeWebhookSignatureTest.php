<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payments\PagarmeWebhookException;
use App\Services\Payments\PagarmeWebhookSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PagarmeWebhookSignatureTest extends TestCase
{
    #[DataProvider('algorithms')]
    public function testValidatesHmacOverTheUnmodifiedBody(string $algorithm): void
    {
        $body = '{"id":"hook_test","type":"order.paid","data":{"code":"TF-1"}}';
        $secret = 'webhook-secret-with-32-characters';
        $signature = $algorithm . '=' . hash_hmac($algorithm, $body, $secret);

        self::assertSame($algorithm, (new PagarmeWebhookSignature($secret))->verify($body, $signature));
    }

    public function testRejectsModifiedBody(): void
    {
        $secret = 'webhook-secret-with-32-characters';
        $signature = 'sha256=' . hash_hmac('sha256', '{"paid":true}', $secret);

        $this->expectException(PagarmeWebhookException::class);
        $this->expectExceptionMessage('inválida');
        (new PagarmeWebhookSignature($secret))->verify('{"paid":false}', $signature);
    }

    /** @return array<string,array{string}> */
    public static function algorithms(): array
    {
        return ['sha256' => ['sha256'], 'sha1 compatibility' => ['sha1']];
    }
}
