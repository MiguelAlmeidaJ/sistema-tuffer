<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use App\Services\Payments\Pagarme\PagarmeOrderReconciliationService;
use PDO;
use Throwable;

final class MarketplaceReconciliationService
{
    public function __construct(private readonly ?PDO $database = null)
    {
    }

    /** @return array{payments_checked:int,issues:int,provider:array<string,mixed>|null} */
    public function reconcile(int $limit = 100, bool $consultProvider = true): array
    {
        $pdo = $this->pdo();
        $result = ['payments_checked' => 0, 'issues' => 0, 'provider' => null];
        if ($consultProvider) {
            $result['provider'] = (new PagarmeOrderReconciliationService(null, $pdo))
                ->reconcilePending($limit);
        }
        $statement = $pdo->query(
            "SELECT p.id payment_id,p.order_id,p.amount_cents,p.status,p.external_order_id
             FROM payments p WHERE p.integration_type='orders'
             ORDER BY p.updated_at DESC LIMIT " . max(1, min(500, $limit))
        );
        foreach ($statement->fetchAll() as $payment) {
            $result['payments_checked']++;
            $split = $pdo->prepare(
                "SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount_cents ELSE -amount_cents END),0)
                 FROM financial_entries
                 WHERE payment_id=? AND is_split_component=1 AND status IN ('pending','confirmed')"
            );
            $split->execute([$payment['payment_id']]);
            $ledgerAmount = (int) $split->fetchColumn();
            if ($ledgerAmount !== (int) $payment['amount_cents']) {
                $this->issue($payment, 'ledger_split_total_mismatch', (string) $payment['amount_cents'], (string) $ledgerAmount, 'critical');
                $result['issues']++;
            }
            if ($payment['status'] === 'paid') {
                $confirmed = $pdo->prepare(
                    "SELECT COUNT(*) FROM financial_entries WHERE payment_id=? AND status='confirmed'"
                );
                $confirmed->execute([$payment['payment_id']]);
                if ((int) $confirmed->fetchColumn() === 0) {
                    $this->issue($payment, 'paid_without_confirmed_ledger', 'confirmed entries', 'none', 'critical');
                    $result['issues']++;
                }
            }
        }
        foreach ($pdo->query(
            "SELECT d.payment_id,d.external_order_id,d.external_charge_id,d.divergence_type,
                    d.local_status,d.remote_status,p.order_id
             FROM pagarme_reconciliation_divergences d
             LEFT JOIN payments p ON p.id=d.payment_id
             WHERE d.review_status='open'"
        )->fetchAll() as $divergence) {
            $this->issue(
                $divergence,
                'provider_' . (string) $divergence['divergence_type'],
                (string) ($divergence['local_status'] ?? ''),
                (string) ($divergence['remote_status'] ?? ''),
                'critical',
                (string) ($divergence['external_charge_id'] ?? '')
            );
            $result['issues']++;
        }
        return $result;
    }

    /** @param array<string,mixed> $context */
    private function issue(
        array $context,
        string $type,
        string $expected,
        string $actual,
        string $severity,
        string $chargeId = ''
    ): void {
        $fingerprint = hash('sha256', implode('|', [
            $context['payment_id'] ?? 0, $type, $expected, $actual, $chargeId,
        ]));
        $this->pdo()->prepare(
            "INSERT INTO financial_reconciliation_issues(
                order_id,payment_id,external_charge_id,issue_type,expected_value,actual_value,
                status,severity,fingerprint,metadata
             ) VALUES(?,?,?,?,?,?,'open',?,?,?)
             ON DUPLICATE KEY UPDATE detected_at=NOW(),
                status=IF(status='resolved','resolved','open')"
        )->execute([
            $context['order_id'] ?? null,
            $context['payment_id'] ?? null,
            $chargeId ?: null,
            $type,
            mb_substr($expected, 0, 255),
            mb_substr($actual, 0, 255),
            $severity,
            $fingerprint,
            json_encode(['source' => 'marketplace_reconciliation'], JSON_THROW_ON_ERROR),
        ]);
    }

    private function pdo(): PDO { return $this->database ?? Database::connection(); }
}
