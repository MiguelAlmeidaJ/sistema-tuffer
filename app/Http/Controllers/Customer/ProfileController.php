<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Repositories\UserRepository;
use App\Services\Auth\PasswordPolicy;
use App\Services\Mail\QueuedMailService;
use Throwable;

final class ProfileController extends Controller
{
    public function edit(): string
    {
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE id=?');
        $statement->execute([Auth::id()]);
        return $this->page('customer/profile/edit', 'layouts/customer', ['pageTitle' => 'Meus dados', 'user' => $statement->fetch()]);
    }

    public function update(): string
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?? '';
        $document = preg_replace('/\D+/', '', (string) ($_POST['document'] ?? '')) ?? '';
        $password = (string) ($_POST['password'] ?? '');
        $errors = [];
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) $errors['name'] = 'Informe um nome válido.';
        if (!in_array(strlen($phone), [10, 11], true)) $errors['phone'] = 'Informe um telefone com DDD.';
        if (!in_array(strlen($document), [11, 14], true)) $errors['document'] = 'Informe um CPF ou CNPJ válido.';

        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT id,name,email,password_hash FROM users WHERE id=? AND status=\'active\' LIMIT 1');
        $statement->execute([Auth::id()]);
        $user = $statement->fetch();
        if (!$user) return Response::redirect('/entrar');

        if ($password !== '') {
            if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $user['password_hash'])) {
                $errors['current_password'] = 'Informe corretamente sua senha atual.';
            }
            if (($policyError = PasswordPolicy::error($password)) !== null) $errors['password'] = $policyError;
            if ($password !== (string) ($_POST['password_confirmation'] ?? '')) $errors['password_confirmation'] = 'As novas senhas não coincidem.';
            foreach (array_merge([(string) $user['password_hash']], (new UserRepository())->recentPasswordHashes((int) $user['id'])) as $knownHash) {
                if (password_verify($password, $knownHash)) {
                    $errors['password'] = 'Escolha uma senha que ainda não tenha sido usada nesta conta.';
                    break;
                }
            }
        }

        if ($errors !== []) {
            Session::flash('errors', $errors);
            Session::flash('old', ['name' => $name, 'phone' => $phone, 'document' => $document]);
            return Response::redirect('/minha-conta/perfil');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET name=?,phone=?,document=? WHERE id=?')->execute([$name, $phone ?: null, $document ?: null, Auth::id()]);
            if ($password !== '') {
                $pdo->prepare('INSERT INTO user_password_history(user_id,password_hash) VALUES(?,?)')->execute([(int) $user['id'], (string) $user['password_hash']]);
                $pdo->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
                $pdo->prepare('INSERT INTO password_change_audits(user_id,ip_address,user_agent) VALUES(?,?,?)')->execute([(int) $user['id'], (string) ($_SERVER['REMOTE_ADDR'] ?? ''), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Session::flash('error', 'Não foi possível atualizar seus dados.');
            return Response::redirect('/minha-conta/perfil');
        }

        if ($password !== '') {
            try {
                (new QueuedMailService())->enqueue((string) $user['name'], (string) $user['email'], 'Sua senha Tuffer foi alterada', "Sua senha foi alterada pela área de perfil. Se não foi você, entre em contato com o suporte imediatamente.", 'password_changed', 'user', (int) $user['id'], 'profile-password-changed:' . $user['id'] . ':' . time());
            } catch (Throwable $exception) {
                Logger::exception($exception, ['user_id' => $user['id']], 'security');
            }
            Auth::logout();
            Session::flash('success', 'Senha alterada. Entre novamente com a nova senha.');
            return Response::redirect('/entrar');
        }

        Session::flash('success', 'Dados atualizados.');
        return Response::redirect('/minha-conta/perfil');
    }
}
