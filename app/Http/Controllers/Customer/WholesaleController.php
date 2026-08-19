<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Wholesale\CnpjValidator;
use App\Services\Wholesale\CpfValidator;
use App\Services\Wholesale\WholesaleDocumentStorage;
use App\Services\Wholesale\WholesaleNotificationService;
use PDO;
use Throwable;

final class WholesaleController extends Controller
{
    private const TERMS_VERSION = '2026-07';
    private const EDITABLE = ['draft', 'rejected', 'cancelled'];
    private const DOCUMENT_TYPES = ['cnpj_card', 'articles_of_association', 'responsible_document', 'business_address_proof', 'state_registration', 'other'];

    public function index(): string
    {
        $account = $this->account();
        if ($account && !in_array($account['status'], self::EDITABLE, true)) return Response::redirect('/minha-conta/atacado/status');
        return $this->page('customer/wholesale/index', 'layouts/customer', ['pageTitle' => 'Comprar no atacado', 'account' => $account, 'emailVerified' => $this->emailVerified()]);
    }

    public function create(): string
    {
        if (!$this->emailVerified()) {
            Session::flash('error', 'Confirme seu e-mail antes de solicitar acesso ao atacado.');
            return Response::redirect('/minha-conta/atacado');
        }
        $account = $this->account();
        if ($account && !in_array($account['status'], self::EDITABLE, true)) return Response::redirect('/minha-conta/atacado/status');
        return $this->step('company', $account);
    }

    public function saveCompany(): string
    {
        if (!$this->emailVerified()) return $this->blockedEmail();
        $validator = new CnpjValidator();
        $cnpj = $validator->normalize((string) ($_POST['cnpj'] ?? ''));
        $data = [
            'cnpj' => $cnpj,
            'legal_name' => trim((string) ($_POST['legal_name'] ?? '')),
            'trade_name' => trim((string) ($_POST['trade_name'] ?? '')),
            'state_registration_status' => (string) ($_POST['state_registration_status'] ?? ''),
            'state_registration' => trim((string) ($_POST['state_registration'] ?? '')),
            'opened_at' => trim((string) ($_POST['opened_at'] ?? '')),
            'business_phone' => trim((string) ($_POST['business_phone'] ?? '')),
            'business_email' => mb_strtolower(trim((string) ($_POST['business_email'] ?? ''))),
            'website' => trim((string) ($_POST['website'] ?? '')),
            'business_segment' => trim((string) ($_POST['business_segment'] ?? '')),
            'average_monthly_volume' => max(0, (int) ($_POST['average_monthly_volume'] ?? 0)),
        ];
        $errors = [];
        if (!$validator->isValid($cnpj)) $errors['cnpj'] = 'Informe um CNPJ válido.';
        if (mb_strlen($data['legal_name']) < 3) $errors['legal_name'] = 'Informe a razão social.';
        if (mb_strlen($data['trade_name']) < 2) $errors['trade_name'] = 'Informe o nome fantasia.';
        if (!in_array($data['state_registration_status'], ['taxpayer', 'exempt', 'non_taxpayer'], true)) $errors['state_registration_status'] = 'Informe a situação da inscrição estadual.';
        if ($data['state_registration_status'] === 'taxpayer' && $data['state_registration'] === '') $errors['state_registration'] = 'Informe a inscrição estadual.';
        if ($data['business_email'] !== '' && !filter_var($data['business_email'], FILTER_VALIDATE_EMAIL)) $errors['business_email'] = 'Informe um e-mail comercial válido.';
        $duplicate = Database::connection()->prepare('SELECT COUNT(*) FROM wholesale_accounts WHERE cnpj=? AND user_id<>?');
        $duplicate->execute([$cnpj, Auth::id()]);
        if ((int) $duplicate->fetchColumn() > 0) $errors['cnpj'] = 'Este CNPJ já está vinculado a outra conta.';
        if ($errors) return $this->validationFailure($errors, $data, '/minha-conta/atacado/solicitar');

        $account = $this->account();
        if ($account && !in_array($account['status'], self::EDITABLE, true)) return Response::redirect('/minha-conta/atacado/status');
        $values = [$cnpj, $data['legal_name'], $data['trade_name'] ?: null, $data['state_registration'] ?: null, $data['state_registration_status'], $data['opened_at'] ?: null, $data['business_phone'] ?: null, $data['business_email'] ?: null, $data['website'] ?: null, $data['business_segment'] ?: null, $data['average_monthly_volume'] ?: null];
        try {
            if ($account) {
                Database::connection()->prepare('UPDATE wholesale_accounts SET cnpj=?,legal_name=?,trade_name=?,state_registration=?,state_registration_status=?,opened_at=?,business_phone=?,business_email=?,website=?,business_segment=?,average_monthly_volume=? WHERE id=?')->execute([...$values, $account['id']]);
            } else {
                Database::connection()->prepare("INSERT INTO wholesale_accounts(user_id,cnpj,legal_name,trade_name,state_registration,state_registration_status,opened_at,business_phone,business_email,website,business_segment,average_monthly_volume,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?, 'draft')")->execute([Auth::id(), ...$values]);
                $id = (int) Database::connection()->lastInsertId();
                Database::connection()->prepare("INSERT INTO wholesale_status_history(wholesale_account_id,previous_status,new_status,changed_by) VALUES(?,NULL,'draft',?)")->execute([$id, Auth::id()]);
            }
        } catch (Throwable) {
            return $this->validationFailure(['cnpj' => 'Não foi possível vincular este CNPJ. Verifique se ele já está cadastrado.'], $data, '/minha-conta/atacado/solicitar');
        }
        return Response::redirect('/minha-conta/atacado/solicitar?etapa=responsavel');
    }

    public function saveResponsible(): string
    {
        $account = $this->editableAccount();
        if (!$account) return Response::redirect('/minha-conta/atacado');
        $cpfValidator = new CpfValidator();
        $data = ['name' => trim((string) ($_POST['name'] ?? '')), 'cpf' => $cpfValidator->normalize((string) ($_POST['cpf'] ?? '')), 'position' => trim((string) ($_POST['position'] ?? '')), 'email' => mb_strtolower(trim((string) ($_POST['email'] ?? ''))), 'phone' => trim((string) ($_POST['phone'] ?? ''))];
        $errors = [];
        if (mb_strlen($data['name']) < 3) $errors['name'] = 'Informe o nome completo.';
        if (!$cpfValidator->isValid($data['cpf'])) $errors['cpf'] = 'Informe um CPF válido para o responsável.';
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Informe um e-mail válido.';
        if (mb_strlen($data['phone']) < 8) $errors['phone'] = 'Informe o telefone do responsável.';
        if ($errors) return $this->validationFailure($errors, $data, '/minha-conta/atacado/solicitar?etapa=responsavel');
        $existing = Database::connection()->prepare('SELECT id FROM wholesale_responsibles WHERE wholesale_account_id=? ORDER BY is_primary DESC,id LIMIT 1');
        $existing->execute([$account['id']]);
        $responsibleId = (int) $existing->fetchColumn();
        if ($responsibleId) Database::connection()->prepare('UPDATE wholesale_responsibles SET name=?,cpf=?,position=?,email=?,phone=? WHERE id=?')->execute([$data['name'], $data['cpf'], $data['position'] ?: null, $data['email'], $data['phone'], $responsibleId]);
        else Database::connection()->prepare('INSERT INTO wholesale_responsibles(wholesale_account_id,name,cpf,position,email,phone,is_primary) VALUES(?,?,?,?,?,?,1)')->execute([$account['id'], $data['name'], $data['cpf'], $data['position'] ?: null, $data['email'], $data['phone']]);
        return Response::redirect('/minha-conta/atacado/solicitar?etapa=endereco');
    }

    public function saveAddress(): string
    {
        $account = $this->editableAccount();
        if (!$account) return Response::redirect('/minha-conta/atacado');
        $data = ['type' => (string) ($_POST['type'] ?? 'both'), 'postal_code' => preg_replace('/\D+/', '', (string) ($_POST['postal_code'] ?? '')) ?? '', 'street' => trim((string) ($_POST['street'] ?? '')), 'number' => trim((string) ($_POST['number'] ?? '')), 'complement' => trim((string) ($_POST['complement'] ?? '')), 'district' => trim((string) ($_POST['district'] ?? '')), 'city' => trim((string) ($_POST['city'] ?? '')), 'state' => mb_strtoupper(trim((string) ($_POST['state'] ?? '')))];
        $errors = [];
        if (!in_array($data['type'], ['billing', 'shipping', 'both'], true)) $data['type'] = 'both';
        if (strlen($data['postal_code']) !== 8) $errors['postal_code'] = 'Informe um CEP com 8 números.';
        foreach (['street' => 'logradouro', 'number' => 'número', 'district' => 'bairro', 'city' => 'cidade'] as $key => $label) if ($data[$key] === '') $errors[$key] = 'Informe ' . $label . '.';
        if (!preg_match('/^[A-Z]{2}$/', $data['state'])) $errors['state'] = 'Informe a UF.';
        if ($errors) return $this->validationFailure($errors, $data, '/minha-conta/atacado/solicitar?etapa=endereco');
        $statement = Database::connection()->prepare('SELECT id FROM wholesale_addresses WHERE wholesale_account_id=? ORDER BY id LIMIT 1');
        $statement->execute([$account['id']]);
        $id = (int) $statement->fetchColumn();
        $values = [$data['type'], $data['postal_code'], $data['street'], $data['number'], $data['complement'] ?: null, $data['district'], $data['city'], $data['state']];
        if ($id) Database::connection()->prepare('UPDATE wholesale_addresses SET type=?,postal_code=?,street=?,number=?,complement=?,district=?,city=?,state=? WHERE id=?')->execute([...$values, $id]);
        else Database::connection()->prepare('INSERT INTO wholesale_addresses(wholesale_account_id,type,postal_code,street,number,complement,district,city,state) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$account['id'], ...$values]);
        return Response::redirect('/minha-conta/atacado/solicitar?etapa=documentos');
    }

    public function uploadDocuments(): string
    {
        $account = $this->editableAccount();
        if (!$account) return Response::redirect('/minha-conta/atacado');
        $storage = new WholesaleDocumentStorage();
        $uploaded = 0;
        $newKeys = [];
        $oldKeys = [];
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            foreach (self::DOCUMENT_TYPES as $type) {
                $file = $this->nestedFile('documents', $type);
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $stored = $storage->store($file, (int) $account['id']);
                $newKeys[] = $stored['storage_key'];
                $old = $pdo->prepare('SELECT id,storage_key FROM wholesale_documents WHERE wholesale_account_id=? AND type=? ORDER BY id DESC LIMIT 1');
                $old->execute([$account['id'], $type]);
                $previous = $old->fetch();
                $pdo->prepare("INSERT INTO wholesale_documents(wholesale_account_id,type,original_name,storage_key,mime_type,file_size,status) VALUES(?,?,?,?,?,?,'pending')")->execute([$account['id'], $type, $stored['original_name'], $stored['storage_key'], $stored['mime_type'], $stored['file_size']]);
                if ($previous) { $pdo->prepare('DELETE FROM wholesale_documents WHERE id=?')->execute([$previous['id']]); $oldKeys[] = (string) $previous['storage_key']; }
                $uploaded++;
            }
            $pdo->commit();
            foreach ($oldKeys as $oldKey) $storage->delete($oldKey);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($newKeys as $newKey) $storage->delete($newKey);
            Session::flash('error', $exception->getMessage());
            return Response::redirect('/minha-conta/atacado/solicitar?etapa=documentos');
        }
        Session::flash($uploaded ? 'success' : 'error', $uploaded ? 'Documentos armazenados com segurança.' : 'Selecione ao menos um documento.');
        return Response::redirect($uploaded ? '/minha-conta/atacado/revisao' : '/minha-conta/atacado/solicitar?etapa=documentos');
    }

    public function deleteDocument(string $id): string
    {
        $account = $this->editableAccount();
        if (!$account) return Response::redirect('/minha-conta/atacado/status');
        $statement = Database::connection()->prepare('SELECT * FROM wholesale_documents WHERE id=? AND wholesale_account_id=?');
        $statement->execute([(int) $id, $account['id']]);
        if ($document = $statement->fetch()) { Database::connection()->prepare('DELETE FROM wholesale_documents WHERE id=?')->execute([$document['id']]); (new WholesaleDocumentStorage())->delete((string) $document['storage_key']); }
        return Response::redirect('/minha-conta/atacado/solicitar?etapa=documentos');
    }

    public function review(): string
    {
        $account = $this->editableAccount();
        if (!$account) return Response::redirect('/minha-conta/atacado/status');
        return $this->page('customer/wholesale/review', 'layouts/customer', $this->related($account) + ['pageTitle' => 'Revisar cadastro atacadista']);
    }

    public function submit(): string
    {
        $account = $this->editableAccount();
        if (!$account) return Response::redirect('/minha-conta/atacado/status');
        $related = $this->related($account);
        $errors = [];
        if (!$related['responsible']) $errors[] = 'responsável';
        if (!$related['address']) $errors[] = 'endereço empresarial';
        if (!$related['documents']) $errors[] = 'ao menos um documento';
        if (empty($_POST['truth']) || empty($_POST['terms'])) $errors[] = 'aceite das declarações e termos';
        if ($errors) { Session::flash('error', 'Complete: ' . implode(', ', $errors) . '.'); return Response::redirect('/minha-conta/atacado/revisao'); }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE wholesale_accounts SET status='pending',rejection_reason=NULL,submitted_at=NOW(),terms_accepted_at=NOW(),terms_version=? WHERE id=? AND status IN ('draft','rejected','cancelled')")->execute([self::TERMS_VERSION, $account['id']]);
            $pdo->prepare("INSERT INTO wholesale_status_history(wholesale_account_id,previous_status,new_status,changed_by) VALUES(?,?,'pending',?)")->execute([$account['id'], $account['status'], Auth::id()]);
            $pdo->commit();
        } catch (Throwable $exception) { $pdo->rollBack(); Session::flash('error', 'Não foi possível enviar o cadastro.'); return Response::redirect('/minha-conta/atacado/revisao'); }
        (new WholesaleNotificationService())->admins('submitted', 'Nova solicitação de atacado', $account['trade_name'] . ' enviou um cadastro para análise.');
        Session::flash('success', 'Cadastro enviado para análise. Você será avisado sobre cada atualização.');
        return Response::redirect('/minha-conta/atacado/status');
    }

    public function status(): string
    {
        $account = $this->account();
        if (!$account) return Response::redirect('/minha-conta/atacado');
        return $this->page('customer/wholesale/status', 'layouts/customer', $this->related($account) + ['pageTitle' => 'Status do atacado']);
    }

    private function step(string $default, ?array $account): string
    {
        $step = (string) ($_GET['etapa'] ?? $default);
        if (!in_array($step, ['company', 'responsavel', 'endereco', 'documentos'], true)) $step = $default;
        if ($step !== 'company' && !$account) return Response::redirect('/minha-conta/atacado/solicitar');
        $views = ['company' => 'company', 'responsavel' => 'responsible', 'endereco' => 'address', 'documentos' => 'documents'];
        $data = $account ? $this->related($account) : ['account' => null, 'responsible' => null, 'address' => null, 'documents' => [], 'history' => []];
        return $this->page('customer/wholesale/' . $views[$step], 'layouts/customer', $data + ['pageTitle' => 'Cadastro empresarial', 'step' => $step]);
    }

    private function account(): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM wholesale_accounts WHERE user_id=? LIMIT 1');
        $statement->execute([Auth::id()]);
        return $statement->fetch() ?: null;
    }

    private function editableAccount(): ?array
    {
        $account = $this->account();
        return $account && in_array($account['status'], self::EDITABLE, true) ? $account : null;
    }

    /** @return array<string,mixed> */
    private function related(array $account): array
    {
        $pdo = Database::connection();
        $queries = [
            'responsible' => ['SELECT * FROM wholesale_responsibles WHERE wholesale_account_id=? ORDER BY is_primary DESC,id LIMIT 1', false],
            'address' => ['SELECT * FROM wholesale_addresses WHERE wholesale_account_id=? ORDER BY id LIMIT 1', false],
            'documents' => ['SELECT * FROM wholesale_documents WHERE wholesale_account_id=? ORDER BY type,id', true],
            'history' => ['SELECT h.*,u.name changed_by_name FROM wholesale_status_history h LEFT JOIN users u ON u.id=h.changed_by WHERE h.wholesale_account_id=? ORDER BY h.created_at DESC,h.id DESC', true],
        ];
        $data = ['account' => $account];
        foreach ($queries as $key => [$sql, $many]) { $statement = $pdo->prepare($sql); $statement->execute([$account['id']]); $data[$key] = $many ? $statement->fetchAll() : ($statement->fetch() ?: null); }
        return $data;
    }

    private function emailVerified(): bool
    {
        $statement = Database::connection()->prepare('SELECT email_verified_at IS NOT NULL FROM users WHERE id=?');
        $statement->execute([Auth::id()]);
        return (bool) $statement->fetchColumn();
    }

    private function blockedEmail(): string { Session::flash('error', 'Confirme seu e-mail antes de continuar.'); return Response::redirect('/minha-conta/atacado'); }

    /** @param array<string,string> $errors @param array<string,mixed> $old */
    private function validationFailure(array $errors, array $old, string $path): string
    {
        Session::flash('errors', $errors); Session::flash('old', $old); Session::flash('error', 'Revise os campos destacados.'); return Response::redirect($path);
    }

    /** @return array<string,mixed>|null */
    private function nestedFile(string $group, string $key): ?array
    {
        if (!isset($_FILES[$group]) || !is_array($_FILES[$group])) return null;
        $file = $_FILES[$group];
        return ['name' => $file['name'][$key] ?? '', 'type' => $file['type'][$key] ?? '', 'tmp_name' => $file['tmp_name'][$key] ?? '', 'error' => $file['error'][$key] ?? UPLOAD_ERR_NO_FILE, 'size' => $file['size'][$key] ?? 0];
    }
}
