<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Http\Controllers\Controller;

final class FinanceController extends Controller
{
    public function index(): string
    {
        $pdo = Database::connection();
        $ownerSummary = static function(string $owner) use ($pdo): array {
            $statement = $pdo->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN entry_type IN ('official_store_product_revenue','marketplace_commission')
                        AND is_split_component=0 THEN IF(direction='credit',amount_cents,-amount_cents) ELSE 0 END),0) gross,
                    COALESCE(SUM(CASE WHEN is_split_component=1 THEN IF(direction='credit',amount_cents,-amount_cents) ELSE 0 END),0) net,
                    COALESCE(SUM(CASE WHEN entry_type='official_store_product_cost' THEN IF(direction='debit',amount_cents,-amount_cents) ELSE 0 END),0) product_cost,
                    COALESCE(SUM(CASE WHEN entry_type LIKE '%processing_fee' THEN IF(direction='debit',amount_cents,-amount_cents) ELSE 0 END),0) fees,
                    COALESCE(SUM(CASE WHEN entry_type LIKE '%tax_provision' THEN IF(direction='debit',amount_cents,-amount_cents) ELSE 0 END),0) taxes,
                    COALESCE(SUM(CASE WHEN entry_type LIKE '%reserve' THEN IF(direction='debit',amount_cents,-amount_cents) ELSE 0 END),0) reserves,
                    COALESCE(SUM(CASE WHEN entry_type='transfer_out' THEN IF(direction='debit',amount_cents,-amount_cents) ELSE 0 END),0) transferred,
                    COALESCE(SUM(CASE WHEN entry_type LIKE '%coupon_subsidy' THEN IF(direction='debit',amount_cents,-amount_cents) ELSE 0 END),0) coupons,
                    COALESCE(SUM(CASE WHEN is_split_component=1 AND JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.reason'))='chargeback' THEN amount_cents ELSE 0 END),0) chargebacks
                 FROM financial_entries WHERE financial_owner=? AND status IN ('confirmed','reversed')"
            );
            $statement->execute([$owner]);
            return $statement->fetch() ?: [];
        };
        $official = $ownerSummary('official_store');
        $platform = $ownerSummary('marketplace');
        $costKnown = (int) $pdo->query(
            "SELECT COUNT(*) FROM payment_financial_snapshot_lines
             WHERE is_official_store=1 AND product_cost_known=0"
        )->fetchColumn() === 0;
        $official['estimated_profit'] = $costKnown
            ? (int) $official['net'] - (int) $official['product_cost'] - (int) $official['fees'] - (int) $official['taxes']
            : null;
        if (!$costKnown) {
            $official['product_cost'] = null;
        }
        $official['transferable'] = $costKnown
            ? max(0, (int) $official['net'] - (int) $official['product_cost']
                - (int) $official['taxes'] - (int) $official['reserves'] - (int) $official['transferred'])
            : null;
        $platform['transferable'] = max(0, (int) $platform['net'] - (int) $platform['reserves'] - (int) $platform['transferred']);
        $consolidated = [
            'received' => (int) $official['net'] + (int) $platform['net'],
            'official' => (int) $official['net'],
            'platform' => (int) $platform['net'],
            'reserved' => (int) $official['reserves'] + (int) $platform['reserves'],
            'transferred' => (int) $official['transferred'] + (int) $platform['transferred'],
            'issues' => (int) $pdo->query(
                "SELECT COUNT(*) FROM financial_reconciliation_issues WHERE status='open'"
            )->fetchColumn(),
            'pending_settlements' => (int) $pdo->query(
                "SELECT COUNT(*) FROM financial_settlements WHERE status IN ('awaiting_review','approved','partially_transferred')"
            )->fetchColumn(),
            'pagarme_balance' => null,
            'bank_balance_reported' => null,
        ];
        $entries = $pdo->query(
            "SELECT fe.*,o.code order_code,s.trade_name seller_name
             FROM financial_entries fe
             LEFT JOIN orders o ON o.id=fe.order_id
             LEFT JOIN sellers s ON s.id=fe.seller_id
             ORDER BY fe.created_at DESC,fe.id DESC LIMIT 80"
        )->fetchAll();
        return $this->page('admin/finance/index', 'layouts/admin', [
            'pageTitle' => 'Financeiro da plataforma',
            'official' => $official,
            'platform' => $platform,
            'consolidated' => $consolidated,
            'entries' => $entries,
        ]);
    }
}
