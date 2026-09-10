<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Fiscal\FiscalIssuanceMode;
use App\Services\Fiscal\FiscalOrchestratorService;
use App\Services\Fiscal\StoreFiscalProfileRepository;
use App\Services\Stores\SellerStoreContext;
use PDO;
use RuntimeException;
use Throwable;

final class FiscalController extends Controller
{
    public function index(): string
    {
        $context=new SellerStoreContext();$store=$context->current();if(!$store)return Response::redirect('/vendedor');
        $pdo=Database::connection();$stmt=$pdo->prepare('SELECT s.*,u.email user_email FROM sellers s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.user_id=? LIMIT 1');$stmt->execute([$store['seller_id'],Auth::id()]);$seller=$stmt->fetch();if(!is_array($seller))return Response::redirect('/vendedor');
        $profile=(new StoreFiscalProfileRepository($pdo))->find((int)$store['id'],(int)$seller['id']);
        $stmt=$pdo->prepare('SELECT p.id,p.name,p.sku,p.status,pfp.* FROM products p LEFT JOIN product_fiscal_profiles pfp ON pfp.product_id=p.id WHERE p.store_id=? ORDER BY p.name LIMIT 250');$stmt->execute([$store['id']]);$products=$stmt->fetchAll();
        $stmt=$pdo->prepare('SELECT fd.*,so.code seller_order_code,o.code order_code FROM fiscal_documents fd JOIN seller_orders so ON so.id=fd.seller_order_id JOIN orders o ON o.id=so.order_id WHERE fd.store_id=? ORDER BY fd.id DESC LIMIT 50');$stmt->execute([$store['id']]);$documents=$stmt->fetchAll();
        return $this->page('seller/fiscal/index','layouts/seller',[
            'pageTitle'=>'Fiscal','currentStore'=>$store,'stores'=>$context->stores(),'seller'=>$seller,'profile'=>$profile,'products'=>$products,'documents'=>$documents,
            'fiscalMode'=>FiscalIssuanceMode::normalize((string)($profile['issuance_mode']??FiscalIssuanceMode::MANUAL)),
            'fiscalProvider'=>(string)($profile['provider']??'manual'),'fiscalEnvironment'=>(string)($profile['environment']??'production'),
        ]);
    }

    public function saveProfile(): string
    {
        $context=new SellerStoreContext();$store=$context->current();if(!$store)return Response::redirect('/vendedor');
        $pdo=Database::connection();$stmt=$pdo->prepare('SELECT s.*,u.email user_email FROM sellers s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.user_id=? LIMIT 1');$stmt->execute([$store['seller_id'],Auth::id()]);$seller=$stmt->fetch();if(!is_array($seller))return Response::redirect('/vendedor');

        $mode=FiscalIssuanceMode::normalize((string)($_POST['issuance_mode']??FiscalIssuanceMode::MANUAL));
        $provider=mb_strtolower(trim((string)($_POST['provider']??'external')));$provider=preg_replace('/[^a-z0-9_-]+/','',$provider)??'external';$provider=mb_substr($provider===''?'external':$provider,0,60);
        if($mode===FiscalIssuanceMode::MANUAL){$provider='manual';}
        elseif($mode===FiscalIssuanceMode::EXTERNAL){if(in_array($provider,['disabled','manual'],true))$provider='external';}
        elseif($mode===FiscalIssuanceMode::CONNECTOR){
            if($provider!=='tiny'){Session::flash('error','O único conector fiscal nativo disponível no momento é Tiny / Olist ERP. Para outro sistema, use ERP/API própria ou registro manual.');return Response::redirect('/vendedor/fiscal');}
            $provider='tiny';
        }

        $environment=(string)($_POST['environment']??'production')==='homologation'?'homologation':'production';
        $indicator=(string)($_POST['state_registration_indicator']??'contributor');if(!in_array($indicator,['contributor','exempt','non_contributor'],true))$indicator='contributor';
        $enabled=!empty($_POST['enabled'])?1:0;
        $legalName=trim((string)($_POST['legal_name']??$seller['legal_name']??''));$tradeName=trim((string)($_POST['trade_name']??$seller['trade_name']??''));$document=trim((string)($_POST['document']??$seller['document']??''));$stateRegistration=trim((string)($_POST['state_registration']??$seller['state_registration']??''));
        $taxRegime=trim((string)($_POST['tax_regime']??''));$crt=mb_strtoupper(trim((string)($_POST['crt']??'')));$municipalRegistration=trim((string)($_POST['municipal_registration']??''));$email=trim((string)($_POST['fiscal_email']??''));
        $cep=preg_replace('/\D+/','',(string)($_POST['postal_code']??''))??'';$street=trim((string)($_POST['street']??''));$number=trim((string)($_POST['number']??''));$complement=trim((string)($_POST['complement']??''));$neighborhood=trim((string)($_POST['neighborhood']??''));$city=trim((string)($_POST['city']??''));$state=mb_strtoupper(trim((string)($_POST['state']??'')));$ibge=preg_replace('/\D+/','',(string)($_POST['city_ibge_code']??''))??'';$series=max(1,min(999,(int)($_POST['nfe_series']??1)));
        if($legalName===''||$document===''){Session::flash('error','Informe a razão social e o documento fiscal desta loja.');return Response::redirect('/vendedor/fiscal');}
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)){Session::flash('error','Informe um e-mail fiscal válido.');return Response::redirect('/vendedor/fiscal');}
        $state=preg_match('/^[A-Z]{2}$/',$state)?$state:'';$ibge=preg_match('/^\d{7}$/',$ibge)?$ibge:'';$cep=preg_match('/^\d{8}$/',$cep)?$cep:'';

        $sql='INSERT INTO store_fiscal_profiles(store_id,seller_id,enabled,issuance_mode,provider,environment,legal_name,trade_name,document,state_registration,tax_regime,crt,state_registration_indicator,municipal_registration,fiscal_email,postal_code,street,number,complement,neighborhood,city,state,city_ibge_code,nfe_series) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE seller_id=VALUES(seller_id),enabled=VALUES(enabled),issuance_mode=VALUES(issuance_mode),provider=VALUES(provider),environment=VALUES(environment),legal_name=VALUES(legal_name),trade_name=VALUES(trade_name),document=VALUES(document),state_registration=VALUES(state_registration),tax_regime=VALUES(tax_regime),crt=VALUES(crt),state_registration_indicator=VALUES(state_registration_indicator),municipal_registration=VALUES(municipal_registration),fiscal_email=VALUES(fiscal_email),postal_code=VALUES(postal_code),street=VALUES(street),number=VALUES(number),complement=VALUES(complement),neighborhood=VALUES(neighborhood),city=VALUES(city),state=VALUES(state),city_ibge_code=VALUES(city_ibge_code),nfe_series=VALUES(nfe_series)';
        $pdo->prepare($sql)->execute([$store['id'],$store['seller_id'],$enabled,$mode,$provider,$environment,$legalName,$tradeName!==''?$tradeName:null,$document,$stateRegistration!==''?$stateRegistration:null,$taxRegime!==''?$taxRegime:null,$crt!==''?$crt:null,$indicator,$municipalRegistration!==''?$municipalRegistration:null,$email!==''?$email:null,$cep!==''?$cep:null,$street!==''?$street:null,$number!==''?$number:null,$complement!==''?$complement:null,$neighborhood!==''?$neighborhood:null,$city!==''?$city:null,$state!==''?$state:null,$ibge!==''?$ibge:null,$series]);
        $this->resyncStore((int)$store['id']);
        $message=$mode===FiscalIssuanceMode::CONNECTOR?'Configuração fiscal salva. Agora conecte a conta Tiny desta loja para ativar a automação.':'Configuração fiscal desta loja salva.';
        Session::flash('success',$message);return Response::redirect('/vendedor/fiscal');
    }

    public function saveProduct(string $id): string
    {
        $productId=(int)$id;$context=new SellerStoreContext();$store=$context->current();if(!$store)return Response::redirect('/vendedor');$pdo=Database::connection();$stmt=$pdo->prepare('SELECT id FROM products WHERE id=? AND store_id=? AND seller_id=? LIMIT 1');$stmt->execute([$productId,$store['id'],$store['seller_id']]);
        if(!(int)$stmt->fetchColumn()){http_response_code(404);Session::flash('error','Produto não encontrado na loja atual.');return Response::redirect('/vendedor/fiscal');}
        $digits=static fn(string $field,int $max):?string=>(($value=substr(preg_replace('/\D+/','',(string)($_POST[$field]??''))??'',0,$max))===''?null:$value);
        $upper=static fn(string $field,int $max):?string=>(($value=mb_substr(mb_strtoupper(trim((string)($_POST[$field]??''))),0,$max))===''?null:$value);
        $ncm=$digits('ncm',8);$cest=$digits('cest',7);$originRaw=trim((string)($_POST['origin']??''));$origin=$originRaw===''?null:(int)$originRaw;$cfopIn=$digits('cfop_in_state',4);$cfopOut=$digits('cfop_out_state',4);
        if($ncm!==null&&strlen($ncm)!==8){Session::flash('error','O NCM deve possuir 8 dígitos.');return Response::redirect('/vendedor/fiscal');}
        if($cest!==null&&strlen($cest)!==7){Session::flash('error','O CEST deve possuir 7 dígitos quando informado.');return Response::redirect('/vendedor/fiscal');}
        if($origin!==null&&($origin<0||$origin>8)){Session::flash('error','Origem da mercadoria inválida.');return Response::redirect('/vendedor/fiscal');}
        $pdo->prepare('INSERT INTO product_fiscal_profiles(product_id,ncm,cest,origin,commercial_unit,tributary_unit,gtin,cfop_in_state,cfop_out_state,icms_code,pis_code,cofins_code,ipi_code,ibs_cbs_cst,ibs_cbs_classification) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ncm=VALUES(ncm),cest=VALUES(cest),origin=VALUES(origin),commercial_unit=VALUES(commercial_unit),tributary_unit=VALUES(tributary_unit),gtin=VALUES(gtin),cfop_in_state=VALUES(cfop_in_state),cfop_out_state=VALUES(cfop_out_state),icms_code=VALUES(icms_code),pis_code=VALUES(pis_code),cofins_code=VALUES(cofins_code),ipi_code=VALUES(ipi_code),ibs_cbs_cst=VALUES(ibs_cbs_cst),ibs_cbs_classification=VALUES(ibs_cbs_classification)')->execute([$productId,$ncm,$cest,$origin,$upper('commercial_unit',6)??'UN',$upper('tributary_unit',6)??'UN',$upper('gtin',20),$cfopIn,$cfopOut,$upper('icms_code',4),$upper('pis_code',4),$upper('cofins_code',4),$upper('ipi_code',4),$upper('ibs_cbs_cst',4),$upper('ibs_cbs_classification',20)]);
        Session::flash('success','Classificação fiscal do produto atualizada como referência da loja.');return Response::redirect('/vendedor/fiscal#produto-'.$productId);
    }

    public function registerDocument(string $id): string
    {
        $sellerOrderId=(int)$id;$context=new SellerStoreContext();$store=$context->current();if(!$store)return Response::redirect('/vendedor');$stmt=Database::connection()->prepare('SELECT id FROM seller_orders WHERE id=? AND store_id=? AND seller_id=? LIMIT 1');$stmt->execute([$sellerOrderId,$store['id'],$store['seller_id']]);
        if(!(int)$stmt->fetchColumn()){http_response_code(404);Session::flash('error','Pedido fiscal não encontrado na loja atual.');return Response::redirect('/vendedor/fiscal');}
        try{(new FiscalOrchestratorService())->registerOutsideDocument($sellerOrderId,['number'=>$_POST['number']??null,'series'=>$_POST['series']??null,'access_key'=>$_POST['access_key']??null,'protocol'=>$_POST['protocol']??null,'external_reference'=>$_POST['external_reference']??null]);Session::flash('success','NF-e emitida pela loja foi vinculada ao pedido.');}
        catch(RuntimeException $e){Session::flash('error',$e->getMessage());}catch(Throwable){Session::flash('error','Não foi possível registrar a NF-e. Revise os dados e tente novamente.');}
        return Response::redirect('/vendedor/fiscal#documentos');
    }

    private function resyncStore(int $storeId): void
    {
        $stmt=Database::connection()->prepare("SELECT id FROM seller_orders WHERE store_id=? AND status IN ('paid','processing','shipped','delivered') ORDER BY id DESC LIMIT 100");$stmt->execute([$storeId]);$service=new FiscalOrchestratorService();
        foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $id){try{$service->syncSellerOrder((int)$id);}catch(Throwable){}}
    }
}
