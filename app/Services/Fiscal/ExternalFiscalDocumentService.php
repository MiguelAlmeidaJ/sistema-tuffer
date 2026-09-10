<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class ExternalFiscalDocumentService
{
    private readonly PDO $pdo;
    private readonly FiscalOrchestratorService $orchestrator;
    private readonly FiscalDocumentStorage $storage;

    public function __construct(?PDO $pdo = null, ?FiscalOrchestratorService $orchestrator = null, ?FiscalDocumentStorage $storage = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->orchestrator = $orchestrator ?? new FiscalOrchestratorService($this->pdo);
        $this->storage = $storage ?? new FiscalDocumentStorage();
    }

    /** @return array<string,mixed> */
    public function document(int $sellerOrderId): array
    {
        return $this->orchestrator->syncSellerOrder($sellerOrderId);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function authorize(int $sellerOrderId, array $data): array
    {
        $number=(int)($data['number']??0);$series=(int)($data['series']??0);
        $accessKey=preg_replace('/\D+/','',(string)($data['access_key']??''))??'';
        $protocol=mb_substr(trim((string)($data['protocol']??'')),0,100);
        $externalReference=mb_substr(trim((string)($data['external_reference']??'')),0,150);
        $xml=isset($data['xml'])&&is_string($data['xml'])&&$data['xml']!==''?$data['xml']:null;
        $danfe=isset($data['danfe'])&&is_string($data['danfe'])&&$data['danfe']!==''?$data['danfe']:null;
        if($number<1)throw new RuntimeException('Informe o número da NF-e emitida.');
        if($series<1||$series>999)throw new RuntimeException('A série da NF-e deve estar entre 1 e 999.');
        if(!preg_match('/^\d{44}$/',$accessKey))throw new RuntimeException('A chave de acesso da NF-e deve possuir 44 dígitos.');

        $this->document($sellerOrderId);$this->pdo->beginTransaction();
        try{
            $document=$this->lockedDocument($sellerOrderId);$this->assertSupportedMode($document);
            if((string)$document['status']==='cancelled')throw new RuntimeException('A NF-e deste pedido já está cancelada e não pode ser substituída.');
            if((string)$document['status']==='authorized'){
                $same=(int)($document['number']??0)===$number&&(int)($document['series']??0)===$series&&hash_equals((string)($document['access_key']??''),$accessKey);
                if(!$same){$this->event((int)$document['id'],'outside_document_conflict','authorized','Tentativa bloqueada de substituir uma NF-e já autorizada.',['incoming_number'=>$number,'incoming_series'=>$series,'incoming_access_key'=>$accessKey]);$this->pdo->commit();throw new RuntimeException('Já existe outra NF-e autorizada para este pedido da loja. A substituição foi bloqueada.');}
                if($xml!==null||$danfe!==null){$paths=$this->storage->store((int)$document['seller_id'],(int)$document['id'],$accessKey,$xml,$danfe);$this->pdo->prepare('UPDATE fiscal_documents SET xml_storage_path=COALESCE(?,xml_storage_path),danfe_storage_path=COALESCE(?,danfe_storage_path) WHERE id=?')->execute([$paths['xml_storage_path'],$paths['danfe_storage_path'],$document['id']]);$this->event((int)$document['id'],'outside_files_attached','authorized','Arquivos fiscais privados vinculados ao documento.',['xml_attached'=>$xml!==null,'danfe_attached'=>$danfe!==null]);}
                $id=(int)$document['id'];$this->pdo->commit();return $this->fetchDocument($id);
            }
            $duplicate=$this->pdo->prepare('SELECT id FROM fiscal_documents WHERE access_key=? AND id<>? LIMIT 1 FOR UPDATE');$duplicate->execute([$accessKey,$document['id']]);
            if((int)$duplicate->fetchColumn()>0){$this->event((int)$document['id'],'outside_document_conflict',(string)$document['status'],'Tentativa bloqueada de reutilizar chave de acesso vinculada a outro documento.',['incoming_access_key'=>$accessKey]);$this->pdo->commit();throw new RuntimeException('Esta chave de acesso já está vinculada a outro pedido. A substituição foi bloqueada.');}
            $paths=$this->storage->store((int)$document['seller_id'],(int)$document['id'],$accessKey,$xml,$danfe);
            $this->pdo->prepare("UPDATE fiscal_documents SET status='authorized',number=?,series=?,access_key=?,protocol=?,external_reference=?,xml_storage_path=COALESCE(?,xml_storage_path),danfe_storage_path=COALESCE(?,danfe_storage_path),requires_action=0,action_reason=NULL,error_code=NULL,error_message=NULL,authorized_at=COALESCE(authorized_at,NOW()) WHERE id=?")
                ->execute([$number,$series,$accessKey,$protocol!==''?$protocol:null,$externalReference!==''?$externalReference:null,$paths['xml_storage_path'],$paths['danfe_storage_path'],$document['id']]);
            $mode=FiscalIssuanceMode::normalize((string)($document['issuance_mode']??'manual'));
            $eventType=match($mode){FiscalIssuanceMode::MANUAL=>'manual_document_registered',FiscalIssuanceMode::CONNECTOR=>'connector_document_registered',default=>'external_document_registered'};
            $this->event((int)$document['id'],$eventType,'authorized','NF-e emitida pelo sistema da loja foi vinculada ao pedido.',['number'=>$number,'series'=>$series,'access_key'=>$accessKey,'external_reference'=>$externalReference,'xml_attached'=>$xml!==null,'danfe_attached'=>$danfe!==null]);
            $id=(int)$document['id'];$this->pdo->commit();return $this->fetchDocument($id);
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** @return array<string,mixed> */
    public function attach(int $sellerOrderId,?string $xml,?string $danfe):array
    {
        if($xml===null&&$danfe===null)throw new RuntimeException('Selecione ao menos o XML ou o DANFE.');
        $this->document($sellerOrderId);$this->pdo->beginTransaction();
        try{$document=$this->lockedDocument($sellerOrderId);$this->assertSupportedMode($document);if(!in_array((string)$document['status'],['authorized','cancelled'],true))throw new RuntimeException('Autorize a NF-e antes de anexar XML ou DANFE.');$paths=$this->storage->store((int)$document['seller_id'],(int)$document['id'],(string)($document['access_key']??''),$xml,$danfe);$this->pdo->prepare('UPDATE fiscal_documents SET xml_storage_path=COALESCE(?,xml_storage_path),danfe_storage_path=COALESCE(?,danfe_storage_path) WHERE id=?')->execute([$paths['xml_storage_path'],$paths['danfe_storage_path'],$document['id']]);$this->event((int)$document['id'],'outside_files_attached',(string)$document['status'],'Arquivos fiscais privados vinculados ao documento.',['xml_attached'=>$xml!==null,'danfe_attached'=>$danfe!==null]);$id=(int)$document['id'];$this->pdo->commit();return $this->fetchDocument($id);}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function cancel(int $sellerOrderId,array $data):array
    {
        $reason=trim((string)($data['reason']??''));$protocol=mb_substr(trim((string)($data['cancellation_protocol']??'')),0,100);$externalReference=mb_substr(trim((string)($data['external_reference']??'')),0,150);
        if(mb_strlen($reason)<15||mb_strlen($reason)>1000)throw new RuntimeException('Informe o motivo do cancelamento com 15 a 1000 caracteres.');
        $this->document($sellerOrderId);$this->pdo->beginTransaction();
        try{$document=$this->lockedDocument($sellerOrderId);$this->assertSupportedMode($document);if((string)$document['status']==='cancelled'){$this->pdo->commit();return $document;}if((string)$document['status']!=='authorized')throw new RuntimeException('Somente uma NF-e autorizada pode ser marcada como cancelada.');$this->pdo->prepare("UPDATE fiscal_documents SET status='cancelled',cancellation_protocol=?,cancellation_reason=?,external_reference=COALESCE(?,external_reference),requires_action=0,action_reason=NULL,cancelled_at=COALESCE(cancelled_at,NOW()) WHERE id=?")->execute([$protocol!==''?$protocol:null,$reason,$externalReference!==''?$externalReference:null,$document['id']]);$mode=FiscalIssuanceMode::normalize((string)($document['issuance_mode']??'manual'));$eventType=match($mode){FiscalIssuanceMode::MANUAL=>'manual_cancelled',FiscalIssuanceMode::CONNECTOR=>'connector_cancelled',default=>'external_cancelled'};$this->event((int)$document['id'],$eventType,'cancelled','Cancelamento da NF-e registrado na Tuffer.',['cancellation_protocol'=>$protocol,'external_reference'=>$externalReference]);$id=(int)$document['id'];$this->pdo->commit();return $this->fetchDocument($id);}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** @param array<string,mixed> $document */
    private function assertSupportedMode(array $document):void
    {
        $mode=FiscalIssuanceMode::normalize((string)($document['issuance_mode']??'manual'));
        if(!in_array($mode,[FiscalIssuanceMode::MANUAL,FiscalIssuanceMode::EXTERNAL,FiscalIssuanceMode::CONNECTOR],true))throw new RuntimeException('Modo fiscal não suporta vínculo de documento externo.');
    }

    /** @return array<string,mixed> */
    private function lockedDocument(int $sellerOrderId):array{$stmt=$this->pdo->prepare("SELECT * FROM fiscal_documents WHERE seller_order_id=? AND document_type='nfe' AND revision=1 LIMIT 1 FOR UPDATE");$stmt->execute([$sellerOrderId]);$row=$stmt->fetch();if(!is_array($row))throw new RuntimeException('Documento fiscal não encontrado.');return $row;}
    /** @return array<string,mixed> */
    private function fetchDocument(int $id):array{$stmt=$this->pdo->prepare('SELECT * FROM fiscal_documents WHERE id=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();if(!is_array($row))throw new RuntimeException('Documento fiscal não encontrado.');return $row;}
    /** @param array<string,mixed> $payload */
    private function event(int $documentId,string $type,string $status,string $message,array $payload=[]):void{foreach(['xml','danfe','certificate','password','token','secret','api_key'] as $key)unset($payload[$key]);$encoded=$payload===[]?null:json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$hash=$encoded===null?null:hash('sha256',$encoded);$this->pdo->prepare('INSERT INTO fiscal_events(fiscal_document_id,event_type,status,message,payload_sha256) VALUES(?,?,?,?,?)')->execute([$documentId,mb_substr($type,0,60),mb_substr($status,0,30),mb_substr($message,0,1000),$hash]);}
}
