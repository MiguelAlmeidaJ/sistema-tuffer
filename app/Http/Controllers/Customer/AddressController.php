<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;

final class AddressController extends Controller
{
    public function index(): string { $s=Database::connection()->prepare('SELECT * FROM user_addresses WHERE user_id=? ORDER BY is_default DESC,id DESC');$s->execute([Auth::id()]);return $this->page('customer/addresses/index','layouts/customer',['pageTitle'=>'Meus endereços','addresses'=>$s->fetchAll()]); }
    public function create(): string { return $this->form(null, $this->safeReturn((string)($_GET['return']??''))); }
    public function store(): string { $this->save();Session::flash('success','Endereço adicionado.');return Response::redirect($this->safeReturn((string)($_POST['return']??''))?:'/minha-conta/enderecos'); }
    public function edit(string $id): string { $s=Database::connection()->prepare('SELECT * FROM user_addresses WHERE id=? AND user_id=?');$s->execute([(int)$id,Auth::id()]);return $this->form($s->fetch()?:null,$this->safeReturn((string)($_GET['return']??''))); }
    public function update(string $id): string { $this->save((int)$id);Session::flash('success','Endereço atualizado.');return Response::redirect($this->safeReturn((string)($_POST['return']??''))?:'/minha-conta/enderecos'); }
    public function destroy(string $id): string { Database::connection()->prepare('DELETE FROM user_addresses WHERE id=? AND user_id=?')->execute([(int)$id,Auth::id()]);Session::flash('success','Endereço removido.');return Response::redirect('/minha-conta/enderecos'); }

    private function save(?int $id=null): void
    {
        $pdo=Database::connection();$default=!empty($_POST['is_default'])?1:0;if($default)$pdo->prepare('UPDATE user_addresses SET is_default=0 WHERE user_id=?')->execute([Auth::id()]);$values=[trim((string)($_POST['label']??'')),trim((string)$_POST['recipient_name']),trim((string)$_POST['postal_code']),trim((string)$_POST['street']),trim((string)$_POST['number']),trim((string)($_POST['complement']??'')),trim((string)$_POST['neighborhood']),trim((string)$_POST['city']),mb_strtoupper(trim((string)$_POST['state'])),$default];if($id){$values[]=$id;$values[]=Auth::id();$pdo->prepare('UPDATE user_addresses SET label=?,recipient_name=?,postal_code=?,street=?,number=?,complement=?,neighborhood=?,city=?,state=?,is_default=? WHERE id=? AND user_id=?')->execute($values);}else{array_unshift($values,Auth::id());$pdo->prepare('INSERT INTO user_addresses(user_id,label,recipient_name,postal_code,street,number,complement,neighborhood,city,state,is_default) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute($values);}
    }

    private function form(?array $address=null, string $return=''): string { return $this->page('customer/addresses/form','layouts/customer',['pageTitle'=>$address?'Editar endereço':'Novo endereço','address'=>$address,'returnPath'=>$return]); }
    private function safeReturn(string $path): string
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) return '';
        if (str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) return '';
        return $path;
    }
}
