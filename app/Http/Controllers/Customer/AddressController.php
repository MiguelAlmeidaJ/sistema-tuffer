<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use Throwable;

final class AddressController extends Controller
{
    public function index(): string
    {
        $statement=Database::connection()->prepare('SELECT * FROM user_addresses WHERE user_id=? ORDER BY is_default DESC,id DESC');
        $statement->execute([Auth::id()]);
        return $this->page('customer/addresses/index','layouts/customer',['pageTitle'=>'Meus endereços','addresses'=>$statement->fetchAll()]);
    }

    public function create(): string
    {
        return $this->form(null,$this->safeReturn((string)($_GET['return']??'')));
    }

    public function store(): string
    {
        if(!$this->save())return Response::redirect('/minha-conta/enderecos?novo=1'.($this->safeReturn((string)($_POST['return']??''))==='/checkout'?'&return=/checkout':''));
        Session::flash('success','Endereço cadastrado com sucesso.');
        return Response::redirect($this->safeReturn((string)($_POST['return']??''))?:'/minha-conta/enderecos');
    }

    public function edit(string $id): string
    {
        $statement=Database::connection()->prepare('SELECT * FROM user_addresses WHERE id=? AND user_id=?');
        $statement->execute([(int)$id,Auth::id()]);
        $address=$statement->fetch();
        if(!$address)return Response::redirect('/minha-conta/enderecos');
        return $this->form($address,$this->safeReturn((string)($_GET['return']??'')));
    }

    public function update(string $id): string
    {
        $statement=Database::connection()->prepare('SELECT id FROM user_addresses WHERE id=? AND user_id=?');
        $statement->execute([(int)$id,Auth::id()]);
        if(!$statement->fetchColumn())return Response::redirect('/minha-conta/enderecos');
        if(!$this->save((int)$id))return Response::redirect('/minha-conta/enderecos/'.(int)$id.'/editar');
        Session::flash('success','Endereço atualizado.');
        return Response::redirect($this->safeReturn((string)($_POST['return']??''))?:'/minha-conta/enderecos');
    }

    public function destroy(string $id): string
    {
        $pdo=Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM user_addresses WHERE id=? AND user_id=?')->execute([(int)$id,Auth::id()]);
            $statement=$pdo->prepare('SELECT id FROM user_addresses WHERE user_id=? AND is_default=1 LIMIT 1');
            $statement->execute([Auth::id()]);
            if(!$statement->fetchColumn()){
                $pdo->prepare('UPDATE user_addresses SET is_default=1 WHERE user_id=? ORDER BY id DESC LIMIT 1')->execute([Auth::id()]);
            }
            $pdo->commit();
            Session::flash('success','Endereço removido.');
        }catch(Throwable $exception){
            if($pdo->inTransaction())$pdo->rollBack();
            Logger::exception($exception,['user_id'=>Auth::id()],'customer_address_delete');
            Session::flash('error','Não foi possível excluir o endereço agora.');
        }
        return Response::redirect('/minha-conta/enderecos');
    }

    private function save(?int $id=null): bool
    {
        $name=trim((string)($_POST['recipient_name']??''));
        $label=trim((string)($_POST['label']??''));
        $cep=preg_replace('/\D+/','',(string)($_POST['postal_code']??''))??'';
        $street=trim((string)($_POST['street']??''));
        $number=trim((string)($_POST['number']??''));
        $complement=trim((string)($_POST['complement']??''));
        $neighborhood=trim((string)($_POST['neighborhood']??''));
        $city=trim((string)($_POST['city']??''));
        $state=mb_strtoupper(trim((string)($_POST['state']??'')));
        $ibge=preg_replace('/\D+/','',(string)($_POST['city_ibge_code']??''))??'';
        $ibge=preg_match('/^\d{7}$/',$ibge)?$ibge:null;
        if(mb_strlen($name)<3||mb_strlen($name)>150||!preg_match('/^\d{8}$/',$cep)||$street===''||$number===''||$neighborhood===''||$city===''||!preg_match('/^[A-Z]{2}$/',$state)||mb_strlen($label)>80||mb_strlen($street)>180||mb_strlen($number)>30||mb_strlen($complement)>100||mb_strlen($neighborhood)>100||mb_strlen($city)>100){
            Session::flash('error','Confira o destinatário, CEP, rua, número, bairro, cidade e UF.');
            return false;
        }
        $pdo=Database::connection();
        try {
            $pdo->beginTransaction();
            $countStatement=$pdo->prepare('SELECT COUNT(*) FROM user_addresses WHERE user_id=?');
            $countStatement->execute([Auth::id()]);
            $count=(int)$countStatement->fetchColumn();
            // O primeiro endereço sempre será principal.
            $default=!empty($_POST['is_default'])||$count===0?1:0;
            if($default)$pdo->prepare('UPDATE user_addresses SET is_default=0 WHERE user_id=?')->execute([Auth::id()]);
            $values=[$label,$name,$cep,$street,$number,$complement,$neighborhood,$city,$ibge,$state,$default];
            if($id!==null){
                $values[]=$id;$values[]=Auth::id();
                $pdo->prepare('UPDATE user_addresses SET label=?,recipient_name=?,postal_code=?,street=?,number=?,complement=?,neighborhood=?,city=?,city_ibge_code=?,state=?,is_default=? WHERE id=? AND user_id=?')->execute($values);
            }else{
                array_unshift($values,Auth::id());
                $pdo->prepare('INSERT INTO user_addresses(user_id,label,recipient_name,postal_code,street,number,complement,neighborhood,city,city_ibge_code,state,is_default) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute($values);
            }
            // Nunca deixe uma conta com endereços e nenhum endereço principal.
            $defaultCheck=$pdo->prepare('SELECT id FROM user_addresses WHERE user_id=? AND is_default=1 LIMIT 1');
            $defaultCheck->execute([Auth::id()]);
            if(!$defaultCheck->fetchColumn()){
                $pdo->prepare('UPDATE user_addresses SET is_default=1 WHERE user_id=? ORDER BY id DESC LIMIT 1')->execute([Auth::id()]);
            }
            $pdo->commit();
            return true;
        }catch(Throwable $exception){
            if($pdo->inTransaction())$pdo->rollBack();
            Logger::exception($exception,['user_id'=>Auth::id()],'customer_address_save');
            Session::flash('error','Não foi possível salvar o endereço. Confira os dados e tente novamente.');
            return false;
        }
    }

    private function form(?array $address=null,string $return=''): string
    {
        return $this->page('customer/addresses/form','layouts/customer',['pageTitle'=>$address?'Editar endereço':'Novo endereço','address'=>$address,'returnPath'=>$return]);
    }

    private function safeReturn(string $path): string
    {
        return $path==='/checkout'?'/checkout':'';
    }
}
