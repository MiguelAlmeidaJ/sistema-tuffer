<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use App\Services\Payments\Pagarme\PagarmeRecipientId;
use PDO;
use RuntimeException;
use Throwable;

final class FinancialTransferService
{
    public function __construct(private readonly ?PDO $database = null)
    {
    }

    public function recordManual(
        int $settlementId,
        int $amountCents,
        string $destinationName,
        string $destinationMasked,
        string $bankReference,
        ?string $proofPath,
        ?string $notes,
        int $userId,
        string $idempotencyKey
    ): int {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $existing = $pdo->prepare('SELECT id FROM financial_transfers WHERE idempotency_key=? LIMIT 1');
            $existing->execute([$idempotencyKey]);
            $existingId = (int) ($existing->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $pdo->commit();
                return $existingId;
            }
            $statement = $pdo->prepare('SELECT * FROM financial_settlements WHERE id=? FOR UPDATE');
            $statement->execute([$settlementId]);
            $settlement = $statement->fetch();
            $existing->execute([$idempotencyKey]);
            $existingId = (int) ($existing->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $pdo->commit();
                return $existingId;
            }
            if (is_array($settlement) && ($settlement['financial_owner'] ?? null) !== 'official_store') {
                throw new RuntimeException('Nesta fase, o registro de transferência é exclusivo da loja oficial.');
            }
            if (!is_array($settlement)) throw new RuntimeException('Fechamento não encontrado.');
            $available = (int) $settlement['transferable_amount_cents'] - (int) $settlement['transferred_amount_cents'];
            $critical = $pdo->prepare(
                "SELECT COUNT(*) FROM financial_reconciliation_issues
                 WHERE status='open' AND severity='critical'
                   AND (settlement_id=? OR payment_id IN(
                       SELECT fe.payment_id FROM financial_settlement_entries fse
                       JOIN financial_entries fe ON fe.id=fse.financial_entry_id
                       WHERE fse.settlement_id=?
                   ))"
            );
            $critical->execute([$settlementId, $settlementId]);
            $chargeback = $pdo->prepare(
                "SELECT COUNT(*) FROM financial_settlement_entries fse
                 JOIN financial_entries fe ON fe.id=fse.financial_entry_id
                 WHERE fse.settlement_id=? AND fe.status='confirmed'
                   AND JSON_UNQUOTE(JSON_EXTRACT(fe.metadata,'$.reason'))='chargeback'"
            );
            $chargeback->execute([$settlementId]);
            (new OfficialStoreTransferPolicy())->assertTransferAllowed(
                $amountCents,
                $available,
                (int) $critical->fetchColumn() > 0,
                (int) $chargeback->fetchColumn() > 0,
                (string) $settlement['status']
            );
            $destinationName = mb_substr(trim($destinationName), 0, 120);
            $destinationMasked = mb_substr(trim($destinationMasked), 0, 80);
            $bankReference = mb_substr(trim($bankReference), 0, 120);
            $platformRecipientId = trim((string) ($_ENV['PAGARME_PLATFORM_RECIPIENT_ID'] ?? ''));
            if (!PagarmeRecipientId::isValid($platformRecipientId)) {
                throw new RuntimeException('O recebedor global da plataforma não está configurado.');
            }
            if ($destinationName === '' || $destinationMasked === '' || $bankReference === '') {
                throw new RuntimeException('Informe destino mascarado e referência bancária.');
            }
            $pdo->prepare(
                "INSERT INTO financial_transfers(
                    settlement_id,financial_owner,amount_cents,transfer_type,source_account,
                    destination_account_masked,destination_account_name,status,requested_at,
                    approved_at,transferred_at,bank_reference,proof_file,idempotency_key,
                    created_by,approved_by,metadata
                 ) VALUES(?,? ,?,'manual_bank_transfer','pagarme_main',?,?,'completed',NOW(),NOW(),NOW(),?,?,?,?,?,?)"
            )->execute([
                $settlementId, $settlement['financial_owner'], $amountCents, $destinationMasked,
                $destinationName, $bankReference, $proofPath, $idempotencyKey, $userId, $userId,
                json_encode(['notes' => mb_substr((string) $notes, 0, 1000)], JSON_THROW_ON_ERROR),
            ]);
            $transferId = (int) $pdo->lastInsertId();
            $newTransferred = (int) $settlement['transferred_amount_cents'] + $amountCents;
            $newStatus = $newTransferred >= (int) $settlement['transferable_amount_cents']
                ? 'transferred' : 'partially_transferred';
            $pdo->prepare(
                'UPDATE financial_settlements SET transferred_amount_cents=?,status=?,
                    transferred_at=NOW(),transferred_by=?,destination_account_name=?,
                    destination_account_masked=?,bank_reference=?,proof_file=? WHERE id=?'
            )->execute([
                $newTransferred, $newStatus, $userId, $destinationName, $destinationMasked,
                $bankReference, $proofPath, $settlementId,
            ]);
            $pdo->prepare(
                "INSERT INTO financial_entries(
                    order_id,payment_id,seller_id,recipient_id,financial_owner,entry_type,direction,
                    gross_amount_cents,amount_cents,status,is_split_component,accounting_period,
                    source_type,source_id,sequence_no,idempotency_key,description,metadata,occurred_at,settled_at
                 ) VALUES(NULL,NULL,NULL,? ,?,'transfer_out','debit',?,?,'confirmed',0,?,
                    'financial_transfer',?,1,?,'Transferência manual registrada',?,NOW(),NOW())"
            )->execute([
                $platformRecipientId,
                $settlement['financial_owner'],
                $amountCents,
                $amountCents,
                date('Y-m'),
                (string) $transferId,
                'transfer:' . $idempotencyKey,
                json_encode(['bank_reference' => $bankReference], JSON_THROW_ON_ERROR),
            ]);
            $pdo->prepare(
                'INSERT INTO financial_settlement_history(
                    settlement_id,action,previous_status,new_status,notes,created_by
                 ) VALUES(?,?,?,?,?,?)'
            )->execute([$settlementId, 'manual_transfer', $settlement['status'], $newStatus, $notes, $userId]);
            $pdo->commit();
            return $transferId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    private function pdo(): PDO { return $this->database ?? Database::connection(); }
}
