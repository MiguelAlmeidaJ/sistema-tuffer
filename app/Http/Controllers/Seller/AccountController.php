<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

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

final class AccountController extends Controller
{
    public function edit(): string
    {
        $statement = Database::connection()->prepare('SELECT id,name,email,phone FROM users WHERE id=? LIMIT 1');
        $statement->execute([Auth::id()]);
        return $this->page('seller/account/profile', 'layouts/seller', ['pageTitle'=>'Meu perfil', 'user'=>$statement->fetch()]);
    }

    public function update(): string
    {
        $name=trim((string)($_POST['name']??''));
        $phone=preg_replace('/\D+/', '', (string)($_POST['phone']??''))??'';
        if(mb_strlen($name)<3 || mb_strlen($name)>150 || ($phone!=='' && !in_array(strlen($phone),[10,11],true))) {
            Session::flash('error','Confira seu nome e telefone com DDD.');
            return Response::redirect('/vendedor/minha-conta');
        }
        Database::connection()->prepare('UPDATE users SET name=?,phone=? WHERE id=?')->execute([$name,$phone?:null,Auth::id()]);
        Session::flash('success','Seu perfil foi atualizado.');
        return Response::redirect('/vendedor/minha-conta');
    }

    public function password(): string
    {
        return $this->page('seller/account/password', 'layouts/seller', ['pageTitle'=>'Alterar minha senha']);
    }

    public function changePassword(): string
    {
        $current=(string)($_POST['current_password']??'');
        $new=(string)($_POST['password']??'');
        $confirmation=(string)($_POST['password_confirmation']??'');
        $pdo=Database::connection();
        $statement=$pdo->prepare("SELECT id,name,email,password_hash FROM users WHERE id=? AND status='active' LIMIT 1");
        $statement->execute([Auth::id()]);
        $user=$statement->fetch();
        if(!$user) return Response::redirect('/entrar');
        if(!password_verify($current,(string)$user['password_hash'])) {
            Session::flash('error','Senha atual incorreta.');
            return Response::redirect('/vendedor/minha-conta/senha');
        }
        if(($error=PasswordPolicy::error($new))!==null || $new!==$confirmation) {
            Session::flash('error',$error??'As novas senhas não coincidem.');
            return Response::redirect('/vendedor/minha-conta/senha');
        }
        foreach(array_merge([(string)$user['password_hash']],(new UserRepository())->recentPasswordHashes((int)$user['id'])) as $hash) {
            if(password_verify($new,$hash)) {
                Session::flash('error','Escolha uma senha ainda não utilizada nesta conta.');
                return Response::redirect('/vendedor/minha-conta/senha');
            }
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO user_password_history(user_id,password_hash) VALUES(?,?)')->execute([$user['id'],$user['password_hash']]);
            $pdo->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$user['id']]);
            $pdo->prepare('INSERT INTO password_change_audits(user_id,ip_address,user_agent) VALUES(?,?,?)')->execute([$user['id'],(string)($_SERVER['REMOTE_ADDR']??''),mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);
            $pdo->commit();
        } catch(Throwable $exception) {
            if($pdo->inTransaction()) $pdo->rollBack();
            Logger::exception($exception,['user_id'=>$user['id']],'seller_password_change');
            Session::flash('error','Não foi possível alterar a senha. Tente novamente.');
            return Response::redirect('/vendedor/minha-conta/senha');
        }
        try {
            (new QueuedMailService())->enqueue((string)$user['name'],(string)$user['email'],'Sua senha Tuffer foi alterada','Sua senha foi alterada. Se não foi você, entre em contato com o suporte.','password_changed','user',(int)$user['id'],'seller-password-changed:'.$user['id'].':'.time());
        } catch(Throwable $exception) {
            Logger::exception($exception,['user_id'=>$user['id']],'security');
        }
        Auth::logout();
        Session::flash('success','Senha alterada. Entre novamente com sua nova senha.');
        return Response::redirect('/entrar');
    }
}
