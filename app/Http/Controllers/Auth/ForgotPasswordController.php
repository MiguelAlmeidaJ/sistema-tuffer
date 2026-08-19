<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\ResetCodeService;
use App\Services\Mail\QueuedMailService;

final class ForgotPasswordController extends Controller
{
    public function create(): string
    {
        return $this->page('auth/forgot-password', 'layouts/auth', ['pageTitle' => 'Recuperar senha']);
    }

    public function store(): string
    {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? Session::get('password_reset_email', ''))));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('errors', ['email' => 'Informe um endereço de e-mail válido.']);
            Session::flash('old', ['email' => $email]);
            return Response::redirect('/esqueci-minha-senha');
        }

        $users = new UserRepository();
        $resets = new PasswordResetRepository();
        $user = $users->findByEmail($email);
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if ($user !== null) {
            $seconds = $resets->secondsSinceLatestRequest($email);
            $dailyRequests = $resets->countRequestsToday($email, $ip);

            if (($seconds === null || $seconds >= 60) && $dailyRequests < 10) {
                $resets->invalidateOpenCodes((int) $user['id']);
                $codes = new ResetCodeService();
                $code = $codes->generate();
                $resets->create([
                    'user_id' => (int) $user['id'],
                    'email' => (string) $user['email'],
                    'code_hash' => $codes->hash($code),
                    'request_ip' => $ip,
                    'resend_count' => min(255, $dailyRequests),
                ]);
                (new QueuedMailService())->enqueue(
                    (string) $user['name'],
                    (string) $user['email'],
                    'Seu código de recuperação Tuffer',
                    "Olá, {$user['name']}.\n\nSeu código para redefinir a senha é: {$code}\n\nEle expira em 15 minutos. Se você não fez esta solicitação, ignore este e-mail.",
                    'password_reset_code',
                    'password_reset',
                    null,
                    'password-reset:' . hash('sha256', (string) $user['id'] . '|' . $code),
                );
            }
        }

        Session::put('password_reset_email', $email);
        Session::flash('success', 'Se o e-mail estiver cadastrado, enviaremos um código com as orientações.');

        return Response::redirect('/redefinir-senha/codigo');
    }
}
