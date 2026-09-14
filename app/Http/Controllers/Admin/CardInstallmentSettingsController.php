<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Http\Controllers\Controller;
use App\Services\Payments\CardInstallmentPricingService;
use App\Services\Settings\PlatformSettings;
use RuntimeException;
use Throwable;

final class CardInstallmentSettingsController extends Controller
{
    private const INSTALLMENTS = [6, 7, 8, 9, 10, 11, 12];

    public function show(): string
    {
        return $this->json([
            'ok' => true,
            'configuration' => (new CardInstallmentPricingService())->configuration(),
        ]);
    }

    public function update(): string
    {
        try {
            $values = [];
            foreach (self::INSTALLMENTS as $installments) {
                $key = 'pagarme_card_mdr_' . $installments . 'x';
                $raw = trim((string) ($_POST[$key] ?? ''));
                if ($raw === '') {
                    $values[$key] = null;
                    continue;
                }
                $rate = (float) str_replace(',', '.', $raw);
                if ($rate < 0 || $rate >= 100) {
                    throw new RuntimeException('As taxas da Pagar.me devem estar entre 0% e 99,9999%.');
                }
                $values[$key] = number_format($rate, 4, '.', '');
            }

            $base = $values['pagarme_card_mdr_6x'];
            foreach ([7, 8, 9, 10, 11, 12] as $installments) {
                $key = 'pagarme_card_mdr_' . $installments . 'x';
                if ($values[$key] === null) {
                    continue;
                }
                if ($base === null) {
                    throw new RuntimeException('Informe a taxa contratada de 6x antes de habilitar parcelas maiores.');
                }
                if ((float) $values[$key] < (float) $base) {
                    throw new RuntimeException("A taxa de {$installments}x não pode ser menor que a taxa-base de 6x.");
                }
            }

            $pdo = Database::connection();
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                "INSERT INTO settings(scope_type,scope_id,setting_key,setting_value)
                 VALUES('platform',0,?,?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()"
            );
            foreach ($values as $key => $value) {
                $statement->execute([$key, json_encode($value, JSON_THROW_ON_ERROR)]);
            }
            $pdo->commit();
            PlatformSettings::reset();

            return $this->json([
                'ok' => true,
                'message' => 'Taxas de parcelamento atualizadas.',
                'configuration' => (new CardInstallmentPricingService())->configuration(),
            ]);
        } catch (Throwable $exception) {
            $pdo = isset($pdo) ? $pdo : null;
            if ($pdo?->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code($exception instanceof RuntimeException ? 422 : 500);
            return $this->json([
                'ok' => false,
                'message' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Não foi possível salvar as taxas de parcelamento.',
            ]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload): string
    {
        header('Content-Type: application/json; charset=UTF-8');
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
