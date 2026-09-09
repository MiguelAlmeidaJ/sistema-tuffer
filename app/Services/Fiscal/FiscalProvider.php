<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

interface FiscalProvider
{
    public function name(): string;
    public function configured(): bool;

    /** @param array<string,mixed> $document @return array<string,mixed> */
    public function issue(array $document): array;

    /** @param array<string,mixed> $document @return array<string,mixed> */
    public function cancel(array $document, string $reason): array;
}
