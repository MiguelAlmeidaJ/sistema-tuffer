<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class FinancialSettlementService
{
    public function __construct(private readonly ?PDO $database = null)
    {
    }

    public function generate(string $periodStart, string $periodEnd, string $owner): int
    {
        if (!in_array($owner, ['official_store','marketplace','consolidated'], true)) {
            throw new RuntimeException('Centro financeiro inválido.');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
        if (!$start || !$end
            || $start->format('Y-m-d') !== $periodStart
            || $end->format('Y-m-d') !== $periodEnd
            || $end < $start) {
            throw new RuntimeException('Período financeiro inválido.');
        }
        $pdo = $this->pdo();
        $existing = $pdo->prepare(
            'SELECT id FROM financial_settlements
             WHERE settlement_type=? AND financial_owner=? AND period_start=? AND period_end=? LIMIT 1'
        );
        $existing->execute([$owner, $owner, $start->format('Y-m-d'), $end->format('Y-m-d')]);
        $existingId = (int) ($existing->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return $existingId;
        }
        $whereOwner = $owner === 'consolidated' ? "financial_owner IN ('official_store','marketplace')" : 'financial_owner=?';
        $params = $owner === 'consolidated'
            ? [$start->format('Y-m-d'), $end->format('Y-m-d')]
            : [$owner, $start->format('Y-m-d'), $end->format('Y-m-d')];
        $statement = $pdo->prepare(
            "SELECT * FROM financial_entries
             WHERE status IN ('confirmed','reversed') AND {$whereOwner}
               AND occurred_at>=? AND occurred_at<DATE_ADD(?,INTERVAL 1 DAY)
             ORDER BY id"
        );
        $statement->execute($params);
        $entries = $statement->fetchAll();
        $totals = $this->totals($entries, $owner);
        $policy = new OfficialStoreTransferPolicy();
        $transfer = $policy->calculate(
            $totals['net_revenue'], $totals['product_cost'] ?? 0, $totals['tax'],
            $totals['refunds'], $totals['chargebacks'], $totals['adjustments'], 0
        );
        $totals['reserve'] = $owner === 'official_store' ? $transfer['reserve_cents'] : $totals['reserve'];
        $totals['transferable'] = $owner === 'official_store'
            ? ($totals['product_cost'] === null ? 0 : $transfer['transferable_cents'])
            : max(0, $totals['net_revenue'] - $totals['reserve']);
        $estimatedProfit = $totals['product_cost'] === null
            ? null
            : $totals['net_revenue'] - $totals['product_cost'] - $totals['tax'] - $totals['processing'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO financial_settlements(
                    settlement_type,period_start,period_end,financial_owner,gross_revenue_cents,
                    discounts_cents,coupons_cents,shipping_revenue_cents,shipping_cost_cents,
                    product_cost_cents,processing_fees_cents,tax_provision_cents,refunds_cents,
                    chargebacks_cents,reserve_amount_cents,net_revenue_cents,estimated_profit_cents,
                    previous_adjustments_cents,transferable_amount_cents,status,policy_version,calculated_at
                 ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'awaiting_review',?,NOW())
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
            )->execute([
                $owner, $start->format('Y-m-d'), $end->format('Y-m-d'), $owner,
                $totals['gross'], $totals['discounts'], $totals['coupons'], $totals['shipping'],
                $totals['shipping_cost'], $totals['product_cost'], $totals['processing'], $totals['tax'],
                $totals['refunds'], $totals['chargebacks'], $totals['reserve'], $totals['net_revenue'],
                $estimatedProfit, $totals['adjustments'], $totals['transferable'],
                trim((string) ($_ENV['MARKETPLACE_FINANCIAL_POLICY_VERSION'] ?? MarketplaceFinancialLedgerService::POLICY_VERSION)),
            ]);
            $id = (int) $pdo->lastInsertId();
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO financial_settlement_entries(settlement_id,financial_entry_id) VALUES(?,?)'
            );
            foreach ($entries as $entry) $insert->execute([$id, $entry['id']]);
            $this->history($id, 'generated', null, 'awaiting_review', null, null);
            $pdo->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public function review(int $settlementId, int $userId, ?string $notes = null): void
    {
        $statement = $this->pdo()->prepare(
            "UPDATE financial_settlements SET reviewed_at=NOW(),reviewed_by=?,notes=?
             WHERE id=? AND status='awaiting_review'"
        );
        $statement->execute([$userId, mb_substr((string) $notes, 0, 1000), $settlementId]);
        if ($statement->rowCount() !== 1) throw new RuntimeException('Fechamento indisponível para revisão.');
        $this->history($settlementId, 'reviewed', 'awaiting_review', 'awaiting_review', $notes, $userId);
    }

    public function approve(int $settlementId, int $userId): void
    {
        $critical = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM financial_reconciliation_issues
             WHERE status='open' AND severity='critical'
               AND (settlement_id=? OR settlement_id IS NULL)"
        );
        $critical->execute([$settlementId]);
        if ((int) $critical->fetchColumn() > 0) {
            throw new RuntimeException('Divergências críticas impedem a aprovação do fechamento.');
        }
        $statement = $this->pdo()->prepare(
            "UPDATE financial_settlements SET status='approved',approved_at=NOW(),approved_by=?
             WHERE id=? AND status='awaiting_review' AND reviewed_at IS NOT NULL"
        );
        $statement->execute([$userId, $settlementId]);
        if ($statement->rowCount() !== 1) throw new RuntimeException('Revise o fechamento antes de aprová-lo.');
        $this->history($settlementId, 'approved', 'awaiting_review', 'approved', null, $userId);
    }

    public function cancel(int $settlementId, int $userId, string $notes): void
    {
        $notes = trim($notes);
        if ($notes === '') {
            throw new RuntimeException('Informe o motivo do cancelamento.');
        }
        $statement = $this->pdo()->prepare(
            "UPDATE financial_settlements SET status='canceled',notes=?
             WHERE id=? AND status IN ('awaiting_review','approved')
               AND transferred_amount_cents=0"
        );
        $statement->execute([mb_substr($notes, 0, 1000), $settlementId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Somente fechamentos sem transferência podem ser cancelados.');
        }
        $this->history($settlementId, 'canceled', null, 'canceled', $notes, $userId);
    }

    /** @param array<int,array<string,mixed>> $entries @return array<string,int|null> */
    public function totals(array $entries, string $owner): array
    {
        $sum = static function(array $entries, array $types): int {
            $total = 0;
            foreach ($entries as $entry) {
                if (($entry['source_type'] ?? null) === 'financial_entry_reversal'
                    || !in_array($entry['entry_type'] ?? '', $types, true)
                    || (int) ($entry['is_split_component'] ?? 0) === 1) {
                    continue;
                }
                $signed = ($entry['direction'] ?? '') === 'credit' ? 1 : -1;
                $total += $signed * (int) ($entry['amount_cents'] ?? 0);
            }
            return $total;
        };
        $costEntries = array_values(array_filter($entries, static fn(array $entry): bool =>
            ($entry['entry_type'] ?? '') === 'official_store_product_cost'
            && (int) ($entry['is_split_component'] ?? 0) === 0
        ));
        $hasOfficialRevenue = (bool) array_filter($entries, static fn(array $entry): bool =>
            str_starts_with((string) ($entry['entry_type'] ?? ''), 'official_store_')
        );
        $officialNetSeen = false;
        $allOfficialCostsKnown = true;
        foreach ($entries as $entry) {
            if (($entry['entry_type'] ?? null) !== 'official_store_net_revenue'
                || ($entry['source_type'] ?? null) === 'financial_entry_reversal') continue;
            $officialNetSeen = true;
            $metadata = json_decode((string) ($entry['metadata'] ?? ''), true);
            $allOfficialCostsKnown = $allOfficialCostsKnown && (bool) ($metadata['product_cost_known'] ?? false);
        }
        $cost = $owner === 'official_store' && $hasOfficialRevenue && $costEntries === []
            && (!$officialNetSeen || !$allOfficialCostsKnown)
            ? null
            : abs($sum($entries, ['official_store_product_cost']));
        $netTypes = $owner === 'official_store'
            ? ['official_store_net_revenue']
            : ($owner === 'marketplace' ? ['marketplace_service_fee'] : ['official_store_net_revenue','marketplace_service_fee']);
        $net = 0;
        $refunds = 0;
        $chargebacks = 0;
        foreach ($entries as $entry) {
            if ((int) ($entry['is_split_component'] ?? 0) !== 1) continue;
            if (($entry['source_type'] ?? null) !== 'financial_entry_reversal'
                && in_array($entry['entry_type'] ?? '', $netTypes, true)) {
                $net += (($entry['direction'] ?? '') === 'credit' ? 1 : -1) * (int) $entry['amount_cents'];
                continue;
            }
            if (($entry['source_type'] ?? null) !== 'financial_entry_reversal') continue;
            $metadata = json_decode((string) ($entry['metadata'] ?? ''), true);
            if (($metadata['reason'] ?? null) === 'chargeback') {
                $chargebacks += (int) $entry['amount_cents'];
            } else {
                $refunds += (int) $entry['amount_cents'];
            }
        }
        return [
            'gross' => max(0, $sum($entries, ['official_store_product_revenue','external_seller_gross_revenue','marketplace_commission'])),
            'discounts' => abs(min(0, $sum($entries, ['official_store_discount','external_seller_discount']))),
            'coupons' => abs(min(0, $sum($entries, ['marketplace_coupon_subsidy','official_store_coupon_subsidy']))),
            'shipping' => max(0, $sum($entries, ['official_store_shipping_revenue','marketplace_shipping_revenue','external_seller_shipping'])),
            'shipping_cost' => abs(min(0, $sum($entries, ['official_store_shipping_cost']))),
            'product_cost' => $cost,
            'processing' => abs(min(0, $sum($entries, ['official_store_processing_fee','marketplace_processing_fee','payment_provider_fee']))),
            'tax' => abs(min(0, $sum($entries, ['official_store_tax_provision']))),
            'refunds' => $refunds,
            'chargebacks' => $chargebacks,
            'reserve' => abs(min(0, $sum($entries, ['official_store_reserve','marketplace_reserve']))),
            'net_revenue' => max(0, $net),
            'adjustments' => $sum($entries, ['adjustment_credit','adjustment_debit']),
            'transferable' => 0,
        ];
    }

    private function history(int $id, string $action, ?string $previous, string $new, ?string $notes, ?int $user): void
    {
        $this->pdo()->prepare(
            'INSERT INTO financial_settlement_history(settlement_id,action,previous_status,new_status,notes,created_by)
             VALUES(?,?,?,?,?,?)'
        )->execute([$id, $action, $previous, $new, $notes, $user]);
    }

    private function pdo(): PDO { return $this->database ?? Database::connection(); }
}
