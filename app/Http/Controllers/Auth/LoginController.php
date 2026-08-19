<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Core\Logger;
use App\Http\Controllers\Controller;
use App\Services\Mail\QueuedMailService;
use App\Services\Settings\PlatformSettings;
use App\Services\Auth\LoginThrottle;
use Throwable;

final class LoginController extends Controller
{
    public function create(): string
    {
        return $this->page('auth/login', 'layouts/auth', ['pageTitle' => 'Entrar', 'minimalAuthLayout' => true]);
    }

    public function store(): string
    {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $throttle = new LoginThrottle();
        if ($throttle->blocked($email, $ip)) {
            Session::flash('errors', ['email' => 'Muitas tentativas de acesso. Aguarde 15 minutos e tente novamente.']);
            Session::flash('old', ['email' => $email]);
            return Response::redirect('/entrar');
        }
        $statement = Database::connection()->prepare('SELECT id, name, email, password_hash, auth_version, type, status FROM users WHERE email = ? LIMIT 1');
        $statement->execute([$email]);
        $user = $statement->fetch();

        if (!$user || !password_verify((string) ($_POST['password'] ?? ''), (string) $user['password_hash'])) {
            $throttle->recordFailure($email, $ip);
            Session::flash('errors', ['email' => 'E-mail ou senha incorretos.']);
            Session::flash('old', ['email' => $email]);
            return Response::redirect('/entrar');
        }
        if ($user['status'] !== 'active') {
            $throttle->recordFailure($email, $ip);
            Session::flash('errors', ['email' => 'Esta conta ainda não está ativa.']);
            Session::flash('old', ['email' => $email]);
            return Response::redirect('/entrar');
        }

        $throttle->clear($email, $ip);
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Database::connection()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([
                password_hash((string) ($_POST['password'] ?? ''), PASSWORD_DEFAULT),
                $user['id'],
            ]);
        }
        Auth::login($user);
        if (isset($_POST['remember'])) {
            Session::rememberFor(60 * 60 * 24 * 30);
        }
        Database::connection()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
        if ($user['type'] === 'admin') {
            Session::put('admin_last_activity', time());
            if ((string) (PlatformSettings::all()['admin_login_alerts'] ?? '0') === '1') {
                try {
                    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'não identificado');
                    (new QueuedMailService())->enqueue((string) $user['name'], (string) $user['email'], 'Novo acesso administrativo na Tuffer', "Um novo acesso ao painel administrativo foi realizado.\n\nData: " . date('d/m/Y H:i:s') . "\nIP: {$ip}\n\nSe não foi você, altere sua senha e contate o suporte.", 'admin_login_alert', 'user', (int) $user['id'], 'admin-login:' . $user['id'] . ':' . hash('sha256', session_id() . '|' . time()));
                } catch (Throwable $exception) {
                    Logger::exception($exception, ['user_id' => $user['id']], 'security');
                }
            }
        }

        return Response::redirect(match ($user['type']) {
            'admin' => '/admin',
            'seller', 'operator' => '/vendedor',
            default => '/minha-conta',
        });
    }

    public function destroy(): string
    {
        Auth::logout();
        Session::flash('success', 'Você saiu com segurança.');
        return Response::redirect('/');
    }
}
