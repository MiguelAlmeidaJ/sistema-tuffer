<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use Throwable;
use App\Services\Auth\PasswordPolicy;
use App\Services\Wholesale\CnpjValidator;

final class SellerRegisterController extends Controller
{
    public function create(): string
    {
        return $this->page('auth/seller-register', 'layouts/auth', ['pageTitle' => 'Quero vender']);
    }

    public function store(): string
    {
        $data = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'email' => mb_strtolower(trim((string) ($_POST['email'] ?? ''))),
            'password' => (string) ($_POST['password'] ?? ''),
            'trade_name' => trim((string) ($_POST['trade_name'] ?? '')),
            'document' => preg_replace('/\D+/', '', (string) ($_POST['document'] ?? '')) ?? '',
        ];
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        $errors = [];

        if (mb_strlen($data['name']) < 3) $errors['name'] = 'Informe seu nome completo.';
        if ($data['trade_name'] === '') $errors['trade_name'] = 'Informe o nome da loja.';
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Informe um endereço de e-mail válido.';
        if (!(new CnpjValidator())->isValid($data['document'])) $errors['document'] = 'Informe um CNPJ válido.';
        if (($policyError = PasswordPolicy::error($data['password'])) !== null) $errors['password'] = $policyError;
        if ($data['password'] !== $confirmation) $errors['password_confirmation'] = 'As senhas não coincidem.';
        if (!isset($_POST['terms'])) $errors['terms'] = 'Você precisa aceitar os termos para continuar.';

        if ($errors !== []) {
            Session::flash('errors', $errors);
            $personalDataHasErrors = isset($errors['name']) || isset($errors['trade_name']) || isset($errors['email']) || isset($errors['document']);
            Session::flash('old', array_merge($data, ['password' => '', 'step' => $personalDataHasErrors ? 1 : 2]));
            return Response::redirect('/quero-vender');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO users (name, email, document, password_hash, type, status) VALUES (?, ?, ?, ?, 'seller', 'active')")
                ->execute([$data['name'], $data['email'], $data['document'], password_hash($data['password'], PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO sellers (user_id, legal_name, trade_name, document, status) VALUES (?, ?, ?, ?, 'pending')")
                ->execute([$userId, $data['trade_name'], $data['trade_name'], $data['document']]);
            $sellerId = (int) $pdo->lastInsertId();
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', mb_strtolower($data['trade_name'])) ?: '') ?? '', '-') . '-' . $sellerId;
            $pdo->prepare("INSERT INTO stores (seller_id, name, slug, status) VALUES (?, ?, ?, 'draft')")
                ->execute([$sellerId, $data['trade_name'], $slug]);
            $pdo->commit();
        } catch (Throwable) {
            $pdo->rollBack();
            Session::flash('error', 'Não foi possível concluir. Verifique se e-mail ou documento já estão cadastrados.');
            Session::flash('old', array_merge($data, ['password' => '', 'step' => 1]));
            return Response::redirect('/quero-vender');
        }

        Session::flash('success', 'Cadastro recebido. Entre para acompanhar a análise.');
        return Response::redirect('/entrar');
    }
}
