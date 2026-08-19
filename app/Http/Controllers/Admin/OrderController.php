<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Shipping\MelhorEnvioTrackingService;
use App\Services\Payments\Pagarme\PagarmePixRefundService;
use RuntimeException;
use Throwable;

final class OrderController extends Controller
{
    public function index(): string
    {
        $status=trim((string)($_GET['status']??''));$search=mb_substr(trim((string)($_GET['q']??'')),0,100);$allowed=['pending_payment','paid','processing','completed','cancelled','refunded'];$sql='SELECT o.*,u.name customer_name,u.email customer_email,COUNT(DISTINCT so.id) store_count,COUNT(DISTINCT sh.id) shipment_count FROM orders o JOIN users u ON u.id=o.user_id LEFT JOIN seller_orders so ON so.order_id=o.id LEFT JOIN shipments sh ON sh.seller_order_id=so.id WHERE 1=1';$params=[];if(in_array($status,$allowed,true)){$sql.=' AND o.status=?';$params[]=$status;}if($search!==''){$sql.=' AND (o.code LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';$term='%'.$search.'%';array_push($params,$term,$term,$term);}$sql.=' GROUP BY o.id,u.id ORDER BY o.created_at DESC LIMIT 150';$statement=Database::connection()->prepare($sql);$statement->execute($params);$counts=Database::connection()->query("SELECT COUNT(*) total,SUM(status='pending_payment') pending_payment,SUM(status IN ('paid','processing')) active,SUM(status='completed') completed FROM orders")->fetch();return $this->page('admin/orders/index','layouts/admin',['pageTitle'=>'Pedidos','orders'=>$statement->fetchAll(),'counts'=>$counts,'filters'=>['status'=>$status,'q'=>$search]]);
    }

    public function show(string $code): string
    {
        $pdo=Database::connection();$statement=$pdo->prepare('SELECT o.*,u.name customer_name,u.email customer_email,u.phone customer_phone,u.document customer_document FROM orders o JOIN users u ON u.id=o.user_id WHERE o.code=?');$statement->execute([$code]);$order=$statement->fetch();if(!$order){http_response_code(404);return $this->page('admin/orders/show','layouts/admin',['pageTitle'=>'Pedido não encontrado','order'=>null]);}$sub=$pdo->prepare('SELECT so.*,st.name store_name,s.trade_name,sh.id shipment_id,sh.external_id,sh.service_name,sh.carrier_name,sh.tracking_code,sh.tracking_url,sh.status shipment_status,sh.raw_status,sh.last_synced_at FROM seller_orders so JOIN stores st ON st.id=so.store_id JOIN sellers s ON s.id=so.seller_id LEFT JOIN shipments sh ON sh.seller_order_id=so.id WHERE so.order_id=? ORDER BY so.id');$sub->execute([$order['id']]);$sellerOrders=$sub->fetchAll();$items=$pdo->prepare('SELECT * FROM order_items WHERE seller_order_id=? ORDER BY id');foreach($sellerOrders as &$sellerOrder){$items->execute([$sellerOrder['id']]);$sellerOrder['items']=$items->fetchAll();}unset($sellerOrder);$payment=$pdo->prepare('SELECT * FROM payments WHERE order_id=? ORDER BY id DESC');$payment->execute([$order['id']]);$history=$pdo->prepare('SELECT * FROM order_status_history WHERE order_id=? ORDER BY created_at DESC,id DESC');$history->execute([$order['id']]);$address=$pdo->prepare('SELECT * FROM order_addresses WHERE order_id=?');$address->execute([$order['id']]);return $this->page('admin/orders/show','layouts/admin',['pageTitle'=>'Pedido '.$code,'order'=>$order,'sellerOrders'=>$sellerOrders,'payments'=>$payment->fetchAll(),'history'=>$history->fetchAll(),'address'=>$address->fetch()?:null,'trackingConfigured'=>(new MelhorEnvioTrackingService())->configured()]);
    }

    public function sync(string $code, string $shipmentId): string
    {
        $statement=Database::connection()->prepare('SELECT sh.id FROM shipments sh JOIN seller_orders so ON so.id=sh.seller_order_id JOIN orders o ON o.id=so.order_id WHERE o.code=? AND sh.id=?');$statement->execute([$code,(int)$shipmentId]);try{$id=(int)$statement->fetchColumn();if(!$id)throw new RuntimeException('Remessa não encontrada.');(new MelhorEnvioTrackingService())->syncShipment($id,true);Session::flash('success','Rastreamento sincronizado com o Melhor Envio.');}catch(Throwable $exception){Session::flash('error',$exception->getMessage());}return Response::redirect('/admin/pedidos/'.$code);
    }

    public function refundPix(string $code, string $paymentId): string
    {
        $statement = Database::connection()->prepare(
            "SELECT p.id FROM payments p JOIN orders o ON o.id=p.order_id
             WHERE o.code=? AND p.id=? AND p.provider='pagarme'
               AND p.integration_type='orders' AND p.method='pix'"
        );
        $statement->execute([$code, (int) $paymentId]);
        try {
            $id = (int) $statement->fetchColumn();
            if ($id < 1) {
                throw new RuntimeException('Pagamento Pix não encontrado neste pedido.');
            }
            (new PagarmePixRefundService())->refundFull($id);
            Session::flash('success', 'Solicitação de estorno integral enviada. A confirmação final virá da Pagar.me.');
        } catch (Throwable $exception) {
            Session::flash('error', $exception->getMessage());
        }
        return Response::redirect('/admin/pedidos/' . rawurlencode($code));
    }
}
