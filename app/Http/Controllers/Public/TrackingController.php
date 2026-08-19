<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Core\Database;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Shipping\MelhorEnvioTrackingService;

final class TrackingController extends Controller
{
    public function index(): string
    {
        return $this->page('public/tracking/index', 'layouts/public', ['pageTitle'=>'Rastrear pedido','tracking'=>null,'trackingError'=>null,'input'=>['code'=>'','email'=>'']]);
    }

    public function track(): string
    {
        $code=mb_strtoupper(mb_substr(trim((string)($_POST['code']??'')),0,50));$email=mb_strtolower(mb_substr(trim((string)($_POST['email']??'')),0,190));$error=null;$tracking=null;
        $attempts=Session::get('tracking_attempts',[]);$attempts=is_array($attempts)?array_values(array_filter($attempts,static fn($time):bool=>(int)$time>time()-900)):[];
        if(count($attempts)>=10){$error='Muitas consultas em sequência. Aguarde alguns minutos e tente novamente.';}elseif($code===''||!filter_var($email,FILTER_VALIDATE_EMAIL)){$error='Informe o código do pedido e o e-mail usado na compra.';}else{$attempts[]=time();Session::put('tracking_attempts',$attempts);$pdo=Database::connection();$orderStatement=$pdo->prepare('SELECT o.id,o.code,o.status,o.created_at FROM orders o JOIN users u ON u.id=o.user_id WHERE o.code=? AND LOWER(u.email)=?');$orderStatement->execute([$code,$email]);$order=$orderStatement->fetch();if(!$order){$error='Pedido não encontrado. Confira o código e o e-mail informado.';}else{$shipments=$this->shipments((int)$order['id']);$service=new MelhorEnvioTrackingService();if($service->configured()){foreach($shipments as $shipment){if(empty($shipment['external_id']))continue;try{$service->syncShipment((int)$shipment['id']);}catch(\Throwable $exception){error_log('Falha ao atualizar rastreio público: '.$exception->getMessage());}}$shipments=$this->shipments((int)$order['id']);}$tracking=['order'=>$order,'shipments'=>$shipments];}}
        return $this->page('public/tracking/index','layouts/public',['pageTitle'=>'Rastrear pedido','tracking'=>$tracking,'trackingError'=>$error,'input'=>['code'=>$code,'email'=>$email]]);
    }

    /** @return array<int,array<string,mixed>> */
    private function shipments(int $orderId): array
    {
        $pdo=Database::connection();$statement=$pdo->prepare('SELECT sh.*,so.code seller_order_code,so.status seller_order_status,st.name store_name FROM shipments sh JOIN seller_orders so ON so.id=sh.seller_order_id JOIN stores st ON st.id=so.store_id WHERE so.order_id=? ORDER BY sh.id');$statement->execute([$orderId]);$shipments=$statement->fetchAll();$events=$pdo->prepare('SELECT event_code,description,city,state,occurred_at FROM shipment_tracking_events WHERE shipment_id=? ORDER BY occurred_at DESC,id DESC LIMIT 20');foreach($shipments as &$shipment){if(!$this->trustedTrackingUrl((string)($shipment['tracking_url']??'')))$shipment['tracking_url']=null;$events->execute([$shipment['id']]);$shipment['events']=$events->fetchAll();}unset($shipment);return $shipments;
    }

    private function trustedTrackingUrl(string $url): bool
    {
        if ($url === '') return true;
        $parts=parse_url($url);$host=strtolower((string)($parts['host']??''));
        return ($parts['scheme']??'')==='https'&&($host==='melhorenvio.com.br'||str_ends_with($host,'.melhorenvio.com.br')||$host==='melhorrastreio.com.br'||str_ends_with($host,'.melhorrastreio.com.br'));
    }
}
