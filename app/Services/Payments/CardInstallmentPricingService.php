<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\Database;
use App\Services\Settings\PlatformSettings;
use PDO;
use RuntimeException;
use Throwable;

final class CardInstallmentPricingService
{
    public const FREE_INSTALLMENTS = 3;
    public const MAX_INSTALLMENTS = 6;

    /** @var array<int,float|null>|null */
    private ?array $rateOverride;

    /** @param array<int,float|int|string|null>|null $rates */
    public function __construct(private readonly ?PDO $database = null, ?array $rates = null)
    {
        $this->rateOverride = $rates === null ? null : $this->normalizeRates($rates);
    }

    /** @return array{free_installments:int,max_installments:int,rates:array<int,float|null>,plans:array<int,array{installments:int,available:bool,customer_surcharge:bool}>} */
    public function configuration(): array
    {
        $rates = $this->rates();
        $plans = [];
        for ($installments = 1; $installments <= self::MAX_INSTALLMENTS; $installments++) {
            $available = $installments <= self::FREE_INSTALLMENTS
                || ($rates[self::FREE_INSTALLMENTS] !== null
                    && $rates[$installments] !== null
                    && $rates[$installments] >= $rates[self::FREE_INSTALLMENTS]);
            $plans[$installments] = [
                'installments' => $installments,
                'available' => $available,
                'customer_surcharge' => $installments > self::FREE_INSTALLMENTS,
            ];
        }

        return [
            'free_installments' => self::FREE_INSTALLMENTS,
            'max_installments' => self::MAX_INSTALLMENTS,
            'rates' => $rates,
            'plans' => $plans,
        ];
    }

    /** @return array{installments:int,base_amount_cents:int,surcharge_cents:int,total_amount_cents:int,base_rate_percent:float|null,provider_rate_percent:float|null} */
    public function quote(int $baseAmountCents, int $installments): array
    {
        if ($baseAmountCents < 1) {
            throw new RuntimeException('O valor base do pagamento é inválido.');
        }
        if ($installments < 1 || $installments > self::MAX_INSTALLMENTS) {
            throw new RuntimeException('Escolha entre 1 e ' . self::MAX_INSTALLMENTS . ' parcelas.');
        }

        $rates = $this->rates();
        $baseRate = $rates[self::FREE_INSTALLMENTS];
        $providerRate = $installments >= self::FREE_INSTALLMENTS ? $rates[$installments] : null;
        $surcharge = 0;

        if ($installments > self::FREE_INSTALLMENTS) {
            if ($baseRate === null || $providerRate === null) {
                throw new RuntimeException('A taxa contratada da Pagar.me para este parcelamento ainda não foi configurada.');
            }
            if ($providerRate < $baseRate) {
                throw new RuntimeException('A taxa do parcelamento não pode ser menor que a taxa-base de 3x.');
            }
            $targetFraction = $providerRate / 100;
            if ($targetFraction >= 1) {
                throw new RuntimeException('A taxa do parcelamento precisa ser menor que 100%.');
            }
            $incrementFraction = ($providerRate - $baseRate) / 100;
            $surcharge = (int) ceil(($baseAmountCents * $incrementFraction) / (1 - $targetFraction));
        }

        return [
            'installments' => $installments,
            'base_amount_cents' => $baseAmountCents,
            'surcharge_cents' => max(0, $surcharge),
            'total_amount_cents' => $baseAmountCents + max(0, $surcharge),
            'base_rate_percent' => $baseRate,
            'provider_rate_percent' => $providerRate,
        ];
    }

    /** @return array{installments:int,base_amount_cents:int,surcharge_cents:int,total_amount_cents:int,base_rate_percent:float|null,provider_rate_percent:float|null} */
    public function applyToPayment(int $paymentId, int $installments): array
    {
        $pdo = $this->database ?? Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $statement = $pdo->prepare(
                "SELECT p.id,p.order_id,p.method,p.status,p.amount_cents,p.card_installments,
                        p.card_base_amount_cents,p.card_installment_surcharge_cents
                   FROM payments p
                  WHERE p.id=? FOR UPDATE"
            );
            $statement->execute([$paymentId]);
            $payment = $statement->fetch();
            if (!is_array($payment)) {
                throw new RuntimeException('Pagamento não encontrado para aplicar o parcelamento.');
            }
            if ((string) $payment['method'] !== 'card') {
                throw new RuntimeException('A política de parcelamento é exclusiva para cartão de crédito.');
            }
            if (!in_array((string) $payment['status'], ['pending', 'processing'], true)) {
                throw new RuntimeException('O estado atual do pagamento não permite alterar o parcelamento.');
            }

            $snapshot = $pdo->prepare(
                'SELECT (SELECT COUNT(*) FROM payment_split_snapshots WHERE payment_id=?)
                      + (SELECT COUNT(*) FROM payment_financial_snapshot_lines WHERE payment_id=?)'
            );
            $snapshot->execute([$paymentId, $paymentId]);
            if ((int) $snapshot->fetchColumn() > 0) {
                $storedInstallments = (int) ($payment['card_installments'] ?? 0);
                if ($storedInstallments === $installments && (int) ($payment['card_base_amount_cents'] ?? 0) > 0) {
                    $quote = $this->quote((int) $payment['card_base_amount_cents'], $installments);
                    if ($ownsTransaction) {
                        $pdo->commit();
                    }
                    return $quote;
                }
                throw new RuntimeException('O parcelamento não pode ser alterado após a criação do snapshot financeiro.');
            }

            $baseAmount = (int) ($payment['card_base_amount_cents'] ?? 0);
            if ($baseAmount < 1) {
                $baseAmount = (int) $payment['amount_cents'];
            }
            $quote = $this->quote($baseAmount, $installments);
            $surchargeAmount = number_format($quote['surcharge_cents'] / 100, 2, '.', '');
            $totalAmount = number_format($quote['total_amount_cents'] / 100, 2, '.', '');

            $pdo->prepare(
                'UPDATE payments
                    SET card_installments=?,card_base_amount_cents=?,card_installment_surcharge_cents=?,
                        card_base_provider_rate=?,card_provider_rate=?,amount_cents=?,amount=?
                  WHERE id=?'
            )->execute([
                $installments,
                $quote['base_amount_cents'],
                $quote['surcharge_cents'],
                $quote['base_rate_percent'],
                $quote['provider_rate_percent'],
                $quote['total_amount_cents'],
                $totalAmount,
                $paymentId,
            ]);

            $pdo->prepare(
                'UPDATE orders
                    SET grand_total=grand_total-payment_surcharge_total+?,payment_surcharge_total=?
                  WHERE id=?'
            )->execute([$surchargeAmount, $surchargeAmount, (int) $payment['order_id']]);

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $quote;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<int,float|null> */
    public function rates(): array
    {
        if ($this->rateOverride !== null) {
            return $this->rateOverride;
        }
        $settings = PlatformSettings::all();
        return $this->normalizeRates([
            3 => $settings['pagarme_card_mdr_3x'] ?? null,
            4 => $settings['pagarme_card_mdr_4x'] ?? null,
            5 => $settings['pagarme_card_mdr_5x'] ?? null,
            6 => $settings['pagarme_card_mdr_6x'] ?? null,
        ]);
    }

    /** @param array<int,float|int|string|null> $rates @return array<int,float|null> */
    private function normalizeRates(array $rates): array
    {
        $normalized = array_fill(1, self::MAX_INSTALLMENTS, null);
        foreach ([3, 4, 5, 6] as $installments) {
            $raw = $rates[$installments] ?? null;
            if ($raw === null || trim((string) $raw) === '') {
                $normalized[$installments] = null;
                continue;
            }
            $rate = (float) str_replace(',', '.', (string) $raw);
            $normalized[$installments] = $rate >= 0 && $rate < 100 ? $rate : null;
        }
        return $normalized;
    }
}
