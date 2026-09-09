<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class FiscalDocumentService
{
    private readonly PDO $pdo;
    private readonly FiscalConfiguration $configuration;
    private readonly FiscalProfileValidator $validator;
    private readonly FiscalProvider $provider;
    private readonly FiscalDocumentStorage $storage;

    public function __construct(?PDO $pdo=null, ?FiscalConfiguration $configuration=null, ?FiscalProfileValidator $validator=null, ?FiscalProvider $provider=null, ?FiscalDocumentStorage $storage=null)
    {
        $this->pdo=$pdo??Database::connection();
        $this->configuration=$configuration??new FiscalConfiguration();
        $this->validator=$validator??new FiscalProfileValidator();
        $this->provider=$provider??FiscalProviderFactory::make($this->configuration);
        $this->storage=$storage??new FiscalDocumentStorage($this->configuration);
    }

    public function syncPaidOrder(int $orderId): void
    {
        if($orderId<1)return;
        $s=$this->pdo->prepare("SELECT id FROM seller_orders WHERE order_id=? AND status IN ('paid','processing','shipped','delivered') ORDER BY id");$s->execute([$orderId]);
        foreach($s->fetchAll(PDO::FETCH_COLUMN) as $sellerOrderId)$this->syncSellerOrder((int)$sellerOrderId);
    }

    /** @return array<string,mixed> */
    public function syncSellerOrder(int $sellerOrderId): array
    {
        $context=$this->sellerOrderContext($sellerOrderId);
        if(!in_array((string)$context['seller_order_status'],['paid','processing','shipped','delivered'],true))throw new RuntimeException('O pedido da loja ainda não está apto para processamento fiscal.');
        $profile=$this->sellerProfile((int)$context['seller_id']);$items=$this->items($sellerOrderId);$document=$this->findOrCreateDocument($context,$profile);
        if(in_array((string)$document['status'],['authorized','cancelled','voided'],true))return $document;
        $issuerState=mb_strtoupper(trim((string)($profile['state']??'')));$destinationState=mb_strtoupper(trim((string)($context['destination_state']??'')));
        $errors=$this->validator->sellerErrors($context,$profile);
        if(!preg_match('/^\d{7}$/',trim((string)($context['destination_city_ibge_code']??''))))$errors[]='Código IBGE do município do destinatário não informado ou inválido.';
        if($destinationState===''||!preg_match('/^[A-Z]{2}$/',$destinationState))$errors[]='UF do destinatário não informada ou inválida.';
        if($items===[])$errors[]='Pedido sem itens disponíveis para emissão fiscal.';
        foreach($items as $item)$errors=array_merge($errors,$this->validator->itemErrors($item,$issuerState,$destinationState,$this->configuration->rtcRequired()));
        $errors=array_values(array_unique($errors));
        $issuer=$this->issuerSnapshot($context,$profile);$recipient=$this->recipientSnapshot($context);$taxContext=['issuer_state'=>$issuerState,'destination_state'=>$destinationState,'interstate'=>$issuerState!==''&&$destinationState!==''&&$issuerState!==$destinationState,'rtc_required'=>$this->configuration->rtcRequired()];
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('UPDATE fiscal_documents SET provider=?,environment=?,series=?,products_total=?,shipping_total=?,discount_total=?,grand_total=?,issuer_snapshot=?,recipient_snapshot=?,tax_context=?,validation_errors=?,status=?,requires_action=?,action_reason=?,error_code=NULL,error_message=NULL WHERE id=?')->execute([$this->configuration->provider(),$this->configuration->environment(),(int)($profile['nfe_series']??1),$context['products_total'],$context['shipping_total'],$context['discount_total'],$this->grand($context),$this->json($issuer),$this->json($recipient),$this->json($taxContext),$errors===[]?null:$this->json($errors),$errors===[]?'ready':'validation_failed',$errors===[]?0:1,$errors===[]?null:'Cadastro fiscal incompleto. Corrija as pendências antes da emissão.',$document['id']]);
            $this->replaceItems((int)$document['id'],$items,$issuerState,$destinationState);
            $this->event((int)$document['id'],$errors===[]?'validation_passed':'validation_failed',$errors===[]?'ready':'validation_failed',$errors===[]?'Documento fiscal pronto para emissão.':implode(' | ',array_slice($errors,0,8)),['errors'=>$errors]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $document=$this->document((int)$document['id']);
        if($errors!==[]||!$this->configuration->autoIssue())return $document;
        if(!$this->provider->configured()){
            $this->pdo->prepare("UPDATE fiscal_documents SET requires_action=1,action_reason='Emissão automática habilitada, mas o provedor fiscal não está configurado.' WHERE id=?")->execute([$document['id']]);
            $this->event((int)$document['id'],'provider_unavailable','ready','Provedor fiscal não configurado.',['provider'=>$this->provider->name()]);
            return $this->document((int)$document['id']);
        }
        return $this->issueDocument((int)$document['id']);
    }

    /** @return array<string,mixed> */
    public function issueDocument(int $documentId): array
    {
        $document=$this->document($documentId);
        if((string)$document['status']!=='ready')throw new RuntimeException('Somente documentos fiscais prontos podem ser enviados para emissão.');
        if(!$this->provider->configured())throw new RuntimeException('O provedor fiscal selecionado não está configurado.');
        $payload=$document;$payload['issuer']=$this->decodeJson($document['issuer_snapshot']??null);$payload['recipient']=$this->decodeJson($document['recipient_snapshot']??null);$payload['tax_context']=$this->decodeJson($document['tax_context']??null);$payload['items']=$this->documentItems($documentId);
        $this->pdo->prepare("UPDATE fiscal_documents SET status='submitting',provider=?,requires_action=0,action_reason=NULL WHERE id=? AND status='ready'")->execute([$this->provider->name(),$documentId]);
        $this->event($documentId,'submission_started','submitting','Documento enviado ao provedor fiscal.',['provider'=>$this->provider->name()]);
        try{
            $response=$this->provider->issue($payload);$status=(string)($response['status']??'processing');if(!in_array($status,['processing','authorized','rejected'],true))$status='processing';
            $paths=['xml_storage_path'=>null,'danfe_storage_path'=>null];
            if($status==='authorized')$paths=$this->storage->store((int)$document['seller_id'],$documentId,isset($response['access_key'])?(string)$response['access_key']:null,isset($response['xml'])?(string)$response['xml']:null,isset($response['danfe'])?(string)$response['danfe']:null);
            $this->pdo->prepare('UPDATE fiscal_documents SET status=?,provider_document_id=?,number=?,series=COALESCE(?,series),access_key=?,protocol=?,xml_storage_path=?,danfe_storage_path=?,error_code=?,error_message=?,requires_action=?,action_reason=?,authorized_at=? WHERE id=?')->execute([$status,$response['provider_document_id']??null,$response['number']??null,$response['series']??null,$response['access_key']??null,$response['protocol']??null,$paths['xml_storage_path'],$paths['danfe_storage_path'],$response['error_code']??null,$response['error_message']??null,$status==='rejected'?1:0,$status==='rejected'?'NF-e rejeitada pelo provedor/SEFAZ. Revise a rejeição antes de reenviar.':null,$status==='authorized'?date('Y-m-d H:i:s'):null,$documentId]);
            $this->event($documentId,$status==='authorized'?'authorized':($status==='rejected'?'rejected':'submitted'),$status,isset($response['error_message'])?(string)$response['error_message']:'Resposta recebida do provedor fiscal.',$response,isset($response['provider_event_id'])?(string)$response['provider_event_id']:null,isset($response['error_code'])?(string)$response['error_code']:null);
        }catch(Throwable $e){$this->pdo->prepare("UPDATE fiscal_documents SET status='error',requires_action=1,action_reason='Falha ao comunicar com o provedor fiscal.',error_message=? WHERE id=?")->execute([mb_substr($e->getMessage(),0,1000),$documentId]);$this->event($documentId,'submission_error','error',$e->getMessage(),['exception'=>$e::class]);throw $e;}
        return $this->document($documentId);
    }

    public function reviewRefund(int $orderId,bool $fullRefund): void
    {
        $s=$this->pdo->prepare('SELECT fd.* FROM fiscal_documents fd JOIN seller_orders so ON so.id=fd.seller_order_id WHERE so.order_id=?');$s->execute([$orderId]);
        foreach($s->fetchAll() as $document){$id=(int)$document['id'];$status=(string)$document['status'];
            if($status==='authorized'){$this->pdo->prepare('UPDATE fiscal_documents SET requires_action=1,action_reason=? WHERE id=?')->execute([$fullRefund?'Pedido reembolsado: avaliar cancelamento da NF-e ou documento fiscal de devolução.':'Pedido parcialmente reembolsado: avaliar ajuste/devolução fiscal.',$id]);$this->event($id,'refund_review_required',$status,'Reembolso exige revisão fiscal; nenhum cancelamento automático foi executado.',['full_refund'=>$fullRefund]);continue;}
            if($fullRefund&&in_array($status,['pending','validation_failed','ready','error'],true)){$this->pdo->prepare("UPDATE fiscal_documents SET status='voided',requires_action=0,action_reason=NULL WHERE id=?")->execute([$id]);$this->event($id,'voided_after_refund','voided','Documento não autorizado descartado após reembolso integral.',['full_refund'=>true]);}
            elseif(!$fullRefund&&!in_array($status,['cancelled','voided'],true)){$this->pdo->prepare("UPDATE fiscal_documents SET requires_action=1,action_reason='Reembolso parcial exige revisão fiscal manual.' WHERE id=?")->execute([$id]);$this->event($id,'partial_refund_review_required',$status,'Reembolso parcial exige revisão fiscal manual.',['full_refund'=>false]);}
        }
    }

    /** @return array<string,mixed> */
    private function sellerOrderContext(int $id): array
    {
        $s=$this->pdo->prepare("SELECT so.id seller_order_id,so.order_id,so.seller_id,so.store_id,so.code seller_order_code,so.status seller_order_status,so.products_total,so.shipping_total,so.discount_total,so.seller_net_total,o.code order_code,o.status order_status,o.user_id,s.legal_name,s.trade_name,s.document,s.state_registration,st.name store_name,u.name customer_name,u.email customer_email,u.phone customer_phone,u.document customer_document,oa.recipient_name,oa.postal_code destination_postal_code,oa.street destination_street,oa.number destination_number,oa.complement destination_complement,oa.neighborhood destination_neighborhood,oa.city destination_city,COALESCE(oa.city_ibge_code,ua.city_ibge_code) destination_city_ibge_code,oa.state destination_state FROM seller_orders so JOIN orders o ON o.id=so.order_id JOIN sellers s ON s.id=so.seller_id JOIN stores st ON st.id=so.store_id JOIN users u ON u.id=o.user_id LEFT JOIN order_addresses oa ON oa.order_id=o.id LEFT JOIN user_addresses ua ON ua.user_id=o.user_id AND ua.postal_code=oa.postal_code AND ua.street=oa.street AND ua.number=oa.number AND ua.city=oa.city AND ua.state=oa.state WHERE so.id=? LIMIT 1");$s->execute([$id]);$row=$s->fetch();if(!is_array($row))throw new RuntimeException('Pedido da loja não encontrado para processamento fiscal.');return $row;
    }

    private function sellerProfile(int $sellerId): ?array{$s=$this->pdo->prepare('SELECT * FROM seller_fiscal_profiles WHERE seller_id=? LIMIT 1');$s->execute([$sellerId]);$r=$s->fetch();return is_array($r)?$r:null;}

    /** @return array<int,array<string,mixed>> */
    private function items(int $sellerOrderId): array{$s=$this->pdo->prepare('SELECT oi.id order_item_id,oi.product_id,oi.product_variant_id,oi.product_name,oi.sku,oi.quantity,oi.unit_price,oi.total,COALESCE(pfp.gtin,pv.barcode) gtin,pfp.ncm,pfp.cest,pfp.origin,pfp.commercial_unit,pfp.tributary_unit,pfp.cfop_in_state,pfp.cfop_out_state,pfp.icms_code,pfp.pis_code,pfp.cofins_code,pfp.ipi_code,pfp.ibs_cbs_cst,pfp.ibs_cbs_classification,pfp.tax_metadata FROM order_items oi LEFT JOIN product_variants pv ON pv.id=oi.product_variant_id LEFT JOIN product_fiscal_profiles pfp ON pfp.product_id=oi.product_id WHERE oi.seller_order_id=? ORDER BY oi.id');$s->execute([$sellerOrderId]);return $s->fetchAll();}

    /** @param array<string,mixed> $context @param array<string,mixed>|null $profile @return array<string,mixed> */
    private function findOrCreateDocument(array $context,?array $profile): array
    {
        $s=$this->pdo->prepare("SELECT * FROM fiscal_documents WHERE seller_order_id=? AND document_type='nfe' AND revision=1 LIMIT 1");$s->execute([$context['seller_order_id']]);$r=$s->fetch();if(is_array($r))return $r;
        $key='nfe-sale:'.(int)$context['seller_order_id'].':1';$this->pdo->prepare("INSERT INTO fiscal_documents(seller_order_id,seller_id,store_id,document_type,operation_type,revision,provider,environment,idempotency_key,status,series,products_total,shipping_total,discount_total,grand_total) VALUES(?,?,?,'nfe','sale',1,?,?,?,'pending',?,?,?,?,?)")->execute([$context['seller_order_id'],$context['seller_id'],$context['store_id'],$this->configuration->provider(),$this->configuration->environment(),$key,(int)($profile['nfe_series']??1),$context['products_total'],$context['shipping_total'],$context['discount_total'],$this->grand($context)]);$id=(int)$this->pdo->lastInsertId();$this->event($id,'created','pending','Documento fiscal criado a partir do pedido pago.',['seller_order_id'=>(int)$context['seller_order_id']]);return $this->document($id);
    }

    /** @param array<int,array<string,mixed>> $items */
    private function replaceItems(int $documentId,array $items,string $issuerState,string $destinationState): void
    {
        $this->pdo->prepare('DELETE FROM fiscal_document_items WHERE fiscal_document_id=?')->execute([$documentId]);$same=$issuerState!==''&&$issuerState===$destinationState;
        $insert=$this->pdo->prepare('INSERT INTO fiscal_document_items(fiscal_document_id,order_item_id,product_id,product_variant_id,product_name,sku,quantity,unit_price,total,ncm,cest,origin,commercial_unit,tributary_unit,gtin,cfop_in_state,cfop_out_state,applied_cfop,icms_code,pis_code,cofins_code,ipi_code,ibs_cbs_cst,ibs_cbs_classification,tax_metadata) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($items as $item){$meta=$item['tax_metadata']??null;if(is_array($meta))$meta=$this->json($meta);$insert->execute([$documentId,$item['order_item_id'],$item['product_id'],$item['product_variant_id'],$item['product_name'],$item['sku'],$item['quantity'],$item['unit_price'],$item['total'],$item['ncm']?:null,$item['cest']?:null,$item['origin'],$item['commercial_unit']?:null,$item['tributary_unit']?:null,$item['gtin']?:null,$item['cfop_in_state']?:null,$item['cfop_out_state']?:null,$item[$same?'cfop_in_state':'cfop_out_state']?:null,$item['icms_code']?:null,$item['pis_code']?:null,$item['cofins_code']?:null,$item['ipi_code']?:null,$item['ibs_cbs_cst']?:null,$item['ibs_cbs_classification']?:null,$meta]);}
    }

    private function issuerSnapshot(array $c,?array $p): array{return ['seller_id'=>(int)$c['seller_id'],'store_id'=>(int)$c['store_id'],'legal_name'=>$c['legal_name'],'trade_name'=>$c['trade_name'],'document'=>$c['document'],'state_registration'=>$c['state_registration'],'state_registration_indicator'=>$p['state_registration_indicator']??'contributor','municipal_registration'=>$p['municipal_registration']??null,'tax_regime'=>$p['tax_regime']??null,'crt'=>$p['crt']??null,'fiscal_email'=>$p['fiscal_email']??null,'address'=>['postal_code'=>$p['postal_code']??null,'street'=>$p['street']??null,'number'=>$p['number']??null,'complement'=>$p['complement']??null,'neighborhood'=>$p['neighborhood']??null,'city'=>$p['city']??null,'city_ibge_code'=>$p['city_ibge_code']??null,'state'=>$p['state']??null]];}
    private function recipientSnapshot(array $c): array{return ['user_id'=>(int)$c['user_id'],'name'=>$c['recipient_name']?:$c['customer_name'],'document'=>$c['customer_document'],'email'=>$c['customer_email'],'phone'=>$c['customer_phone'],'address'=>['postal_code'=>$c['destination_postal_code'],'street'=>$c['destination_street'],'number'=>$c['destination_number'],'complement'=>$c['destination_complement'],'neighborhood'=>$c['destination_neighborhood'],'city'=>$c['destination_city'],'city_ibge_code'=>$c['destination_city_ibge_code'],'state'=>$c['destination_state']]];}
    private function document(int $id): array{$s=$this->pdo->prepare('SELECT * FROM fiscal_documents WHERE id=? LIMIT 1');$s->execute([$id]);$r=$s->fetch();if(!is_array($r))throw new RuntimeException('Documento fiscal não encontrado.');return $r;}
    private function documentItems(int $id): array{$s=$this->pdo->prepare('SELECT * FROM fiscal_document_items WHERE fiscal_document_id=? ORDER BY id');$s->execute([$id]);return $s->fetchAll();}
    private function grand(array $c): string{return number_format(max(0,(float)$c['products_total']+(float)$c['shipping_total']-(float)$c['discount_total']),2,'.','');}
    private function event(int $documentId,string $type,string $status,?string $message,array $payload=[],?string $providerEventId=null,?string $code=null): void{$encoded=$payload===[]?null:$this->json($this->sanitized($payload));$hash=$encoded===null?null:hash('sha256',$encoded);$this->pdo->prepare('INSERT INTO fiscal_events(fiscal_document_id,event_type,status,provider_event_id,code,message,payload_sha256) VALUES(?,?,?,?,?,?,?)')->execute([$documentId,mb_substr($type,0,60),mb_substr($status,0,30),$providerEventId?:null,$code?:null,$message===null?null:mb_substr($message,0,1000),$hash]);}
    private function sanitized(array $payload): array{foreach(['xml','danfe','certificate','password','token','secret','api_key'] as $key)unset($payload[$key]);return $payload;}
    private function decodeJson(mixed $value): array{if(is_array($value))return $value;if(!is_string($value)||trim($value)==='')return[];$d=json_decode($value,true);return is_array($d)?$d:[];}
    private function json(mixed $value): string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
