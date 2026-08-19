<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Finance\FinancialProofStorage;
use App\Services\Finance\FinancialSettlementService;
use App\Services\Finance\FinancialTransferService;
use RuntimeException;
use Throwable;

final class FinancialSettlementController extends Controller
{
    public function index(): string
    {
        $owner = in_array($_GET['owner'] ?? '', ['official_store','marketplace','consolidated'], true)
            ? (string) $_GET['owner'] : '';
        $sql = 'SELECT * FROM financial_settlements';
        $where = [];
        $params = [];
        if ($owner !== '') { $where[] = 'financial_owner=?'; $params[] = $owner; }
        $periodStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['period_start'] ?? ''))
            ? (string) $_GET['period_start'] : '';
        $periodEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['period_end'] ?? ''))
            ? (string) $_GET['period_end'] : '';
        if ($periodStart !== '') { $where[] = 'period_end>=?'; $params[] = $periodStart; }
        if ($periodEnd !== '') { $where[] = 'period_start<=?'; $params[] = $periodEnd; }
        if ($where !== []) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY period_end DESC,id DESC LIMIT 120';
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        return $this->page('admin/finance/settlements/index', 'layouts/admin', [
            'pageTitle' => 'Fechamentos financeiros',
            'settlements' => $statement->fetchAll(),
            'owner' => $owner,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
        ]);
    }

    public function show(string $id): string
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM financial_settlements WHERE id=?');
        $statement->execute([(int) $id]);
        $settlement = $statement->fetch();
        if (!is_array($settlement)) { http_response_code(404); throw new RuntimeException('Fechamento não encontrado.'); }
        $entries = $pdo->prepare(
            "SELECT fe.*,o.code order_code,s.trade_name seller_name
             FROM financial_settlement_entries fse
             JOIN financial_entries fe ON fe.id=fse.financial_entry_id
             LEFT JOIN orders o ON o.id=fe.order_id LEFT JOIN sellers s ON s.id=fe.seller_id
             WHERE fse.settlement_id=? ORDER BY fe.occurred_at,fe.id"
        );
        $entries->execute([$id]);
        $transfers = $pdo->prepare('SELECT * FROM financial_transfers WHERE settlement_id=? ORDER BY id DESC');
        $transfers->execute([$id]);
        $history = $pdo->prepare('SELECT * FROM financial_settlement_history WHERE settlement_id=? ORDER BY id DESC');
        $history->execute([$id]);
        $issues = $pdo->prepare(
            "SELECT * FROM financial_reconciliation_issues
             WHERE status='open' AND (settlement_id=? OR payment_id IN(
                 SELECT fe.payment_id FROM financial_settlement_entries fse
                 JOIN financial_entries fe ON fe.id=fse.financial_entry_id WHERE fse.settlement_id=?
             )) ORDER BY severity='critical' DESC,detected_at DESC"
        );
        $issues->execute([$id,$id]);
        return $this->page('admin/finance/settlements/show', 'layouts/admin', [
            'pageTitle' => 'Fechamento #' . $id,
            'settlement' => $settlement,
            'entries' => $entries->fetchAll(),
            'transfers' => $transfers->fetchAll(),
            'history' => $history->fetchAll(),
            'issues' => $issues->fetchAll(),
        ]);
    }

    public function generate(): string
    {
        try {
            $id = (new FinancialSettlementService())->generate(
                (string) ($_POST['period_start'] ?? ''),
                (string) ($_POST['period_end'] ?? ''),
                (string) ($_POST['financial_owner'] ?? '')
            );
            Session::flash('success', 'Fechamento calculado a partir do livro financeiro.');
            return Response::redirect('/admin/financeiro/fechamentos/' . $id);
        } catch (Throwable $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect('/admin/financeiro/fechamentos');
        }
    }

    public function review(string $id): string
    {
        try {
            (new FinancialSettlementService())->review((int) $id, Auth::id(), (string) ($_POST['notes'] ?? ''));
            Session::flash('success', 'Fechamento revisado.');
        } catch (Throwable $exception) { Session::flash('error', $exception->getMessage()); }
        return Response::redirect('/admin/financeiro/fechamentos/' . $id);
    }

    public function approve(string $id): string
    {
        try {
            (new FinancialSettlementService())->approve((int) $id, Auth::id());
            Session::flash('success', 'Fechamento aprovado para registro manual de transferência.');
        } catch (Throwable $exception) { Session::flash('error', $exception->getMessage()); }
        return Response::redirect('/admin/financeiro/fechamentos/' . $id);
    }

    public function cancel(string $id): string
    {
        try {
            (new FinancialSettlementService())->cancel((int) $id, Auth::id(), (string) ($_POST['notes'] ?? ''));
            Session::flash('success', 'Fechamento cancelado sem apagar seu histórico.');
        } catch (Throwable $exception) { Session::flash('error', $exception->getMessage()); }
        return Response::redirect('/admin/financeiro/fechamentos/' . $id);
    }

    public function transfer(string $id): string
    {
        $proof = null;
        try {
            $amount = $this->amountCents((string) ($_POST['amount'] ?? ''));
            $idempotencyKey = hash('sha256', implode('|', [
                $id,
                $amount,
                mb_strtolower(trim((string) ($_POST['bank_reference'] ?? ''))),
            ]));
            $existing = Database::connection()->prepare(
                'SELECT id FROM financial_transfers WHERE idempotency_key=? LIMIT 1'
            );
            $existing->execute([$idempotencyKey]);
            if ($existing->fetchColumn()) {
                Session::flash('success', 'Esta transferência já estava registrada.');
                return Response::redirect('/admin/financeiro/fechamentos/' . $id);
            }
            $proof = (new FinancialProofStorage())->store($_FILES['proof'] ?? []);
            $transferId = (new FinancialTransferService())->recordManual(
                (int) $id, $amount, (string) ($_POST['destination_name'] ?? ''),
                (string) ($_POST['destination_masked'] ?? ''), (string) ($_POST['bank_reference'] ?? ''),
                $proof, (string) ($_POST['notes'] ?? ''), Auth::id(),
                $idempotencyKey
            );
            if ($proof !== null) {
                $stored = Database::connection()->prepare('SELECT proof_file FROM financial_transfers WHERE id=?');
                $stored->execute([$transferId]);
                if (!hash_equals((string) ($stored->fetchColumn() ?: ''), $proof)) {
                    (new FinancialProofStorage())->delete($proof);
                }
            }
            Session::flash('success', 'Transferência externa registrada no livro. Nenhuma operação bancária foi executada.');
        } catch (Throwable $exception) {
            (new FinancialProofStorage())->delete($proof);
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect('/admin/financeiro/fechamentos/' . $id);
    }

    public function proof(string $id, string $transferId): string
    {
        $statement = Database::connection()->prepare(
            'SELECT proof_file FROM financial_transfers WHERE id=? AND settlement_id=? LIMIT 1'
        );
        $statement->execute([(int) $transferId, (int) $id]);
        $relativePath = (string) ($statement->fetchColumn() ?: '');
        $path = (new FinancialProofStorage())->resolve($relativePath);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="comprovante-' . (int) $transferId . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        return '';
    }

    public function resolveIssue(string $id, string $issueId): string
    {
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($notes === '') {
            Session::flash('error', 'Informe como a divergência foi tratada.');
            return Response::redirect('/admin/financeiro/fechamentos/' . $id);
        }
        $statement = Database::connection()->prepare(
            "UPDATE financial_reconciliation_issues
             SET status='resolved',resolved_at=NOW(),resolved_by=?,resolution_notes=?
             WHERE id=? AND status='open'
               AND (settlement_id=? OR payment_id IN(
                   SELECT fe.payment_id FROM financial_settlement_entries fse
                   JOIN financial_entries fe ON fe.id=fse.financial_entry_id
                   WHERE fse.settlement_id=?
               ))"
        );
        $statement->execute([Auth::id(), mb_substr($notes, 0, 1000), (int) $issueId, (int) $id, (int) $id]);
        Session::flash(
            $statement->rowCount() === 1 ? 'success' : 'error',
            $statement->rowCount() === 1 ? 'Divergência marcada como resolvida.' : 'Divergência não encontrada ou já tratada.'
        );
        return Response::redirect('/admin/financeiro/fechamentos/' . $id);
    }

    private function amountCents(string $value): int
    {
        $value = str_replace(',', '.', trim($value));
        if (preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $value) !== 1) {
            throw new RuntimeException('Informe um valor monetário válido.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
