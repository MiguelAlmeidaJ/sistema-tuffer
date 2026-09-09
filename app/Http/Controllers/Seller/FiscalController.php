<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Fiscal\FiscalConfiguration;
use App\Services\Fiscal\FiscalDocumentService;
use App\Services\Stores\SellerStoreContext;
use Throwable;

final class FiscalController extends Controller
{
    public function index(): string
    {
        $context=new SellerStoreContext();$store=$context->current();if(!$store)return Response::redirect('/vendedor');$pdo=Database::connection();
        $s=$pdo->prepare('SELECT s.*,u.email user_email FROM sellers s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.user_id=? LIMIT 1');$s->execute([$store['seller_id'],Auth::id()]);$seller=$s->fetch();if(!is_array($seller))return Response::redirect('/vendedor');
        $s=$pdo->prepare('SELECT * FROM seller_fiscal_profiles WHERE seller_id=? LIMIT 1');$s->execute([$seller['id']]);$profile=$s->fetch()?:null;
        $s=$pdo->prepare('SELECT p.id,p.name,p.sku,p.status,pfp.* FROM products p LEFT JOIN product_fiscal_profiles pfp ON pfp.product_id=p.id WHERE p.store_id=? ORDER BY p.name LIMIT 250');$s->execute([$store['id']]);$products=$s->fetchAll();
        $s=$pdo->prepare('SELECT fd.*,so.code seller_order_code,o.code order_code FROM fiscal_documents fd JOIN seller_orders so ON so.id=fd.seller_order_id JOIN orders o ON o.id=so.order_id WHERE fd.store_id=? ORDER BY fd.id DESC LIMIT 50');$s->execute([$store['id']]);$documents=$s->fetchAll();
        $cfg=new FiscalConfiguration();return $this->page('seller/fiscal/index','layouts/seller',['pageTitle'=>'Fiscal','currentStore'=>$store,'stores'=>$context->stores(),'seller'=>$seller,'profile'=>$profile,'products'=>$products,'documents'=>$documents,'fiscalProvider'=>$cfg->provider(),'fiscalEnvironment'=>$cfg->environment(),'fiscalAutoIssue'=>$cfg->autoIssue()]);
    }

    public function saveProfile(): string
    {
        $context=new SellerStoreContext();$store=$context->current();if(!$store)return Response::redirect('/vendedor');$indicator=(string)($_POST['state_registration_indicator']??'contributor');if(!in_array($indicator,['contributor','exempt','non_contributor'],true))$indicator='contributor';$state=mb_strtoupper(trim((string)($_POST['state']??'')));$ibge=preg_replace('/\D+/','',(string)($_POST['city_ibge_code']??''))??'';$cep=preg_replace('/\D+/','',(string)($_POST['postal_code']??''))??'';$series=max(1,min(999,(int)($_POST['nfe_series']??1)));$email=trim((string)($_POST['fiscal_email']??''));
        if(!preg_match('/^[A-Z]{2}$/',$state)||!preg_match('/^\d{7}$/',$ibge)||!preg_match('/^\d{8}$/',$cep)){Session::flash('error','Informe UF, código IBGE e CEP fiscal válidos.');return Response::redirect('/vendedor/fiscal');}if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)){Session::flash('error','Informe um e-mail fiscal válido.');return Response::redirect('/vendedor/fiscal');}
        $values=[!empty($_POST['enabled'])?1:0,trim((string)($_POST['tax_regime']??''))?:null,mb_strtoupper(trim((string)($_POST['crt']??'')))?:null,$indicator,trim((string)($_POST['municipal_registration']??''))?:null,$email?:null,$cep,trim((string)($_POST['street']??''))?:null,trim((string)($_POST['number']??''))?:null,trim((string)($_POST['complement']??''))?:null,trim((string)($_POST['neighborhood']??''))?:null,trim((string)($_POST['city']??''))?:null,$state,$ibge,$series,$store['seller_id']];
        if($values[1]===null||$values[7]===null||$values[8]===null||$values[10]===null||$values[11]===null){Session::flash('error','Preencha regime tributário e endereço fiscal completo.');return Response::redirect('/vendedor/fiscal');}
        Database::connection()->prepare('INSERT INTO seller_fiscal_profiles(enabled,tax_regime,crt,state_registration_indicator,municipal_registration,fiscal_email,postal_code,street,number,complement,neighborhood,city,state,city_ibge_code,nfe_series,seller_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),tax_regime=VALUES(tax_regime),crt=VALUES(crt),state_registration_indicator=VALUES(state_registration_indicator),municipal_registration=VALUES(municipal_registration),fiscal_email=VALUES(fiscal_email),postal_code=VALUES(postal_code),street=VALUES(street),number=VALUES(number),complement=VALUES(complement),neighborhood=VALUES(neighborhood),city=VALUES(city),state=VALUES(state),city_ibge_code=VALUES(city_ibge_code),nfe_series=VALUES(nfe_series)')->execute($values);
        $this->resyncStore((int)$store['id']);Session::flash('success','Configuração fiscal salva. Pedidos pagos pendentes foram revalidados.');return Response::redirect('/vendedor/fiscal');
    }

    public function saveProduct(string $id): string
    {
        $productId=(int)$id;$context=new SellerStoreContext();$store=$context->current();if(!$store)return Response::redirect('/vendedor');$pdo=Database::connection();$s=$pdo->prepare('SELECT id FROM products WHERE id=? AND store_id=? AND seller_id=? LIMIT 1');$s->execute([$productId,$store['id'],$store['seller_id']]);if(!(int)$s->fetchColumn()){http_response_code(404);Session::flash('error','Produto não encontrado na loja atual.');return Response::redirect('/vendedor/fiscal');}
        $digits=static fn(string $field,int $max):?string=>(($v=substr(preg_replace('/\D+/','',(string)($_POST[$field]??''))??'',0,$max))===''?null:$v);$upper=static fn(string $field,int $max):?string=>(($v=mb_substr(mb_strtoupper(trim((string)($_POST[$field]??''))),0,$max))===''?null:$v);$ncm=$digits('ncm',8);$cest=$digits('cest',7);$originRaw=trim((string)($_POST['origin']??''));$origin=$originRaw===''?null:(int)$originRaw;$cfopIn=$digits('cfop_in_state',4);$cfopOut=$digits('cfop_out_state',4);
        if($ncm!==null&&strlen($ncm)!==8){Session::flash('error','O NCM deve possuir 8 dígitos.');return Response::redirect('/vendedor/fiscal');}if($cest!==null&&strlen($cest)!==7){Session::flash('error','O CEST deve possuir 7 dígitos quando informado.');return Response::redirect('/vendedor/fiscal');}if($origin!==null&&($origin<0||$origin>8)){Session::flash('error','Origem da mercadoria inválida.');return Response::redirect('/vendedor/fiscal');}
        $pdo->prepare('INSERT INTO product_fiscal_profiles(product_id,ncm,cest,origin,commercial_unit,tributary_unit,gtin,cfop_in_state,cfop_out_state,icms_code,pis_code,cofins_code,ipi_code,ibs_cbs_cst,ibs_cbs_classification) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ncm=VALUES(ncm),cest=VALUES(cest),origin=VALUES(origin),commercial_unit=VALUES(commercial_unit),tributary_unit=VALUES(tributary_unit),gtin=VALUES(gtin),cfop_in_state=VALUES(cfop_in_state),cfop_out_state=VALUES(cfop_out_state),icms_code=VALUES(icms_code),pis_code=VALUES(pis_code),cofins_code=VALUES(cofins_code),ipi_code=VALUES(ipi_code),ibs_cbs_cst=VALUES(ibs_cbs_cst),ibs_cbs_classification=VALUES(ibs_cbs_classification)')->execute([$productId,$ncm,$cest,$origin,$upper('commercial_unit',6)??'UN',$upper('tributary_unit',6)??'UN',$upper('gtin',20),$cfopIn,$cfopOut,$upper('icms_code',4),$upper('pis_code',4),$upper('cofins_code',4),$upper('ipi_code',4),$upper('ibs_cbs_cst',4),$upper('ibs_cbs_classification',20)]);
        $this->resyncStore((int)$store['id']);Session::flash('success','Classificação fiscal do produto atualizada.');return Response::redirect('/vendedor/fiscal#produto-'.$productId);
    }

    private function resyncStore(int $storeId): void
    {
        $s=Database::connection()->prepare("SELECT id FROM seller_orders WHERE store_id=? AND status IN ('paid','processing','shipped','delivered') ORDER BY id DESC LIMIT 100");$s->execute([$storeId]);$service=new FiscalDocumentService();foreach($s->fetchAll(\PDO::FETCH_COLUMN) as $id){try{$service->syncSellerOrder((int)$id);}catch(Throwable){}}
    }
}
