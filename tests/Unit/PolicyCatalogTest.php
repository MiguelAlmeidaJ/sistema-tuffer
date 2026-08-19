<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Content\PolicyCatalog;
use PHPUnit\Framework\TestCase;

final class PolicyCatalogTest extends TestCase
{
    /** @return array<string,string> */
    private function settings(): array
    {
        return [
            'platform_name' => 'Tuffer',
            'legal_name' => 'Tuffer Comércio Eletrônico Ltda.',
            'tax_id' => '00.000.000/0001-00',
            'support_email' => 'atendimento@example.com',
            'privacy_email' => 'privacidade@example.com',
            'default_commission' => '12',
        ];
    }

    public function testCatalogContainsAllTwentyMappedPolicies(): void
    {
        $policies = PolicyCatalog::all($this->settings());

        self::assertCount(20, $policies);
        self::assertSame(
            ['compradores', 'vendedores', 'institucional'],
            array_keys(PolicyCatalog::groups())
        );

        foreach ($policies as $slug => $policy) {
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
            self::assertArrayHasKey($policy['group'], PolicyCatalog::groups());
            self::assertNotSame('', $policy['title']);
            self::assertNotSame('', $policy['summary']);
            self::assertNotEmpty($policy['sections']);
        }
    }

    public function testCoreMarketplaceRulesAreExplicit(): void
    {
        $policies = PolicyCatalog::all($this->settings());
        $terms = json_encode($policies['termos-de-uso'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $returns = json_encode($policies['trocas-devolucoes-arrependimento'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $payments = json_encode($policies['pagamentos-reembolsos'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $commissions = json_encode($policies['comissoes-tarifas-recebimentos'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        self::assertStringContainsString('lojas independentes', $terms);
        self::assertStringContainsString('7 dias', $returns);
        self::assertStringContainsString('não afasta o direito legal', $returns);
        self::assertStringContainsString('Pagar.me', $payments);
        self::assertStringContainsString('12%', $commissions);
        self::assertStringContainsString('R$ 83,50', $commissions);
    }
}
