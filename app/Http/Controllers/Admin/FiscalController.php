<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Fiscal\FiscalDocumentStorage;
use PDO;
use RuntimeException;

final class FiscalController extends Controller
{
    public function index(): string
    {
        $pdo = Database::connection();
        $allowedStatuses = ['pending','configuration_required','validation_failed','ready','submitting','processing','authorized','rejected','error','awaiting_manual','awaiting_external','cancelled','voided'];
        $allowedModes = ['platform','manual','external'];
        $status = in_array((string)($_GET['status'] ?? ''), $allowedStatuses, true) ? (string)$_GET['status'] : '';
        $mode = in_array((string)($_GET['mode'] ?? ''), $allowedModes, true) ? (string)$_GET['mode'] : '';
        $actionRequired = (string)($_GET['action'] ?? '') === '1';
        $search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

        $where = [];
        $params = [];
        if ($status !== '') { $where[] = 'fd.status=?'; $params[] = $status; }
        if ($mode !== '') { $where[] = 'fd.issuance_mode=?'; $params[] = $mode; }
        if ($actionRequired) $where[] = 'fd.requires_action=1';
        if ($search !== '') {
            $where[] = '(so.code LIKE ? OR o.code LIKE ? OR st.name LIKE ? OR fd.access_key LIKE ? OR CAST(fd.number AS CHAR) LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $sql = "SELECT fd.*,so.code seller_order_code,o.code order_code,st.name store_name,
                       COALESCE(NULLIF(s.trade_name,''),s.legal_name) seller_name
                FROM fiscal_documents fd
                JOIN seller_orders so ON so.id=fd.seller_order_id
                JOIN orders o ON o.id=so.order_id
                JOIN stores st ON st.id=fd.store_id
                JOIN sellers s ON s.id=fd.seller_id";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY fd.requires_action DESC,fd.id DESC LIMIT 250';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $summary = $pdo->query("SELECT COUNT(*) total,
            COALESCE(SUM(status='authorized'),0) authorized,
            COALESCE(SUM(status='cancelled'),0) cancelled,
            COALESCE(SUM(requires_action=1),0) action_required,
            COALESCE(SUM(status IN ('pending','configuration_required','validation_failed','ready','submitting','processing','awaiting_manual','awaiting_external','error','rejected')),0) open_documents
            FROM fiscal_documents")->fetch();

        return $this->page('admin/fiscal/index','layouts/admin',[
            'pageTitle'=>'Fiscal','documents'=>$stmt->fetchAll(),'summary'=>$summary ?: [],'statusFilter'=>$status,'modeFilter'=>$mode,'actionFilter'=>$actionRequired,'searchFilter'=>$search,
        ]);
    }

    public function show(string $id): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT fd.*,so.code seller_order_code,o.code order_code,st.name store_name,
                                      COALESCE(NULLIF(s.trade_name,''),s.legal_name) seller_name
                               FROM fiscal_documents fd
                               JOIN seller_orders so ON so.id=fd.seller_order_id
                               JOIN orders o ON o.id=so.order_id
                               JOIN stores st ON st.id=fd.store_id
                               JOIN sellers s ON s.id=fd.seller_id
                               WHERE fd.id=? LIMIT 1");
        $stmt->execute([(int)$id]);
        $document = $stmt->fetch();
        if (!is_array($document)) { http_response_code(404); return $this->page('errors/404','layouts/admin',['pageTitle'=>'Documento fiscal não encontrado']); }

        $stmt = $pdo->prepare('SELECT * FROM fiscal_document_items WHERE fiscal_document_id=? ORDER BY id');
        $stmt->execute([$document['id']]);
        $items = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT * FROM fiscal_events WHERE fiscal_document_id=? ORDER BY id DESC LIMIT 200');
        $stmt->execute([$document['id']]);
        $events = $stmt->fetchAll();

        return $this->page('admin/fiscal/show','layouts/admin',['pageTitle'=>'Documento fiscal #'.$document['id'],'document'=>$document,'items'=>$items,'events'=>$events]);
    }

    public function xml(string $id): string { return $this->download((int)$id, 'xml'); }
    public function danfe(string $id): string { return $this->download((int)$id, 'danfe'); }

    private function download(int $id, string $type): string
    {
        $column = $type === 'xml' ? 'xml_storage_path' : 'danfe_storage_path';
        $stmt = Database::connection()->prepare("SELECT id,access_key,{$column} storage_path FROM fiscal_documents WHERE id=? AND status IN ('authorized','cancelled') LIMIT 1");
        $stmt->execute([$id]);
        $document = $stmt->fetch();
        if (!is_array($document) || empty($document['storage_path'])) {
            Session::flash('error','Arquivo fiscal ainda não disponível.');
            return Response::redirect('/admin/fiscal/' . $id);
        }
        try {
            $path = (new FiscalDocumentStorage())->path((string)$document['storage_path']);
            $key = preg_replace('/\D+/', '', (string)($document['access_key'] ?? '')) ?: (string)$id;
            return Response::privateFile($path, $type === 'xml' ? 'application/xml; charset=utf-8' : 'application/pdf', 'nfe-' . $key . '.' . ($type === 'xml' ? 'xml' : 'pdf'));
        } catch (RuntimeException) {
            Session::flash('error','Arquivo fiscal não encontrado no armazenamento privado.');
            return Response::redirect('/admin/fiscal/' . $id);
        }
    }
}
