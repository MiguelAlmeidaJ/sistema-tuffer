<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Fiscal\FiscalOrchestratorService;
use App\Services\Fiscal\FiscalWebhookService;
use App\Services\Stores\SellerStoreContext;
use PDO;
use RuntimeException;
use Throwable;

final class FiscalWebhookController extends Controller
{
    public function index(): string
    {
        [$store,$seller]=$this->context();if(!$store||!$seller)return Response::redirect('/vendedor');
        $pdo=Database::connection();$service=new FiscalWebhookService($pdo);
        $stmt=$pdo->prepare('SELECT enabled,issuance_mode FROM store_fiscal_profiles WHERE store_id=? AND seller_id=? LIMIT 1');$stmt->execute([$store['id'],$seller['id']]);$profile=$stmt->fetch()?:null;
        $stmt=$pdo->prepare('SELECT * FROM fiscal_webhook_deliveries WHERE store_id=? ORDER BY id DESC LIMIT 50');$stmt->execute([$store['id']]);
        return $this->page('seller/fiscal/webhook','layouts/seller',[
            'pageTitle'=>'Webhook fiscal','currentStore'=>$store,'stores'=>(new SellerStoreContext())->stores(),'profile'=>$profile,'webhook'=>$service->metadata((int)$store['id'],(int)$seller['id']),'deliveries'=>$stmt->fetchAll(),'generatedWebhookSecret'=>Session::pullFlash('fiscal_webhook_secret'),
        ]);
    }

    public function configure(): string
    {
        [$store,$seller]=$this->context();if(!$store||!$seller)return Response::redirect('/vendedor');
        $pdo=Database::connection();$stmt=$pdo->prepare('SELECT enabled,issuance_mode FROM store_fiscal_profiles WHERE store_id=? AND seller_id=? LIMIT 1');$stmt->execute([$store['id'],$seller['id']]);$profile=$stmt->fetch();
        if(!is_array($profile)||!(bool)$profile['enabled']||(string)$profile['issuance_mode']!=='external'){Session::flash('error','Configure esta loja no modo Integração externa / ERP antes de ativar o webhook.');return Response::redirect('/vendedor/fiscal/webhook');}
        try{
            $service=new FiscalWebhookService($pdo);$secret=$service->configure((int)$store['id'],(int)$seller['id'],(string)($_POST['endpoint_url']??''));
            $stmt=$pdo->prepare("SELECT id FROM seller_orders WHERE store_id=? AND seller_id=? AND status IN ('paid','processing','shipped','delivered') ORDER BY id DESC LIMIT 100");$stmt->execute([$store['id'],$seller['id']]);
            $orchestrator=new FiscalOrchestratorService($pdo);foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $sellerOrderId){$id=(int)$sellerOrderId;try{$orchestrator->syncSellerOrder($id);$service->enqueueReady($id);}catch(Throwable){}}
            Session::flash('fiscal_webhook_secret',$secret);Session::flash('success','Webhook fiscal ativado. Pedidos elegíveis foram reconciliados. Copie o segredo HMAC agora: ele não será exibido novamente.');
        }
        catch(RuntimeException $e){Session::flash('error',$e->getMessage());}catch(Throwable){Session::flash('error','Não foi possível configurar o webhook fiscal.');}
        return Response::redirect('/vendedor/fiscal/webhook');
    }

    public function disable(): string
    {
        [$store,$seller]=$this->context();if(!$store||!$seller)return Response::redirect('/vendedor');
        (new FiscalWebhookService())->disable((int)$store['id'],(int)$seller['id']);Session::flash('success','Webhook fiscal desativado.');return Response::redirect('/vendedor/fiscal/webhook');
    }

    /** @return array{0:?array,1:?array} */
    private function context(): array
    {
        $context=new SellerStoreContext();$store=$context->current();if(!$store)return [null,null];
        $stmt=Database::connection()->prepare('SELECT s.* FROM sellers s WHERE s.id=? AND s.user_id=? LIMIT 1');$stmt->execute([$store['seller_id'],Auth::id()]);$seller=$stmt->fetch();return [$store,is_array($seller)?$seller:null];
    }
}
