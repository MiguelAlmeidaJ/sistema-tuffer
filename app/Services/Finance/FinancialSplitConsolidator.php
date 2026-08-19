<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use App\Services\Payments\MarketplaceFinancialPolicy;
use App\Services\Payments\Pagarme\DTO\SplitRuleData;
use App\Services\Payments\Pagarme\PagarmeRecipientId;
use PDO;
use RuntimeException;

final class FinancialSplitConsolidator
{
    public function __construct(
        private readonly ?PDO $database = null,
        private readonly ?MarketplaceFinancialPolicy $policy = null
    ) {
    }

    /** @return array<int,SplitRuleData> */
    public function forPayment(int $paymentId): array
    {
        $pdo = $this->database ?? Database::connection();
        $payment = $pdo->prepare('SELECT amount_cents FROM payments WHERE id=?');
        $payment->execute([$paymentId]);
        $amount = $payment->fetchColumn();
        if ($amount === false) {
            throw new RuntimeException('Pagamento não encontrado para consolidar o split.');
        }
        $statement = $pdo->prepare(
            "SELECT recipient_id,direction,amount_cents
             FROM financial_entries
             WHERE payment_id=? AND status IN ('pending','confirmed')
               AND is_split_component=1 ORDER BY id"
        );
        $statement->execute([$paymentId]);
        return $this->consolidateRows(
            $statement->fetchAll(),
            (int) $amount,
            trim((string) ($_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? ''))
        );
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,SplitRuleData> */
    public function consolidateRows(array $rows, int $chargeAmountCents, string $platformRecipientId): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $recipientId = trim((string) ($row['recipient_id'] ?? ''));
            if (!PagarmeRecipientId::isValid($recipientId)) {
                throw new RuntimeException('Um lançamento financeiro não possui recipient_id válido.');
            }
            $amount = (int) ($row['amount_cents'] ?? 0);
            if ($amount < 1) {
                throw new RuntimeException('O split não aceita lançamentos zerados ou negativos.');
            }
            $signed = ($row['direction'] ?? null) === 'debit' ? -$amount : $amount;
            $totals[$recipientId] = ($totals[$recipientId] ?? 0) + $signed;
        }
        if ($totals === []) {
            throw new RuntimeException('Nenhum lançamento detalhado foi gerado para o split.');
        }
        ksort($totals);
        $rules = [];
        $policy = $this->policy ?? new MarketplaceFinancialPolicy();
        foreach ($totals as $recipientId => $amount) {
            if ($amount < 1) {
                throw new RuntimeException('A consolidação produziu uma parcela zerada ou negativa.');
            }
            $rules[] = new SplitRuleData(
                $amount,
                $recipientId,
                hash_equals($platformRecipientId, $recipientId)
                    ? $policy->platformOptions()
                    : $policy->sellerOptions()
            );
        }
        if (array_sum(array_map(static fn(SplitRuleData $rule): int => $rule->amount, $rules)) !== $chargeAmountCents) {
            throw new RuntimeException('A soma consolidada do split diverge do valor da cobrança.');
        }
        return $rules;
    }
}
