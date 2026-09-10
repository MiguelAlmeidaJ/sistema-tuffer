<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Fiscal\TinyFiscalConnectorService;
use App\Services\Stores\SellerStoreContext;
use RuntimeException;
use Throwable;

final class TinyFiscalConnectorController extends Controller
{
    public function index(): string
    {
        [$store,$seller]=$this->context();if(!$store||!$seller)return Response::redirect('/vendedor');
        $pdo=Database::connection();$service=new TinyFiscalConnectorService($pdo);
        $stmt=$pdo->prepare('SELECT enabled,issuance_mode,provider,legal_name,document FROM store_fiscal_profiles WHERE store_id=? AND seller_id=? LIMIT 1');$stmt->execute([$store['id'],$seller['id']]);$profile=$stmt->fetch()?:null;
        $stmt=$pdo->prepare("SELECT r.*,so.code seller_order_code,fd.status document_status,fd.number,fd.series,fd.access_key FROM fiscal_connector_runs r JOIN seller_orders so ON so.id=r.seller_order_id JOIN fiscal_documents fd ON fd.id=r.fiscal_document_id WHERE r.store_id=? AND r.seller_id=? AND r.provider='tiny' ORDER BY r.id DESC LIMIT 50");$stmt->execute([$store['id'],$seller['id']]);
        return $this->page('seller/fiscal/tiny','layouts/seller',[
            'pageTitle'=>'Conector Tiny','currentStore'=>$store,'stores'=>(new SellerStoreContext())->stores(),'profile'=>$profile,'connector'=>$service->configuration((int)$store['id'],(int)$seller['id']),'runs'=>$stmt->fetchAll(),
        ]);
    }

    public function connect(): string
    {
        [$store,$seller]=$this->context();if(!$store||!$seller)return Response::redirect('/vendedor');
        try{
            $token=trim((string)($_POST['token']??''));
            (new TinyFiscalConnectorService())->connect((int)$store['id'],(int)$seller['id'],$token,!empty($_POST['auto_emit']),!empty($_POST['send_email']));
            Session::flash('success','Tiny conectado a esta loja. Pedidos fiscais elegíveis foram preparados para sincronização.');
        }catch(RuntimeException $e){Session::flash('error',$e->getMessage());}catch(Throwable){Session::flash('error','Não foi possível conectar o Tiny. Revise o token e tente novamente.');}
        return Response::redirect('/vendedor/fiscal/conectores/tiny');
    }

    public function test(): string
    {
        [$store,$seller]=$this->context();if(!$store||!$seller)return Response::redirect('/vendedor');
        try{$account=(new TinyFiscalConnectorService())->test((int)$store['id'],(int)$seller['id']);$name=(string)($account['razao_social']??$account['fantasia']??'conta Tiny');Session::flash('success','Conexão com Tiny validada: '.$name.'.');}
        catch(RuntimeException $e){Session::flash('error',$e->getMessage());}catch(Throwable){Session::flash('error','Não foi possível validar a conexão com Tiny.');}
        return Response::redirect('/vendedor/fiscal/conectores/tiny');
    }

    public function disconnect(): string
    {
        [$store,$seller]=$this->context();if(!$store||!$seller)return Response::redirect('/vendedor');
        (new TinyFiscalConnectorService())->disconnect((int)$store['id'],(int)$seller['id']);Session::flash('success','Tiny desconectado. A loja voltou para o modo fiscal manual.');return Response::redirect('/vendedor/fiscal/conectores/tiny');
    }

    public function reprocess(string $id): string
    {
        [$store,$seller]=$this->context();if(!$store||!$seller)return Response::redirect('/vendedor');$runId=(int)$id;$pdo=Database::connection();
        $stmt=$pdo->prepare("SELECT seller_order_id FROM fiscal_connector_runs WHERE id=? AND store_id=? AND seller_id=? AND provider='tiny' LIMIT 1");$stmt->execute([$runId,$store['id'],$seller['id']]);$sellerOrderId=(int)$stmt->fetchColumn();
        if($sellerOrderId<1){Session::flash('error','Sincronização Tiny não encontrada nesta loja.');return Response::redirect('/vendedor/fiscal/conectores/tiny');}
        try{$pdo->prepare("UPDATE fiscal_connector_runs SET status='pending',last_error=NULL,completed_at=NULL WHERE id=? AND status<>'completed'")->execute([$runId]);(new TinyFiscalConnectorService($pdo))->enqueue($sellerOrderId);Session::flash('success','Sincronização Tiny reenfileirada.');}catch(RuntimeException $e){Session::flash('error',$e->getMessage());}
        return Response::redirect('/vendedor/fiscal/conectores/tiny');
    }

    /** @return array{0:?array,1:?array} */
    private function context(): array
    {
        $context=new SellerStoreContext();$store=$context->current();if(!$store)return [null,null];$stmt=Database::connection()->prepare('SELECT s.* FROM sellers s WHERE s.id=? AND s.user_id=? LIMIT 1');$stmt->execute([$store['seller_id'],Auth::id()]);$seller=$stmt->fetch();return [$store,is_array($seller)?$seller:null];
    }
}
