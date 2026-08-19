<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Auth\SocialAuthService;
use PDO;
use RuntimeException;
use Throwable;

final class SocialAuthController extends Controller
{
    public function redirect(): string
    {
        $provider = 'google';
        try {
            $url = (new SocialAuthService())->authorizationUrl($provider);
            return Response::redirectExternal($url, ['accounts.google.com']);
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect('/entrar');
        }
    }

    public function callback(): string
    {
        $provider = 'google';
        $service = new SocialAuthService();
        try {
            if (isset($_GET['error'])) throw new RuntimeException('Login social cancelado. Você pode tentar novamente quando quiser.');
            if (!$service->consumeState($provider, (string) ($_GET['state'] ?? ''))) {
                throw new RuntimeException('A tentativa de login expirou ou não pôde ser validada. Tente novamente.');
            }

            $profile = $service->profile($provider, (string) ($_GET['code'] ?? ''));
            $user = $this->findOrCreateCustomer($provider, $profile);
            if (($user['status'] ?? '') !== 'active') throw new RuntimeException('Esta conta não está ativa. Entre em contato com o suporte.');

            Auth::login($user);
            Database::connection()->prepare('UPDATE users SET last_login_at=NOW(),email_verified_at=COALESCE(email_verified_at,NOW()) WHERE id=?')->execute([$user['id']]);
            Session::flash('success', 'Login realizado com ' . $service->label($provider) . '.');
            return Response::redirect('/minha-conta');
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect('/entrar');
        } catch (Throwable $exception) {
            Logger::exception($exception, ['provider' => $provider], 'security');
            Session::flash('error', 'Não foi possível concluir o login social agora. Tente novamente.');
            return Response::redirect('/entrar');
        }
    }

    /** @param array{id:string,name:string,email:string,email_verified:bool,avatar:?string} $profile @return array<string,mixed> */
    private function findOrCreateCustomer(string $provider, array $profile): array
    {
        if (!$profile['email_verified']) throw new RuntimeException('Use uma conta social com e-mail verificado.');
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $identity = $pdo->prepare('SELECT u.id,u.name,u.email,u.auth_version,u.type,u.status FROM social_identities si JOIN users u ON u.id=si.user_id WHERE si.provider=? AND si.provider_user_id=? LIMIT 1 FOR UPDATE');
            $identity->execute([$provider, $profile['id']]);
            $user = $identity->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $byEmail = $pdo->prepare('SELECT id,name,email,auth_version,type,status FROM users WHERE email=? LIMIT 1 FOR UPDATE');
                $byEmail->execute([$profile['email']]);
                $user = $byEmail->fetch(PDO::FETCH_ASSOC);
                if ($user && $user['type'] !== 'customer') {
                    throw new RuntimeException('Contas administrativas e de vendedores devem entrar com e-mail e senha.');
                }
                if (!$user) {
                    $password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users(name,email,password_hash,type,status,email_verified_at) VALUES(?,?,?,'customer','active',NOW())")->execute([$profile['name'], $profile['email'], $password]);
                    $userId = (int) $pdo->lastInsertId();
                    $user = ['id' => $userId, 'name' => $profile['name'], 'email' => $profile['email'], 'auth_version' => 1, 'type' => 'customer', 'status' => 'active'];
                }

                $pdo->prepare('INSERT INTO social_identities(user_id,provider,provider_user_id,email,profile_photo_url) VALUES(?,?,?,?,?)')->execute([(int) $user['id'], $provider, $profile['id'], $profile['email'], $profile['avatar']]);
            }

            $pdo->commit();
            return $user;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}
