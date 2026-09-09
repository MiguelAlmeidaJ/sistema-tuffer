<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Fiscal\ExternalFiscalDocumentService;
use App\Services\Fiscal\FiscalApiTokenService;
use App\Services\Fiscal\FiscalDocumentStorage;
use App\Services\Stores\SellerStoreContext;
use RuntimeException;
use Throwable;

final class FiscalIntegrationController extends Controller
{
    public function index(): string
    {
        [$store, $seller] = $this->context();
        if (!$store || !$seller) return Response::redirect('/vendedor');
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM store_fiscal_profiles WHERE store_id=? AND seller_id=? LIMIT 1');
        $stmt->execute([$store['id'], $seller['id']]);
        $profile = $stmt->fetch() ?: null;
        $credential = (new FiscalApiTokenService($pdo))->metadata((int)$store['id'], (int)$seller['id']);
        $stmt = $pdo->prepare('SELECT fd.*,so.code seller_order_code,o.code order_code FROM fiscal_documents fd JOIN seller_orders so ON so.id=fd.seller_order_id JOIN orders o ON o.id=so.order_id WHERE fd.store_id=? AND fd.issuance_mode IN (\'manual\',\'external\') ORDER BY fd.id DESC LIMIT 100');
        $stmt->execute([$store['id']]);
        return $this->page('seller/fiscal/integration','layouts/seller',[
            'pageTitle'=>'Integração fiscal','currentStore'=>$store,'stores'=>(new SellerStoreContext())->stores(),'profile'=>$profile,'credential'=>$credential,'documents'=>$stmt->fetchAll(),'generatedToken'=>Session::pullFlash('fiscal_api_token'),
        ]);
    }

    public function rotateToken(): string
    {
        [$store, $seller] = $this->context();
        if (!$store || !$seller) return Response::redirect('/vendedor');
        $stmt=Database::connection()->prepare("SELECT enabled,issuance_mode FROM store_fiscal_profiles WHERE store_id=? AND seller_id=? LIMIT 1");$stmt->execute([$store['id'],$seller['id']]);$profile=$stmt->fetch();
        if(!is_array($profile)||!(bool)$profile['enabled']||(string)$profile['issuance_mode']!=='external'){Session::flash('error','Configure esta loja no modo Integração externa / ERP antes de gerar o token.');return Response::redirect('/vendedor/fiscal/integracao');}
        $token=(new FiscalApiTokenService())->rotate((int)$store['id'],(int)$seller['id']);
        Session::flash('fiscal_api_token',$token);Session::flash('success','Novo token fiscal gerado. Copie agora: ele não será exibido novamente.');return Response::redirect('/vendedor/fiscal/integracao');
    }

    public function revokeToken(): string
    {
        [$store,$seller]=$this->context();if(!$store||!$seller)return Response::redirect('/vendedor');
        (new FiscalApiTokenService())->revoke((int)$store['id'],(int)$seller['id']);Session::flash('success','Token da integração fiscal revogado.');return Response::redirect('/vendedor/fiscal/integracao');
    }

    public function register(string $id): string
    {
        $sellerOrderId=(int)$id;if(!$this->ownsSellerOrder($sellerOrderId)){http_response_code(404);Session::flash('error','Pedido fiscal não encontrado nesta loja.');return Response::redirect('/vendedor/fiscal/integracao');}
        try{$storage=new FiscalDocumentStorage();$xml=$storage->readUploaded($_FILES['xml']??null,'xml');$danfe=$storage->readUploaded($_FILES['danfe']??null,'danfe');(new ExternalFiscalDocumentService())->authorize($sellerOrderId,['number'=>$_POST['number']??null,'series'=>$_POST['series']??null,'access_key'=>$_POST['access_key']??null,'protocol'=>$_POST['protocol']??null,'external_reference'=>$_POST['external_reference']??null,'xml'=>$xml,'danfe'=>$danfe]);Session::flash('success','NF-e vinculada ao pedido e arquivos fiscais armazenados com segurança.');}
        catch(RuntimeException $e){Session::flash('error',$e->getMessage());}catch(Throwable){Session::flash('error','Não foi possível registrar a NF-e.');}
        return Response::redirect('/vendedor/fiscal/integracao#documentos');
    }

    public function uploadFiles(string $id): string
    {
        $sellerOrderId=(int)$id;if(!$this->ownsSellerOrder($sellerOrderId)){http_response_code(404);Session::flash('error','Pedido fiscal não encontrado nesta loja.');return Response::redirect('/vendedor/fiscal/integracao');}
        try{$storage=new FiscalDocumentStorage();$xml=$storage->readUploaded($_FILES['xml']??null,'xml');$danfe=$storage->readUploaded($_FILES['danfe']??null,'danfe');(new ExternalFiscalDocumentService())->attach($sellerOrderId,$xml,$danfe);Session::flash('success','Arquivos fiscais atualizados.');}
        catch(RuntimeException $e){Session::flash('error',$e->getMessage());}catch(Throwable){Session::flash('error','Não foi possível armazenar os arquivos fiscais.');}
        return Response::redirect('/vendedor/fiscal/integracao#documentos');
    }

    public function cancel(string $id): string
    {
        $sellerOrderId=(int)$id;if(!$this->ownsSellerOrder($sellerOrderId)){http_response_code(404);Session::flash('error','Pedido fiscal não encontrado nesta loja.');return Response::redirect('/vendedor/fiscal/integracao');}
        try{(new ExternalFiscalDocumentService())->cancel($sellerOrderId,['reason'=>$_POST['reason']??null,'cancellation_protocol'=>$_POST['cancellation_protocol']??null,'external_reference'=>$_POST['external_reference']??null]);Session::flash('success','Cancelamento fiscal registrado.');}
        catch(RuntimeException $e){Session::flash('error',$e->getMessage());}catch(Throwable){Session::flash('error','Não foi possível registrar o cancelamento.');}
        return Response::redirect('/vendedor/fiscal/integracao#documentos');
    }

    /** @return array{0:?array,1:?array} */
    private function context(): array
    {
        $context=new SellerStoreContext();$store=$context->current();if(!$store)return [null,null];
        $stmt=Database::connection()->prepare('SELECT s.* FROM sellers s WHERE s.id=? AND s.user_id=? LIMIT 1');$stmt->execute([$store['seller_id'],Auth::id()]);$seller=$stmt->fetch();return [$store,is_array($seller)?$seller:null];
    }

    private function ownsSellerOrder(int $id): bool
    {
        [$store,$seller]=$this->context();if(!$store||!$seller||$id<1)return false;$stmt=Database::connection()->prepare('SELECT COUNT(*) FROM seller_orders WHERE id=? AND store_id=? AND seller_id=?');$stmt->execute([$id,$store['id'],$seller['id']]);return (int)$stmt->fetchColumn()===1;
    }
}
