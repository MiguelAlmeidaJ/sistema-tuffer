<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordPolicy;
use App\Services\Wholesale\CpfValidator;
use PDOException;

final class RegisterController extends Controller
{
    public function create(): string
    {
        return $this->page('auth/register', 'layouts/auth', [
            'pageTitle' => 'Criar conta',
            'redirectPath' => $this->safeRedirect((string) ($_GET['redirect'] ?? '')),
        ]);
    }

    public function store(): string
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?? '';
        $cpfValidator = new CpfValidator();
        $document = $cpfValidator->normalize((string) ($_POST['document'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        $redirect = $this->safeRedirect((string) ($_POST['redirect'] ?? ''));
        $errors = [];

        if (mb_strlen($name) < 3) $errors['name'] = 'Informe seu nome completo.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Informe um endereço de e-mail válido.';
        if (!in_array(strlen($phone), [10, 11], true)) $errors['phone'] = 'Informe um telefone com DDD.';
        if (!$cpfValidator->isValid($document)) $errors['document'] = 'Informe um CPF válido.';
        if (($policyError = PasswordPolicy::error($password)) !== null) $errors['password'] = $policyError;
        if ($password !== $confirmation) $errors['password_confirmation'] = 'As senhas não coincidem.';
        if (!isset($_POST['terms'])) $errors['terms'] = 'Você precisa aceitar os termos para continuar.';

        if ($errors !== []) {
            Session::flash('errors', $errors);
            $personalDataHasErrors = isset($errors['name']) || isset($errors['email']) || isset($errors['phone']) || isset($errors['document']);
            Session::flash('old', [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'document' => $document,
                'step' => $personalDataHasErrors ? 1 : 2,
            ]);
            return Response::redirect($this->registerPath($redirect));
        }

        $pdo = Database::connection();
        try {
            $statement = $pdo->prepare("INSERT INTO users (name, email, phone, document, password_hash, type, status) VALUES (?, ?, ?, ?, ?, 'customer', 'active')");
            $statement->execute([$name, $email, $phone, $document, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException) {
            Session::flash('errors', ['email' => 'Não foi possível criar a conta. Verifique se este e-mail já possui cadastro.']);
            Session::flash('old', [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'document' => $document,
                'step' => 1,
            ]);
            return Response::redirect($this->registerPath($redirect));
        }

        $userId = (int) $pdo->lastInsertId();
        $userStatement = $pdo->prepare('SELECT id, name, email, auth_version, type, status FROM users WHERE id=? LIMIT 1');
        $userStatement->execute([$userId]);
        $user = $userStatement->fetch();
        if (!$user) {
            Session::flash('success', 'Conta criada. Entre para continuar.');
            return Response::redirect('/entrar' . ($redirect !== '' ? '?redirect=' . rawurlencode($redirect) : ''));
        }

        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$userId]);
        Auth::login($user);
        Session::flash('success', 'Conta criada com sucesso. Você já está conectado.');
        return Response::redirect($redirect !== '' ? $redirect : '/minha-conta');
    }

    private function registerPath(string $redirect): string
    {
        return $redirect === '' ? '/cadastro' : '/cadastro?redirect=' . rawurlencode($redirect);
    }

    private function safeRedirect(string $path): string
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) return '';
        if (str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) return '';

        $pathname = (string) (parse_url($path, PHP_URL_PATH) ?? '');
        if (in_array($pathname, ['/entrar', '/cadastro', '/sair'], true)) return '';

        return $path;
    }
}
