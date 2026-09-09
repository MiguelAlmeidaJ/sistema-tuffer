<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use RuntimeException;

final class DisabledFiscalProvider implements FiscalProvider
{
    public function __construct(private readonly string $providerName = 'disabled') {}
    public function name(): string { return $this->providerName; }
    public function configured(): bool { return false; }
    public function issue(array $document): array { throw new RuntimeException('Nenhum provedor fiscal foi configurado para emissão de NF-e.'); }
    public function cancel(array $document, string $reason): array { throw new RuntimeException('Nenhum provedor fiscal foi configurado para cancelamento de NF-e.'); }
}
