<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Mail\QueuedMailService;
use Throwable;

final class EmailVerificationController extends Controller
{
    public function show(): string
    {
        if ($this->verified()) return Response::redirect('/minha-conta/atacado');
        return $this->page('customer/profile/verify-email', 'layouts/customer', ['pageTitle' => 'Confirmar e-mail']);
    }

    public function send(): string
    {
        if ($this->verified()) return Response::redirect('/minha-conta/atacado');
        $pdo = Database::connection();
        $recent = $pdo->prepare('SELECT COUNT(*) FROM user_email_verifications WHERE user_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 60 SECOND)');
        $recent->execute([Auth::id()]);
        if ((int) $recent->fetchColumn() > 0) { Session::flash('error', 'Aguarde um minuto antes de solicitar outro código.'); return Response::redirect('/minha-conta/verificar-email'); }
        $code = (string) random_int(100000, 999999);
        $pdo->prepare('UPDATE user_email_verifications SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([Auth::id()]);
        $pdo->prepare('INSERT INTO user_email_verifications(user_id,code_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE))')->execute([Auth::id(), password_hash($code, PASSWORD_DEFAULT)]);
        $user = $pdo->prepare('SELECT name,email FROM users WHERE id=?'); $user->execute([Auth::id()]); $row = $user->fetch();
        try {
            $sent = $row && (new QueuedMailService())->enqueue((string) $row['name'], (string) $row['email'], 'Confirme seu e-mail Tuffer', "Seu código de confirmação é: {$code}\n\nEle expira em 15 minutos.", 'email_verification', 'user', (int) Auth::id(), 'email-verification:' . Auth::id() . ':' . hash('sha256', $code)) > 0;
        } catch (Throwable) {
            $sent = false;
        }
        Session::flash($sent ? 'success' : 'error', $sent ? 'Enviamos um código para o seu e-mail.' : 'Não foi possível enviar o e-mail. Verifique a configuração de envio.');
        return Response::redirect('/minha-conta/verificar-email');
    }

    public function verify(): string
    {
        $code = preg_replace('/\D+/', '', (string) ($_POST['code'] ?? '')) ?? '';
        $statement = Database::connection()->prepare('SELECT * FROM user_email_verifications WHERE user_id=? AND used_at IS NULL AND expires_at>NOW() AND attempts<5 ORDER BY id DESC LIMIT 1');
        $statement->execute([Auth::id()]); $verification = $statement->fetch();
        if (!$verification || strlen($code) !== 6 || !password_verify($code, (string) $verification['code_hash'])) {
            if ($verification) Database::connection()->prepare('UPDATE user_email_verifications SET attempts=attempts+1 WHERE id=?')->execute([$verification['id']]);
            Session::flash('error', 'Código inválido ou expirado.'); return Response::redirect('/minha-conta/verificar-email');
        }
        $pdo = Database::connection(); $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET email_verified_at=NOW() WHERE id=?')->execute([Auth::id()]);
        $pdo->prepare('UPDATE user_email_verifications SET used_at=NOW() WHERE id=?')->execute([$verification['id']]);
        $pdo->commit(); Session::flash('success', 'E-mail confirmado. Você já pode solicitar acesso ao atacado.');
        return Response::redirect('/minha-conta/atacado');
    }

    private function verified(): bool { $statement=Database::connection()->prepare('SELECT email_verified_at IS NOT NULL FROM users WHERE id=?');$statement->execute([Auth::id()]);return (bool)$statement->fetchColumn(); }
}
