<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Wholesale\WholesaleDocumentStorage;
use App\Services\Wholesale\WholesaleNotificationService;
use RuntimeException;
use Throwable;

final class WholesaleController extends Controller
{
    public function index(): string
    {
        $allowed = ['draft','pending','under_review','approved','rejected','suspended','cancelled'];
        $status = in_array($_GET['status'] ?? '', $allowed, true) ? (string) $_GET['status'] : '';
        $sql = 'SELECT wa.*,u.name customer_name,u.email customer_email,r.name responsible_name FROM wholesale_accounts wa JOIN users u ON u.id=wa.user_id LEFT JOIN wholesale_responsibles r ON r.wholesale_account_id=wa.id AND r.is_primary=1';
        $params = [];
        if ($status !== '') { $sql .= ' WHERE wa.status=?'; $params[] = $status; }
        $sql .= " ORDER BY FIELD(wa.status,'pending','under_review','rejected','suspended','approved','draft','cancelled'),wa.submitted_at DESC,wa.created_at DESC";
        $statement = Database::connection()->prepare($sql); $statement->execute($params);
        $counts = Database::connection()->query('SELECT status,COUNT(*) total FROM wholesale_accounts GROUP BY status')->fetchAll();
        return $this->page('admin/wholesale/index', 'layouts/admin', ['pageTitle' => 'Atacadistas', 'accounts' => $statement->fetchAll(), 'filterStatus' => $status, 'counts' => array_column($counts, 'total', 'status')]);
    }

    public function show(string $id): string
    {
        $account = $this->find((int) $id);
        if (!$account) return $this->notFound();
        $pdo = Database::connection();
        $data = ['account' => $account];
        foreach ([
            'responsible' => ['SELECT * FROM wholesale_responsibles WHERE wholesale_account_id=? ORDER BY is_primary DESC,id LIMIT 1', false],
            'address' => ['SELECT * FROM wholesale_addresses WHERE wholesale_account_id=? ORDER BY id LIMIT 1', false],
            'documents' => ['SELECT * FROM wholesale_documents WHERE wholesale_account_id=? ORDER BY type,id', true],
            'history' => ['SELECT h.*,u.name changed_by_name FROM wholesale_status_history h LEFT JOIN users u ON u.id=h.changed_by WHERE h.wholesale_account_id=? ORDER BY h.created_at DESC,h.id DESC', true],
            'orders' => ['SELECT id,code,order_type,status,grand_total,created_at FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 10', true, 'user_id'],
        ] as $key => $config) {
            [$sql, $many] = $config; $statement = $pdo->prepare($sql); $statement->execute([($config[2] ?? '') === 'user_id' ? $account['user_id'] : $account['id']]); $data[$key] = $many ? $statement->fetchAll() : ($statement->fetch() ?: null);
        }
        return $this->page('admin/wholesale/show', 'layouts/admin', $data + ['pageTitle' => 'Analisar atacadista']);
    }

    public function startReview(string $id): string { return $this->transition((int) $id, ['pending'], 'under_review', 'Análise iniciada.', 'analysis_started'); }
    public function approve(string $id): string { return $this->transition((int) $id, ['pending','under_review','rejected'], 'approved', 'Cadastro atacadista aprovado.', 'approved'); }
    public function suspend(string $id): string { return $this->transition((int) $id, ['approved'], 'suspended', 'Acesso atacadista suspenso.', 'suspended', trim((string) ($_POST['reason'] ?? '')) ?: 'Acesso suspenso pela plataforma.'); }
    public function reactivate(string $id): string { return $this->transition((int) $id, ['suspended'], 'approved', 'Acesso atacadista reativado.', 'reactivated'); }

    public function reject(string $id): string
    {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (mb_strlen($reason) < 10) { Session::flash('error', 'Informe um motivo claro, com pelo menos 10 caracteres.'); return Response::redirect('/admin/atacadistas/' . (int) $id); }
        return $this->transition((int) $id, ['pending','under_review'], 'rejected', 'Cadastro devolvido para correção.', 'rejected', $reason);
    }

    public function downloadDocument(string $id, string $documentId): string
    {
        $statement = Database::connection()->prepare('SELECT d.* FROM wholesale_documents d WHERE d.id=? AND d.wholesale_account_id=?');
        $statement->execute([(int) $documentId, (int) $id]);
        $document = $statement->fetch();
        if (!$document) return $this->notFound();
        try { $path = (new WholesaleDocumentStorage())->path((string) $document['storage_key']); }
        catch (RuntimeException) { return $this->notFound(); }
        header('Content-Type: ' . $document['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', basename((string) $document['original_name'])) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        return '';
    }

    public function approveDocument(string $id, string $documentId): string
    {
        Database::connection()->prepare("UPDATE wholesale_documents SET status='approved',rejection_reason=NULL,reviewed_at=NOW() WHERE id=? AND wholesale_account_id=?")->execute([(int) $documentId, (int) $id]);
        Session::flash('success', 'Documento aprovado.');
        return Response::redirect('/admin/atacadistas/' . (int) $id);
    }

    public function rejectDocument(string $id, string $documentId): string
    {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (mb_strlen($reason) < 10) { Session::flash('error', 'Explique o problema encontrado no documento.'); return Response::redirect('/admin/atacadistas/' . (int) $id); }
        $account = $this->find((int) $id);
        if (!$account || !in_array($account['status'], ['pending','under_review'], true)) { Session::flash('error', 'O cadastro não está em análise.'); return Response::redirect('/admin/atacadistas/' . (int) $id); }
        $pdo = Database::connection(); $document = $pdo->prepare('SELECT original_name FROM wholesale_documents WHERE id=? AND wholesale_account_id=?'); $document->execute([(int) $documentId, (int) $id]); $name = $document->fetchColumn();
        if (!$name) return $this->notFound();
        $message = 'Documento ' . $name . ': ' . $reason;
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE wholesale_documents SET status='rejected',rejection_reason=?,reviewed_at=NOW() WHERE id=? AND wholesale_account_id=?")->execute([$reason, (int) $documentId, (int) $id]);
            $pdo->prepare("UPDATE wholesale_accounts SET status='rejected',rejection_reason=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([$message, Auth::id(), (int) $id]);
            $pdo->prepare("INSERT INTO wholesale_status_history(wholesale_account_id,previous_status,new_status,reason,changed_by) VALUES(?,?,'rejected',?,?)")->execute([(int) $id, $account['status'], $message, Auth::id()]);
            $pdo->commit();
        } catch (Throwable) { $pdo->rollBack(); Session::flash('error', 'Não foi possível rejeitar o documento.'); return Response::redirect('/admin/atacadistas/' . (int) $id); }
        (new WholesaleNotificationService())->customer((int) $account['user_id'], 'document_rejected', 'Documento precisa ser corrigido', $message);
        Session::flash('success', 'Documento devolvido para correção.');
        return Response::redirect('/admin/atacadistas/' . (int) $id);
    }

    /** @param array<int,string> $from */
    private function transition(int $id, array $from, string $to, string $success, string $event, ?string $reason = null): string
    {
        $account = $this->find($id);
        if (!$account || !in_array($account['status'], $from, true)) { Session::flash('error', 'Esta mudança de status não é permitida.'); return Response::redirect('/admin/atacadistas/' . $id); }
        $pdo = Database::connection(); $pdo->beginTransaction();
        try {
            $sets = ['status=?', 'reviewed_by=?', 'reviewed_at=NOW()']; $params = [$to, Auth::id()];
            if ($to === 'approved') $sets[] = 'approved_at=NOW(),suspended_at=NULL,rejection_reason=NULL';
            if ($to === 'rejected') { $sets[] = 'rejection_reason=?'; $params[] = $reason; }
            if ($to === 'suspended') { $sets[] = 'suspended_at=NOW(),rejection_reason=?'; $params[] = $reason; }
            $params[] = $id;
            $pdo->prepare('UPDATE wholesale_accounts SET ' . implode(',', $sets) . ' WHERE id=?')->execute($params);
            $pdo->prepare('INSERT INTO wholesale_status_history(wholesale_account_id,previous_status,new_status,reason,changed_by) VALUES(?,?,?,?,?)')->execute([$id, $account['status'], $to, $reason, Auth::id()]);
            $pdo->commit();
        } catch (Throwable) { $pdo->rollBack(); Session::flash('error', 'Não foi possível atualizar o cadastro.'); return Response::redirect('/admin/atacadistas/' . $id); }
        $messages = ['analysis_started' => 'Sua solicitação está sendo analisada.', 'approved' => 'Sua conta agora tem acesso a preços e condições de atacado.', 'rejected' => 'Precisamos de correções: ' . $reason, 'suspended' => 'Seu acesso foi suspenso: ' . $reason, 'reactivated' => 'Seu acesso aos recursos de atacado foi reativado.'];
        (new WholesaleNotificationService())->customer((int) $account['user_id'], $event, $success, $messages[$event] ?? $success);
        Session::flash('success', $success);
        return Response::redirect('/admin/atacadistas/' . $id);
    }

    private function find(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT wa.*,u.name customer_name,u.email customer_email,u.phone customer_phone FROM wholesale_accounts wa JOIN users u ON u.id=wa.user_id WHERE wa.id=?');
        $statement->execute([$id]); return $statement->fetch() ?: null;
    }

    private function notFound(): string { http_response_code(404); return $this->page('errors/404', 'layouts/admin', ['pageTitle' => 'Cadastro não encontrado', 'path' => 'atacadista']); }
}
