<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Support\Str;
use Throwable;

final class StoreController extends Controller
{
    public function index(): string
    {
        $stores=Database::connection()->query('SELECT st.*,s.trade_name seller_name,(SELECT COUNT(*) FROM products p WHERE p.store_id=st.id) products FROM stores st JOIN sellers s ON s.id=st.seller_id ORDER BY st.created_at DESC')->fetchAll();
        return $this->page('admin/stores/index','layouts/admin',['pageTitle'=>'Lojas','stores'=>$stores]);
    }

    public function create(): string { return $this->form(); }

    public function store(): string
    {
        $name=trim((string)($_POST['name']??'')); $sellerId=(int)($_POST['seller_id']??0);
        if($name===''||$sellerId===0){Session::flash('error','Informe nome e vendedor.');return Response::redirect('/admin/lojas/nova');}
        $status=in_array($_POST['status']??'', ['active','draft','inactive'], true)?(string)$_POST['status']:'draft';
        if($status==='active'&&!$this->sellerCanActivate($sellerId)){Session::flash('error','O vendedor precisa concluir a configuração de recebimento antes de ativar uma loja.');return Response::redirect('/admin/lojas/nova');}
        $slug=Str::slug((string)($_POST['slug']?:$name));
        try{Database::connection()->prepare("INSERT INTO stores(seller_id,name,slug,description,logo_url,banner_url,status) VALUES(?,?,?,?,?,?,?)")->execute([$sellerId,$name,$slug,trim((string)($_POST['description']??'')),trim((string)($_POST['logo_url']??''))?:null,trim((string)($_POST['banner_url']??''))?:null,$status]);Session::flash('success','Loja criada.');}catch(Throwable){Session::flash('error','Não foi possível criar. Verifique o slug.');}
        return Response::redirect('/admin/lojas');
    }

    public function edit(string $id): string
    {
        $statement=Database::connection()->prepare('SELECT * FROM stores WHERE id=?');$statement->execute([(int)$id]);
        return $this->form($statement->fetch()?:null);
    }

    public function update(string $id): string
    {
        $pdo=Database::connection();$storeId=(int)$id;$sellerId=(int)($_POST['seller_id']??0);
        $sourceIds=array_map('intval',[(int)($_POST['shipping_source_store_id']??0)]);
        foreach($sourceIds as $sourceId){if($sourceId){$check=$pdo->prepare('SELECT COUNT(*) FROM stores WHERE id=? AND seller_id=?');$check->execute([$sourceId,$sellerId]);if(!$check->fetchColumn()){Session::flash('error','Configurações compartilhadas devem vir de uma loja do mesmo vendedor.');return Response::redirect("/admin/lojas/{$storeId}/editar");}}}
        $status=(string)($_POST['status']??'active');
        if($status==='active'&&!$this->sellerCanActivate($sellerId)){Session::flash('error','O vendedor precisa concluir a configuração de recebimento antes de ativar a loja.');return Response::redirect("/admin/lojas/{$storeId}/editar");}
        try{$pdo->prepare('UPDATE stores SET seller_id=?,name=?,slug=?,description=?,logo_url=?,banner_url=?,status=?,shipping_source_store_id=? WHERE id=?')->execute([$sellerId,trim((string)$_POST['name']),Str::slug((string)$_POST['slug']),trim((string)($_POST['description']??'')),trim((string)($_POST['logo_url']??''))?:null,trim((string)($_POST['banner_url']??''))?:null,$status,$sourceIds[0]?:null,$storeId]);Session::flash('success','Loja atualizada.');}catch(Throwable){Session::flash('error','Não foi possível atualizar a loja.');}
        return Response::redirect('/admin/lojas');
    }

    public function deactivate(string $id): string { Database::connection()->prepare("UPDATE stores SET status='inactive' WHERE id=?")->execute([(int)$id]);Session::flash('success','Loja inativada.');return Response::redirect('/admin/lojas'); }

    public function destroy(string $id): string
    {
        try{Database::connection()->prepare('DELETE FROM stores WHERE id=?')->execute([(int)$id]);Session::flash('success','Loja excluída.');}catch(Throwable){Session::flash('error','A loja possui dados vinculados e não pode ser excluída; inative-a.');}
        return Response::redirect('/admin/lojas');
    }

    /** @param array<string,mixed>|null $store */
    private function form(?array $store=null): string
    {
        $pdo=Database::connection();$sellers=$pdo->query('SELECT id,trade_name FROM sellers ORDER BY trade_name')->fetchAll();$sellerStores=$pdo->query('SELECT id,seller_id,name FROM stores ORDER BY name')->fetchAll();
        return $this->page('admin/stores/form','layouts/admin',['pageTitle'=>$store?'Editar loja':'Criar loja','store'=>$store,'sellers'=>$sellers,'sellerStores'=>$sellerStores]);
    }

    private function sellerCanActivate(int $sellerId): bool
    {
        return (new \App\Services\Sellers\SellerSalesEligibility())->sellerCanSell($sellerId);
    }
}
