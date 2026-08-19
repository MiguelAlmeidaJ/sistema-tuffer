<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use PDO;
use RuntimeException;

final class MarketplaceFinancialLedgerService
{
    public const POLICY_VERSION = 'marketplace-ledger-v1';

    public function __construct(private readonly ?PDO $database = null)
    {
    }

    public function createPending(int $paymentId): int
    {
        $pdo = $this->pdo();
        $statement = $pdo->prepare(
            "SELECT fsl.*,p.order_id,o.created_at order_created_at
             FROM payment_financial_snapshot_lines fsl
             JOIN payments p ON p.id=fsl.payment_id
             JOIN orders o ON o.id=p.order_id
             WHERE fsl.payment_id=? ORDER BY fsl.id"
        );
        $statement->execute([$paymentId]);
        $lines = $statement->fetchAll();
        if ($lines === []) {
            throw new RuntimeException('O snapshot financeiro precisa existir antes do livro.');
        }
        $created = 0;
        foreach ($lines as $line) {
            $official = (int) ($line['is_official_store'] ?? 0) === 1;
            $owner = $official ? 'official_store' : 'external_seller';
            $sellerId = (int) $line['seller_id'];
            $common = [
                'order_id' => (int) $line['order_id'],
                'seller_order_id' => (int) $line['seller_order_id'],
                'payment_id' => $paymentId,
                'seller_id' => $sellerId,
                'recipient_id' => (string) $line['recipient_id'],
                'occurred_at' => (string) $line['order_created_at'],
                'source_type' => 'financial_snapshot_line',
                'source_id' => (string) $line['id'],
                'policy_version' => (string) ($line['policy_version'] ?? self::POLICY_VERSION),
                'product_cost_known' => (bool) ($line['product_cost_known'] ?? false),
            ];
            $productType = $official ? 'official_store_product_revenue' : 'external_seller_gross_revenue';
            $created += $this->entry($common, $owner, $productType, 'credit', (int) $line['products_amount_cents']);
            $sellerDiscount = (int) $line['seller_discount_cents'];
            if ($sellerDiscount > 0) {
                $created += $this->entry($common, $owner, $official ? 'official_store_discount' : 'external_seller_discount', 'debit', $sellerDiscount);
            }
            $shipping = (int) $line['shipping_amount_cents'];
            if ($shipping > 0 && ($line['shipping_recipient'] ?? null) === 'seller') {
                $created += $this->entry($common, $owner, $official ? 'official_store_shipping_revenue' : 'external_seller_shipping', 'credit', $shipping);
            }
            $commission = (int) $line['commission_amount_cents'];
            if ($commission > 0) {
                $created += $this->entry($common, 'marketplace', 'marketplace_commission', 'credit', $commission);
            }
            $platformDiscount = (int) $line['platform_discount_cents'];
            if ($platformDiscount > 0) {
                $created += $this->entry($common, 'marketplace', 'marketplace_coupon_subsidy', 'debit', $platformDiscount);
            }
            if ($official && (int) ($line['product_cost_known'] ?? 0) === 1 && (int) ($line['product_cost_cents'] ?? 0) > 0) {
                $created += $this->entry($common, 'official_store', 'official_store_product_cost', 'debit', (int) $line['product_cost_cents']);
            }
            foreach ([
                'official_store_tax_provision' => 'tax_provision_cents',
                'official_store_reserve' => 'reserve_amount_cents',
                'official_store_processing_fee' => 'expected_provider_fee_cents',
            ] as $type => $column) {
                if ($official && (int) ($line[$column] ?? 0) > 0) {
                    $created += $this->entry($common, $type === 'official_store_reserve' ? 'reserve' : 'official_store', $type, 'debit', (int) $line[$column]);
                }
            }
            $netType = $official ? 'official_store_net_revenue' : 'external_seller_net_revenue';
            $created += $this->entry($common, $owner, $netType, 'credit', (int) $line['seller_net_amount_cents'], true);
            $platformContribution = (int) $line['platform_contribution_cents'];
            if ($platformContribution > 0) {
                $created += $this->entry($common, 'marketplace', 'marketplace_service_fee', 'credit', $platformContribution, true);
            }
        }
        return $created;
    }

    public function confirm(int $paymentId, string $chargeId, ?string $occurredAt = null): int
    {
        $statement = $this->pdo()->prepare(
            "UPDATE financial_entries SET status='confirmed',external_charge_id=?,
                    occurred_at=COALESCE(?,occurred_at),settled_at=COALESCE(?,NOW())
             WHERE payment_id=? AND status='pending'"
        );
        $statement->execute([$chargeId, $occurredAt, $occurredAt, $paymentId]);
        return $statement->rowCount();
    }

    public function voidPending(int $paymentId): int
    {
        $statement = $this->pdo()->prepare(
            "UPDATE financial_entries SET status='void' WHERE payment_id=? AND status='pending'"
        );
        $statement->execute([$paymentId]);
        return $statement->rowCount();
    }

    public function reverse(int $paymentId, string $chargeId, string $reason, ?string $occurredAt = null): int
    {
        $statement = $this->pdo()->prepare(
            "SELECT * FROM financial_entries
             WHERE payment_id=? AND status='confirmed' ORDER BY id FOR UPDATE"
        );
        $statement->execute([$paymentId]);
        $created = 0;
        foreach ($statement->fetchAll() as $entry) {
            $key = 'reverse:' . (int) $entry['id'] . ':' . preg_replace('/[^a-z_]+/', '', strtolower($reason));
            $reversalType = (string) $entry['entry_type'];
            if ((int) $entry['is_split_component'] === 1) {
                $reversalType = match ((string) $entry['financial_owner']) {
                    'official_store' => $reason === 'chargeback' ? 'official_store_chargeback' : 'official_store_refund',
                    'marketplace' => $reason === 'chargeback' ? 'marketplace_chargeback' : 'marketplace_refund',
                    'external_seller' => $reason === 'chargeback' ? 'external_seller_chargeback' : 'external_seller_refund',
                    default => $reversalType,
                };
            }
            $insert = $this->pdo()->prepare(
                "INSERT INTO financial_entries(
                    order_id,seller_order_id,payment_id,external_charge_id,seller_id,recipient_id,
                    financial_owner,entry_type,direction,gross_amount_cents,amount_cents,currency,
                    status,is_split_component,accounting_period,source_type,source_id,sequence_no,
                    idempotency_key,description,metadata,occurred_at,settled_at,reversed_entry_id
                 ) VALUES(?,?,?,?,?,?,?,?,?,?,?,'BRL','confirmed',?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
            );
            $at = $occurredAt ?? date('Y-m-d H:i:s');
            $insert->execute([
                $entry['order_id'], $entry['seller_order_id'], $paymentId, $chargeId, $entry['seller_id'],
                $entry['recipient_id'], $entry['financial_owner'], $reversalType,
                $entry['direction'] === 'credit' ? 'debit' : 'credit',
                $entry['gross_amount_cents'], $entry['amount_cents'],
                $entry['is_split_component'], substr($at, 0, 7), 'financial_entry_reversal',
                (string) $entry['id'], 1, $key, 'Reversão: ' . mb_substr($reason, 0, 100),
                json_encode(['reason' => $reason], JSON_THROW_ON_ERROR), $at, $at, $entry['id'],
            ]);
            if ($insert->rowCount() === 1) {
                $created++;
            }
            $this->pdo()->prepare("UPDATE financial_entries SET status='reversed' WHERE id=? AND status='confirmed'")
                ->execute([$entry['id']]);
        }
        return $created;
    }

    /** @param array<string,mixed> $common */
    private function entry(array $common, string $owner, string $type, string $direction, int $amount, bool $split = false): int
    {
        if ($amount < 1) {
            return 0;
        }
        $sourceId = (string) $common['source_id'];
        $key = implode(':', [$common['order_id'], $type, $common['seller_id'], $sourceId, 1]);
        $statement = $this->pdo()->prepare(
            "INSERT INTO financial_entries(
                order_id,seller_order_id,payment_id,seller_id,recipient_id,financial_owner,
                entry_type,direction,gross_amount_cents,amount_cents,status,is_split_component,
                accounting_period,source_type,source_id,sequence_no,idempotency_key,description,metadata,occurred_at
             ) VALUES(?,?,?,?,?,?,?,?,?,?,'pending',?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        );
        $occurredAt = (string) $common['occurred_at'];
        $statement->execute([
            $common['order_id'], $common['seller_order_id'], $common['payment_id'], $common['seller_id'],
            $common['recipient_id'], $owner, $type, $direction, $amount, $amount, $split ? 1 : 0,
            substr($occurredAt, 0, 7), $common['source_type'], $sourceId, 1, $key,
            str_replace('_', ' ', $type),
            json_encode([
                'policy_version' => $common['policy_version'] ?? self::POLICY_VERSION,
                'product_cost_known' => $common['product_cost_known'] ?? null,
            ], JSON_THROW_ON_ERROR),
            $occurredAt,
        ]);
        return $statement->rowCount() === 1 ? 1 : 0;
    }

    private function pdo(): PDO
    {
        return $this->database ?? Database::connection();
    }
}
