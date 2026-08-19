<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use PDOException;
use App\Services\Auth\PasswordPolicy;

final class RegisterController extends Controller
{
    public function create(): string
    {
        return $this->page('auth/register', 'layouts/auth', ['pageTitle' => 'Criar conta']);
    }

    public function store(): string
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        $errors = [];

        if (mb_strlen($name) < 3) $errors['name'] = 'Informe seu nome completo.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Informe um endereço de e-mail válido.';
        if (($policyError = PasswordPolicy::error($password)) !== null) $errors['password'] = $policyError;
        if ($password !== $confirmation) $errors['password_confirmation'] = 'As senhas não coincidem.';
        if (!isset($_POST['terms'])) $errors['terms'] = 'Você precisa aceitar os termos para continuar.';

        if ($errors !== []) {
            Session::flash('errors', $errors);
            $personalDataHasErrors = isset($errors['name']) || isset($errors['email']);
            Session::flash('old', ['name' => $name, 'email' => $email, 'step' => $personalDataHasErrors ? 1 : 2]);
            return Response::redirect('/cadastro');
        }

        try {
            $statement = Database::connection()->prepare("INSERT INTO users (name, email, password_hash, type, status) VALUES (?, ?, ?, 'customer', 'active')");
            $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException) {
            Session::flash('errors', ['email' => 'Este e-mail já possui cadastro.']);
            Session::flash('old', ['name' => $name, 'email' => $email, 'step' => 1]);
            return Response::redirect('/cadastro');
        }

        Session::flash('success', 'Conta criada. Agora você já pode entrar.');
        return Response::redirect('/entrar');
    }
}
