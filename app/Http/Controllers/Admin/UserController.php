<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordPolicy;
use RuntimeException;
use Throwable;

final class UserController extends Controller
{
    private const TYPES = ['operator', 'seller', 'customer', 'admin'];
    private const STATUSES = ['active', 'inactive', 'blocked'];
    private const STORE_ROLES = ['manager', 'catalog', 'orders', 'support', 'finance'];

    public function index(): string
    {
        $users = Database::connection()->query("SELECT u.id,u.name,u.email,u.type,u.status,u.created_at,GROUP_CONCAT(st.name ORDER BY st.name SEPARATOR ', ') stores FROM users u LEFT JOIN store_users su ON su.user_id=u.id LEFT JOIN stores st ON st.id=su.store_id GROUP BY u.id ORDER BY u.created_at DESC")->fetchAll();
        return $this->page('admin/users/index', 'layouts/admin', ['pageTitle' => 'Usuários', 'users' => $users]);
    }

    public function create(): string { return $this->form(); }

    public function store(): string
    {
        $pdo = Database::connection();
        try {
            $data = $this->validatedData(true);
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO users(name,email,phone,password_hash,type,status,email_verified_at) VALUES(?,?,?,?,?,?,NOW())')
                ->execute([$data['name'], $data['email'], $data['phone'], password_hash($data['password'], PASSWORD_DEFAULT), $data['type'], $data['status']]);
            $id = (int) $pdo->lastInsertId();
            $this->syncStore($id, $data['store_id'], $data['store_role']);
            $pdo->commit();
            Session::flash('success', 'Usuário criado.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Session::flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível criar. Verifique o e-mail.');
        }
        return Response::redirect('/admin/usuarios');
    }

    public function edit(string $id): string
    {
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE id=?');
        $statement->execute([(int) $id]);
        return $this->form($statement->fetch() ?: null);
    }

    public function update(string $id): string
    {
        $userId = (int) $id;
        $pdo = Database::connection();
        try {
            $data = $this->validatedData(false);
            $current = $pdo->prepare('SELECT id,type,status,password_hash FROM users WHERE id=? LIMIT 1');
            $current->execute([$userId]);
            $user = $current->fetch();
            if (!$user) throw new RuntimeException('Usuário não encontrado.');
            if ($userId === Auth::id() && ($data['type'] !== 'admin' || $data['status'] !== 'active')) throw new RuntimeException('Você não pode remover o próprio acesso administrativo.');

            $pdo->beginTransaction();
            $invalidateSessions = $data['type'] !== $user['type'] || $data['status'] !== $user['status'] || $data['password'] !== '';
            $pdo->prepare('UPDATE users SET name=?,email=?,phone=?,type=?,status=?,auth_version=auth_version+? WHERE id=?')
                ->execute([$data['name'], $data['email'], $data['phone'], $data['type'], $data['status'], $invalidateSessions ? 1 : 0, $userId]);
            if ($data['password'] !== '') {
                $pdo->prepare('INSERT INTO user_password_history(user_id,password_hash) VALUES(?,?)')->execute([$userId, (string) $user['password_hash']]);
                $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($data['password'], PASSWORD_DEFAULT), $userId]);
                $pdo->prepare('INSERT INTO password_change_audits(user_id,ip_address,user_agent) VALUES(?,?,?)')->execute([$userId, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'Alterada por administrador #' . Auth::id()]);
            }
            $this->syncStore($userId, $data['store_id'], $data['store_role']);
            $pdo->commit();
            Session::flash('success', 'Usuário atualizado e sessões sensíveis invalidadas.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Session::flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível atualizar.');
        }
        return Response::redirect('/admin/usuarios');
    }

    /** @return array{name:string,email:string,phone:?string,password:string,type:string,status:string,store_id:int,store_role:string} */
    private function validatedData(bool $requirePassword): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $type = (string) ($_POST['type'] ?? 'operator');
        $status = (string) ($_POST['status'] ?? 'active');
        $storeRole = (string) ($_POST['store_role'] ?? 'manager');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) throw new RuntimeException('Informe um nome válido.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
        if (!in_array($type, self::TYPES, true) || !in_array($status, self::STATUSES, true)) throw new RuntimeException('Perfil ou status inválido.');
        if (!in_array($storeRole, self::STORE_ROLES, true)) throw new RuntimeException('Função de loja inválida.');
        if ($requirePassword && $password === '') throw new RuntimeException('Informe uma senha inicial.');
        if ($password !== '' && ($error = PasswordPolicy::error($password)) !== null) throw new RuntimeException($error);
        return ['name' => $name, 'email' => $email, 'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null, 'password' => $password, 'type' => $type, 'status' => $status, 'store_id' => max(0, (int) ($_POST['store_id'] ?? 0)), 'store_role' => $storeRole];
    }

    private function syncStore(int $userId, int $storeId, string $role): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM store_users WHERE user_id=?')->execute([$userId]);
        if ($storeId < 1) return;
        $store = $pdo->prepare('SELECT id FROM stores WHERE id=? LIMIT 1');
        $store->execute([$storeId]);
        if (!$store->fetchColumn()) throw new RuntimeException('Loja selecionada não existe.');
        $pdo->prepare('INSERT INTO store_users(store_id,user_id,role) VALUES(?,?,?)')->execute([$storeId, $userId, $role]);
    }

    private function form(?array $user = null): string
    {
        if ($user === null && func_num_args() > 0) {
            http_response_code(404);
            return $this->page('errors/404', 'layouts/admin', ['pageTitle' => 'Usuário não encontrado', 'path' => 'usuário']);
        }
        $stores = Database::connection()->query('SELECT id,name FROM stores ORDER BY name')->fetchAll();
        $link = null;
        if ($user) {
            $statement = Database::connection()->prepare('SELECT * FROM store_users WHERE user_id=? LIMIT 1');
            $statement->execute([$user['id']]);
            $link = $statement->fetch() ?: null;
        }
        return $this->page('admin/users/form', 'layouts/admin', ['pageTitle' => $user ? 'Editar usuário' : 'Criar usuário', 'user' => $user, 'stores' => $stores, 'link' => $link]);
    }
}
