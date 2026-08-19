<?php

declare(strict_types=1);

namespace App\Services\Payments;

interface PagarmeApiClient
{
    public function configured(): bool;

    public function environment(): string;

    /** @return array<string,mixed> */
    public function get(string $endpoint): array;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function post(string $endpoint, array $payload = [], ?string $idempotencyKey = null): array;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function put(string $endpoint, array $payload, ?string $idempotencyKey = null): array;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function delete(string $endpoint, array $payload = [], ?string $idempotencyKey = null): array;
}
