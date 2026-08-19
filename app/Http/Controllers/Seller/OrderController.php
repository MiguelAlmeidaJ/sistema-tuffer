<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Mail\OrderMailService;
use App\Services\Shipping\MelhorEnvioLabelService;
use App\Services\Shipping\MelhorEnvioTrackingService;
use App\Services\Stores\SellerStoreContext;
use RuntimeException;
use Throwable;

final class OrderController extends Controller
{
    public function index(): string
    {
        $context = new SellerStoreContext();
        $store = $context->current();
        $status = trim((string) ($_GET['status'] ?? ''));
        $search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $allowed = ['pending_payment','paid','processing','shipped','delivered','cancelled','refunded'];
        $sql = 'SELECT so.*,o.code order_code,o.order_type,o.created_at order_created_at,u.name customer_name,u.email customer_email,sh.id shipment_id,sh.service_name,sh.carrier_name,sh.tracking_code,sh.status shipment_status FROM seller_orders so JOIN orders o ON o.id=so.order_id JOIN users u ON u.id=o.user_id LEFT JOIN shipments sh ON sh.seller_order_id=so.id WHERE so.store_id=?';
        $params = [$store['id']];
        if (in_array($status, $allowed, true)) { $sql .= ' AND so.status=?'; $params[] = $status; }
        if ($search !== '') { $sql .= ' AND (so.code LIKE ? OR o.code LIKE ? OR u.name LIKE ? OR u.email LIKE ?)'; $term='%'.$search.'%'; array_push($params,$term,$term,$term,$term); }
        $sql .= ' ORDER BY so.created_at DESC LIMIT 100';
        $statement = Database::connection()->prepare($sql); $statement->execute($params);
        $counts = Database::connection()->prepare("SELECT COUNT(*) total,SUM(status='pending_payment') pending_payment,SUM(status='paid') paid,SUM(status='processing') processing,SUM(status='shipped') shipped,SUM(status='delivered') delivered,COALESCE(SUM(seller_net_total),0) net_total FROM seller_orders WHERE store_id=?");
        $counts->execute([$store['id']]);
        return $this->page('seller/orders/index', 'layouts/seller', ['pageTitle'=>'Pedidos','orders'=>$statement->fetchAll(),'counts'=>$counts->fetch(),'currentStore'=>$store,'sellerStores'=>$context->stores(),'filters'=>['status'=>$status,'q'=>$search]]);
    }

    public function show(string $code): string
    {
        $context = new SellerStoreContext(); $store = $context->current(); $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT so.*,o.code order_code,o.status order_status,o.order_type,o.grand_total order_grand_total,o.created_at order_created_at,u.name customer_name,u.email customer_email,u.phone customer_phone FROM seller_orders so JOIN orders o ON o.id=so.order_id JOIN users u ON u.id=o.user_id WHERE so.code=? AND so.store_id=?');
        $statement->execute([$code,$store['id']]); $order=$statement->fetch();
        if(!$order){http_response_code(404);return $this->page('seller/orders/show','layouts/seller',['pageTitle'=>'Pedido não encontrado','order'=>null,'currentStore'=>$store,'sellerStores'=>$context->stores()]);}
        $items=$pdo->prepare('SELECT * FROM order_items WHERE seller_order_id=? ORDER BY id');$items->execute([$order['id']]);
        $shipment=$pdo->prepare('SELECT * FROM shipments WHERE seller_order_id=?');$shipment->execute([$order['id']]);$shipmentRow=$shipment->fetch()?:null;
        $events=[];if($shipmentRow){$event=$pdo->prepare('SELECT * FROM shipment_tracking_events WHERE shipment_id=? ORDER BY occurred_at DESC,id DESC');$event->execute([$shipmentRow['id']]);$events=$event->fetchAll();}
        $address=$pdo->prepare('SELECT * FROM order_addresses WHERE order_id=?');$address->execute([$order['order_id']]);
        return $this->page('seller/orders/show','layouts/seller',['pageTitle'=>'Pedido '.$code,'order'=>$order,'items'=>$items->fetchAll(),'shipment'=>$shipmentRow,'trackingEvents'=>$events,'address'=>$address->fetch()?:null,'trackingConfigured'=>(new MelhorEnvioTrackingService())->configured(),'labelPurchaseConfigured'=>(new MelhorEnvioLabelService())->configured(),'currentStore'=>$store,'sellerStores'=>$context->stores()]);
    }

    public function process(string $code): string
    {
        $store=(new SellerStoreContext())->current();$pdo=Database::connection();$pdo->beginTransaction();$orderId=0;
        try{$statement=$pdo->prepare('SELECT id,order_id,status FROM seller_orders WHERE code=? AND store_id=? FOR UPDATE');$statement->execute([$code,$store['id']]);$order=$statement->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');if($order['status']==='paid'){$pdo->prepare("UPDATE seller_orders SET status='processing' WHERE id=?")->execute([$order['id']]);$pdo->prepare("UPDATE orders SET status='processing' WHERE id=? AND status='paid'")->execute([$order['order_id']]);$pdo->prepare("INSERT INTO order_status_history(order_id,status,notes) VALUES(?,'processing',?)")->execute([$order['order_id'],'A loja '.$store['name'].' iniciou a preparação do pedido.']);}$orderId=(int)$order['order_id'];$pdo->commit();(new OrderMailService())->send($orderId,'order_processing_'.$order['id'],'Seu pedido está sendo preparado','Olá! A loja '.$store['name'].' iniciou a preparação do seu pedido '.$code.'.');Session::flash('success','Pedido marcado como em preparação.');}catch(Throwable $exception){if($pdo->inTransaction())$pdo->rollBack();Session::flash('error',$exception->getMessage());}
        return Response::redirect('/vendedor/pedidos/'.$code);
    }

    public function shipping(string $code): string
    {
        $store=(new SellerStoreContext())->current();$externalId=mb_substr(trim((string)($_POST['external_id']??'')),0,100);$trackingCode=mb_substr(trim((string)($_POST['tracking_code']??'')),0,100);$carrier=mb_substr(trim((string)($_POST['carrier_name']??'')),0,120);
        if($externalId===''&&$trackingCode===''){Session::flash('error','Informe o ID da etiqueta do Melhor Envio ou o código de rastreio.');return Response::redirect('/vendedor/pedidos/'.$code);}
        $pdo=Database::connection();$pdo->beginTransaction();$notify=false;$orderId=0;
        try{$statement=$pdo->prepare('SELECT so.id,so.order_id,so.status,sh.id shipment_id,sh.tracking_code current_tracking FROM seller_orders so JOIN shipments sh ON sh.seller_order_id=so.id WHERE so.code=? AND so.store_id=? FOR UPDATE');$statement->execute([$code,$store['id']]);$order=$statement->fetch();if(!$order)throw new RuntimeException('Pedido ou remessa não encontrado.');if(!in_array($order['status'],['paid','processing','shipped'],true))throw new RuntimeException('Este pedido ainda não pode ser enviado.');$shipmentStatus=$trackingCode!==''?'posted':'purchased';$pdo->prepare('UPDATE shipments SET external_id=COALESCE(NULLIF(?,\'\'),external_id),tracking_code=COALESCE(NULLIF(?,\'\'),tracking_code),carrier_name=COALESCE(NULLIF(?,\'\'),carrier_name),status=? WHERE id=?')->execute([$externalId,$trackingCode,$carrier,$shipmentStatus,$order['shipment_id']]);if($trackingCode!==''&&$trackingCode!==$order['current_tracking']){$key=hash('sha256',$order['shipment_id'].'|manual|'.$trackingCode);$pdo->prepare("INSERT IGNORE INTO shipment_tracking_events(shipment_id,provider_event_key,event_code,description,occurred_at) VALUES(?,?,'posted','Código de rastreio informado pela loja.',NOW())")->execute([$order['shipment_id'],$key]);$pdo->prepare("UPDATE seller_orders SET status='shipped' WHERE id=?")->execute([$order['id']]);$pdo->prepare("UPDATE orders SET status='processing' WHERE id=? AND status='paid'")->execute([$order['order_id']]);$pdo->prepare("INSERT INTO order_status_history(order_id,status,notes) VALUES(?,'shipped',?)")->execute([$order['order_id'],'Pedido enviado pela loja '.$store['name'].'.']);$notify=true;}$orderId=(int)$order['order_id'];$pdo->commit();if($notify)(new OrderMailService())->send($orderId,'order_shipped_'.$order['id'],'Seu pedido foi enviado','A loja '.$store['name'].' enviou o pedido '.$code.'. Código de rastreio: '.$trackingCode);Session::flash('success','Dados da remessa atualizados.');}catch(Throwable $exception){if($pdo->inTransaction())$pdo->rollBack();Session::flash('error',$exception->getMessage());}
        return Response::redirect('/vendedor/pedidos/'.$code);
    }

    public function purchaseLabel(string $code): string
    {
        $store = (new SellerStoreContext())->current();
        if (empty($_POST['confirm_purchase'])) {
            Session::flash('error', 'Confirme a compra da etiqueta antes de continuar.');
            return Response::redirect('/vendedor/pedidos/' . $code);
        }
        try {
            $result = (new MelhorEnvioLabelService())->purchaseForSellerOrder(
                $code,
                (int) $store['id'],
                (string) ($_POST['invoice_key'] ?? '')
            );
            Session::flash(
                'success',
                $result['status'] === 'ready'
                    ? 'Etiqueta comprada e pronta para impressão.'
                    : 'Etiqueta comprada. A impressão ainda está sendo preparada; tente novamente em instantes.'
            );
        } catch (Throwable $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect('/vendedor/pedidos/' . $code);
    }

    public function sync(string $code): string
    {
        $store=(new SellerStoreContext())->current();$statement=Database::connection()->prepare('SELECT sh.id,sh.status,so.order_id FROM shipments sh JOIN seller_orders so ON so.id=sh.seller_order_id WHERE so.code=? AND so.store_id=?');$statement->execute([$code,$store['id']]);$shipment=$statement->fetch();
        try{if(!$shipment)throw new RuntimeException('Remessa não encontrada.');$updated=(new MelhorEnvioTrackingService())->syncShipment((int)$shipment['id'],true);if(($updated['status']??'')==='delivered'&&$shipment['status']!=='delivered')(new OrderMailService())->send((int)$shipment['order_id'],'order_delivered_'.$shipment['id'],'Pedido entregue','A transportadora confirmou a entrega do pedido '.$code.'.');Session::flash('success','Rastreamento sincronizado com o Melhor Envio.');}catch(Throwable $exception){Session::flash('error',$exception->getMessage());}
        return Response::redirect('/vendedor/pedidos/'.$code);
    }
}
