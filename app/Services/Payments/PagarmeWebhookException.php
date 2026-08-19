<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

final class PagarmeWebhookException extends RuntimeException
{
    public function __construct(string $message, private readonly int $httpStatus = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
