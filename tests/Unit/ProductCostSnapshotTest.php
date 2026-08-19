<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductCostSnapshotTest extends TestCase
{
    public function testSnapshotSchemaPreservesNullableCost(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/027_create_financial_ledger_and_expand_snapshots.sql');
        self::assertStringContainsString('unit_cost_cents BIGINT UNSIGNED NULL', $migration);
        self::assertStringContainsString('cost_known TINYINT(1)', $migration);
        self::assertStringContainsString('product_cost_cents BIGINT UNSIGNED NULL', $migration);
    }

    public function testCostHistoryIsEffectiveDated(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/028_create_product_cost_history.sql');
        self::assertStringContainsString('effective_from DATETIME NOT NULL', $migration);
        self::assertStringContainsString('effective_until DATETIME NULL', $migration);
    }
}
