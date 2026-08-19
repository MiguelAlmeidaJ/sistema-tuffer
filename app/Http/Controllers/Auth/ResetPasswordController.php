<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\ResetCodeService;
use App\Services\Mail\QueuedMailService;
use Throwable;
use App\Services\Auth\PasswordPolicy;

final class ResetPasswordController extends Controller
{
    public function code(): string
    {
        $email = (string) Session::get('password_reset_email', '');
        if ($email === '') {
            return Response::redirect('/esqueci-minha-senha');
        }

        return $this->page('auth/verify-reset-code', 'layouts/auth', [
            'pageTitle' => 'Confirmar código',
            'maskedEmail' => $this->maskEmail($email),
        ]);
    }

    public function verify(): string
    {
        $email = (string) Session::get('password_reset_email', '');
        if ($email === '') {
            return Response::redirect('/esqueci-minha-senha');
        }

        $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? '')) ?? '';
        if (strlen($code) !== 8) {
            Session::flash('errors', ['code' => 'Digite o código completo de 8 números.']);
            return Response::redirect('/redefinir-senha/codigo');
        }

        $resets = new PasswordResetRepository();
        $reset = $resets->findLatestOpenByEmail($email);

        if ($reset === null || strtotime((string) $reset['expires_at']) < time() || (int) $reset['attempts'] >= 5) {
            Session::flash('errors', ['code' => 'O código expirou. Solicite um novo código.']);
            return Response::redirect('/redefinir-senha/codigo');
        }

        if (!(new ResetCodeService())->verify($code, (string) $reset['code_hash'])) {
            $resets->incrementAttempts((int) $reset['id']);
            Session::flash('errors', ['code' => 'O código informado é inválido.']);
            return Response::redirect('/redefinir-senha/codigo');
        }

        $resets->markAsVerified((int) $reset['id']);
        Session::put('verified_password_reset_id', (int) $reset['id']);

        return Response::redirect('/redefinir-senha');
    }

    public function edit(): string
    {
        $resetId = (int) Session::get('verified_password_reset_id', 0);
        if ($resetId < 1 || (new PasswordResetRepository())->findVerifiedById($resetId) === null) {
            return Response::redirect('/esqueci-minha-senha');
        }

        return $this->page('auth/reset-password', 'layouts/auth', ['pageTitle' => 'Criar nova senha']);
    }

    public function update(): string
    {
        $resetId = (int) Session::get('verified_password_reset_id', 0);
        $resets = new PasswordResetRepository();
        $reset = $resetId > 0 ? $resets->findVerifiedById($resetId) : null;
        if ($reset === null) {
            return Response::redirect('/esqueci-minha-senha');
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        $errors = [];
        if (($policyError = PasswordPolicy::error($password)) !== null) $errors['password'] = $policyError;
        if ($password !== $confirmation) {
            $errors['password_confirmation'] = 'As senhas não coincidem.';
        }

        $pdo = Database::connection();
        $userStatement = $pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE id = ? LIMIT 1');
        $userStatement->execute([(int) $reset['user_id']]);
        $user = $userStatement->fetch();
        if (!$user) {
            return Response::redirect('/esqueci-minha-senha');
        }

        $knownHashes = array_merge([(string) $user['password_hash']], (new UserRepository())->recentPasswordHashes((int) $user['id']));
        foreach ($knownHashes as $knownHash) {
            if (password_verify($password, $knownHash)) {
                $errors['password'] = 'Escolha uma senha que ainda não tenha sido usada nesta conta.';
                break;
            }
        }

        if ($errors !== []) {
            Session::flash('errors', $errors);
            return Response::redirect('/redefinir-senha');
        }

        $pdo->beginTransaction();
        try {
            $lockReset = $pdo->prepare('SELECT id, user_id FROM password_reset_codes WHERE id = ? AND verified_at IS NOT NULL AND used_at IS NULL AND expires_at >= NOW() FOR UPDATE');
            $lockReset->execute([$resetId]);
            if (!$lockReset->fetch()) {
                $pdo->rollBack();
                return Response::redirect('/esqueci-minha-senha');
            }

            $pdo->prepare('INSERT INTO user_password_history (user_id, password_hash) VALUES (?, ?)')
                ->execute([(int) $user['id'], (string) $user['password_hash']]);
            $pdo->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
            $pdo->prepare('UPDATE password_reset_codes SET used_at = NOW() WHERE id = ?')
                ->execute([$resetId]);
            $pdo->prepare('UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
                ->execute([(int) $user['id']]);
            $pdo->prepare('INSERT INTO password_change_audits (user_id, ip_address, user_agent) VALUES (?, ?, ?)')
                ->execute([
                    (int) $user['id'],
                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        Session::forget('password_reset_email');
        Session::forget('verified_password_reset_id');
        Session::put('password_reset_completed', true);
        try {
            (new QueuedMailService())->enqueue((string) $user['name'], (string) $user['email'], 'Sua senha Tuffer foi alterada', "Olá, {$user['name']}.\n\nSua senha foi alterada com sucesso. Se não foi você, entre em contato com o suporte imediatamente.", 'password_changed', 'user', (int) $user['id'], 'password-changed:' . $user['id'] . ':' . $resetId);
        } catch (Throwable $exception) {
            Logger::exception($exception, ['user_id' => $user['id']], 'security');
        }

        return Response::redirect('/senha-alterada');
    }

    public function success(): string
    {
        if (Session::get('password_reset_completed') !== true) {
            return Response::redirect('/entrar');
        }

        Session::forget('password_reset_completed');
        return $this->page('auth/password-success', 'layouts/auth', ['pageTitle' => 'Senha alterada']);
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($name, 0, min(2, mb_strlen($name)));
        $hidden = str_repeat('*', max(3, mb_strlen($name) - 2));

        return $visible . $hidden . '@' . $domain;
    }
}
