<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Database;
use App\Services\Queue\JobQueue;
use PDO;
use RuntimeException;
use Throwable;

final class TinyFiscalConnectorService
{
    public const PROVIDER = 'tiny';

    public function __construct(
        private readonly ?PDO $database = null,
        private readonly ?FiscalConnectorCredentialService $vault = null,
    ) {}

    /** @return array<string,mixed>|null */
    public function configuration(int $storeId, int $sellerId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT id,store_id,seller_id,provider,credential_prefix,enabled,auto_emit,send_email,account_name,account_document,settings,last_test_at,last_success_at,last_failure_at,last_error,created_at,updated_at FROM store_fiscal_connector_configs WHERE store_id=? AND seller_id=? AND provider=? LIMIT 1');
        $stmt->execute([$storeId,$sellerId,self::PROVIDER]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    public function connect(int $storeId, int $sellerId, string $token, bool $autoEmit = true, bool $sendEmail = false): array
    {
        $token = trim($token);
        if ($token === '') throw new RuntimeException('Informe o token da API Tiny.');
        $this->assertStoreProfile($storeId,$sellerId);

        $client = new TinyApiClient($token);
        $account = $client->account();
        $accountName = mb_substr(trim((string)($account['razao_social'] ?? $account['fantasia'] ?? '')),0,120);
        $accountDocument = mb_substr(trim((string)($account['cnpj_cpf'] ?? '')),0,40);
        $ciphertext = $this->credentialVault()->encrypt(['token'=>$token]);
        $prefix = $this->credentialVault()->prefix($token);
        $pdo = $this->pdo();

        $pdo->prepare("INSERT INTO store_fiscal_connector_configs(store_id,seller_id,provider,credentials_ciphertext,credential_prefix,enabled,auto_emit,send_email,account_name,account_document,last_test_at,last_success_at,last_error)
            VALUES(?,?,?,?,?,1,?,?,?,?,?,NOW(),NOW(),NULL)
            ON DUPLICATE KEY UPDATE seller_id=VALUES(seller_id),credentials_ciphertext=VALUES(credentials_ciphertext),credential_prefix=VALUES(credential_prefix),enabled=1,auto_emit=VALUES(auto_emit),send_email=VALUES(send_email),account_name=VALUES(account_name),account_document=VALUES(account_document),last_test_at=NOW(),last_success_at=NOW(),last_error=NULL")
            ->execute([$storeId,$sellerId,self::PROVIDER,$ciphertext,$prefix,$autoEmit?1:0,$sendEmail?1:0,$accountName!==''?$accountName:null,$accountDocument!==''?$accountDocument:null]);

        $pdo->prepare("UPDATE store_fiscal_profiles SET enabled=1,issuance_mode='connector',provider='tiny' WHERE store_id=? AND seller_id=?")
            ->execute([$storeId,$sellerId]);

        $this->enqueueEligibleStoreOrders($storeId,$sellerId);
        return $this->configuration($storeId,$sellerId) ?? [];
    }

    /** @return array<string,mixed> */
    public function test(int $storeId, int $sellerId): array
    {
        $config = $this->configurationWithSecret($storeId,$sellerId);
        $account = $this->client($config)->account();
        $this->pdo()->prepare('UPDATE store_fiscal_connector_configs SET last_test_at=NOW(),last_success_at=NOW(),last_error=NULL WHERE id=?')->execute([$config['id']]);
        return $account;
    }

    public function disconnect(int $storeId, int $sellerId): void
    {
        $this->pdo()->prepare('UPDATE store_fiscal_connector_configs SET enabled=0 WHERE store_id=? AND seller_id=? AND provider=?')->execute([$storeId,$sellerId,self::PROVIDER]);
        $this->pdo()->prepare("UPDATE store_fiscal_profiles SET issuance_mode='manual',provider='manual' WHERE store_id=? AND seller_id=? AND provider='tiny'")->execute([$storeId,$sellerId]);
    }

    public function enqueue(int $sellerOrderId): ?int
    {
        if ($sellerOrderId < 1) return null;
        $pdo = $this->pdo();
        $stmt = $pdo->prepare("SELECT so.id,so.store_id,so.seller_id,fd.id fiscal_document_id,fd.status,sfp.enabled profile_enabled,sfp.issuance_mode,sfp.provider,c.enabled connector_enabled
            FROM seller_orders so
            JOIN fiscal_documents fd ON fd.seller_order_id=so.id AND fd.document_type='nfe' AND fd.revision=1
            JOIN store_fiscal_profiles sfp ON sfp.store_id=so.store_id AND sfp.seller_id=so.seller_id
            LEFT JOIN store_fiscal_connector_configs c ON c.store_id=so.store_id AND c.seller_id=so.seller_id AND c.provider='tiny'
            WHERE so.id=? LIMIT 1");
        $stmt->execute([$sellerOrderId]);
        $row = $stmt->fetch();
        if (!is_array($row) || !(bool)$row['profile_enabled'] || (string)$row['issuance_mode'] !== FiscalIssuanceMode::CONNECTOR || (string)$row['provider'] !== self::PROVIDER || !(bool)($row['connector_enabled'] ?? false)) return null;
        if (in_array((string)$row['status'], ['authorized','cancelled','voided'], true)) return null;

        $pdo->prepare("INSERT INTO fiscal_connector_runs(seller_order_id,fiscal_document_id,store_id,seller_id,provider,status)
            VALUES(?,?,?,?,?,'pending') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),fiscal_document_id=VALUES(fiscal_document_id),status=IF(status IN ('completed','rejected'),'status',status)")
            ->execute([$sellerOrderId,$row['fiscal_document_id'],$row['store_id'],$row['seller_id'],self::PROVIDER]);
        $runId = (int)$pdo->lastInsertId();
        if ($runId < 1) {
            $find=$pdo->prepare('SELECT id FROM fiscal_connector_runs WHERE seller_order_id=? AND provider=? LIMIT 1');$find->execute([$sellerOrderId,self::PROVIDER]);$runId=(int)$find->fetchColumn();
        }
        if ($runId < 1) throw new RuntimeException('Não foi possível preparar a sincronização com Tiny.');
        (new JobQueue($pdo))->dispatch('fiscal.process_connector',['run_id'=>$runId],'fiscal-connector:tiny:'.$sellerOrderId,'fiscal',10,45);
        return $runId;
    }

    public function process(int $runId): void
    {
        if ($runId < 1) throw new RuntimeException('Execução do conector Tiny inválida.');
        $pdo=$this->pdo();
        $run=$this->run($runId);
        if ((string)$run['status']==='completed') return;
        $config=$this->configurationWithSecret((int)$run['store_id'],(int)$run['seller_id']);
        $client=$this->client($config);
        $pdo->prepare("UPDATE fiscal_connector_runs SET status='processing',attempts=attempts+1,started_at=COALESCE(started_at,NOW()),last_error=NULL WHERE id=?")->execute([$runId]);
        try {
            $payload=(new FiscalSellerOrderPayloadService($pdo))->payload((int)$run['seller_order_id']);
            $code=(string)$payload['seller_order']['code'];

            $providerOrderId=(int)($run['provider_order_id'] ?? 0);
            $providerOrderNumber=(string)($run['provider_order_number'] ?? '');
            if ($providerOrderId < 1) {
                $existing=$client->findOrderByEcommerce($code);
                $created=$existing ?? $client->createOrder($this->buildOrder($payload));
                $providerOrderId=(int)($created['id'] ?? 0);
                $providerOrderNumber=(string)($created['numero'] ?? '');
                if ($providerOrderId < 1) throw new RuntimeException('Tiny não retornou o ID do pedido para sincronização fiscal.');
                $pdo->prepare("UPDATE fiscal_connector_runs SET status='order_synced',provider_order_id=?,provider_order_number=? WHERE id=?")->execute([(string)$providerOrderId,$providerOrderNumber!==''?$providerOrderNumber:null,$runId]);
            }

            $client->approveOrder($providerOrderId);

            $providerDocumentId=(int)($run['provider_document_id'] ?? 0);
            $documentNumber=(string)($run['provider_document_number'] ?? '');
            $documentSeries=(string)($run['provider_document_series'] ?? '');
            if ($providerDocumentId < 1) {
                $existingInvoice=$client->findInvoiceByEcommerce($code);
                if (is_array($existingInvoice) && (int)($existingInvoice['id'] ?? 0)>0) {
                    $providerDocumentId=(int)$existingInvoice['id'];
                    $documentNumber=(string)($existingInvoice['numero'] ?? '');
                    $documentSeries=(string)($existingInvoice['serie'] ?? '');
                } else {
                    $generated=$client->generateInvoice($providerOrderId);
                    $providerDocumentId=(int)($generated['idNotaFiscal'] ?? 0);
                    $documentNumber=(string)($generated['numero'] ?? '');
                    $documentSeries=(string)($generated['serie'] ?? '');
                }
                if ($providerDocumentId < 1) throw new RuntimeException('Tiny não retornou o ID da NF-e gerada.');
                $pdo->prepare("UPDATE fiscal_connector_runs SET status='invoice_generated',provider_document_id=?,provider_document_number=?,provider_document_series=? WHERE id=?")
                    ->execute([(string)$providerDocumentId,$documentNumber!==''?$documentNumber:null,$documentSeries!==''?$documentSeries:null,$runId]);
            }

            $invoice = (bool)$config['auto_emit'] ? $client->emitInvoice($providerDocumentId,(bool)$config['send_email']) : $client->getInvoice($providerDocumentId);
            if ($documentNumber==='') $documentNumber=(string)($invoice['numero'] ?? '');
            if ($documentSeries==='') $documentSeries=(string)($invoice['serie'] ?? '');
            $providerStatus=(string)($invoice['descricao_situacao'] ?? $invoice['situacao'] ?? '');
            $situation=(int)($invoice['situacao'] ?? 0);
            $accessKey=preg_replace('/\D+/', '', (string)($invoice['chave_acesso'] ?? '')) ?? '';
            $xml=is_string($invoice['xml'] ?? null) && trim((string)$invoice['xml'])!=='' ? (string)$invoice['xml'] : null;
            $pdo->prepare('UPDATE fiscal_connector_runs SET provider_status=?,provider_document_number=COALESCE(NULLIF(?,\'\'),provider_document_number),provider_document_series=COALESCE(NULLIF(?,\'\'),provider_document_series) WHERE id=?')
                ->execute([$providerStatus,$documentNumber,$documentSeries,$runId]);

            if (in_array($situation,[5,10],true)) {
                $message='Tiny retornou NF-e '.($providerStatus!==''?$providerStatus:'rejeitada/denegada').'. Revise a nota no ERP da loja.';
                $pdo->prepare("UPDATE fiscal_connector_runs SET status='rejected',last_error=?,completed_at=NOW() WHERE id=?")->execute([$message,$runId]);
                $pdo->prepare("UPDATE fiscal_documents SET status='rejected',requires_action=1,action_reason=?,error_code='tiny_rejected',error_message=? WHERE id=?")->execute([$message,$message,$run['fiscal_document_id']]);
                $this->markFailure((int)$config['id'],$message);
                return;
            }

            $authorized = $situation===6 || ($accessKey!=='' && $xml!==null && (str_contains($xml,'<protNFe') || str_contains($xml,':protNFe')));
            if (!$authorized || !preg_match('/^\d{44}$/',$accessKey)) {
                $pdo->prepare("UPDATE fiscal_connector_runs SET status='awaiting_authorization' WHERE id=?")->execute([$runId]);
                $pdo->prepare("UPDATE fiscal_documents SET status='processing',requires_action=0,action_reason=NULL,error_code=NULL,error_message=NULL WHERE id=? AND status<>'authorized'")->execute([$run['fiscal_document_id']]);
                throw new RuntimeException('Tiny ainda está processando a autorização da NF-e.');
            }

            $number=(int)preg_replace('/\D+/', '', $documentNumber);
            $series=(int)preg_replace('/\D+/', '', $documentSeries);
            if ($number<1 || $series<1) {
                $detail=$client->getInvoice($providerDocumentId);
                $number=max(1,(int)preg_replace('/\D+/', '', (string)($detail['numero'] ?? $documentNumber)));
                $series=max(1,(int)preg_replace('/\D+/', '', (string)($detail['serie'] ?? $documentSeries)));
            }
            (new ExternalFiscalDocumentService($pdo))->authorize((int)$run['seller_order_id'],[
                'number'=>$number,'series'=>$series,'access_key'=>$accessKey,'xml'=>$xml,'external_reference'=>'tiny:nfe:'.$providerDocumentId,
            ]);
            $pdo->prepare("UPDATE fiscal_connector_runs SET status='completed',provider_status=?,last_error=NULL,completed_at=NOW() WHERE id=?")->execute([$providerStatus,$runId]);
            $pdo->prepare('UPDATE store_fiscal_connector_configs SET last_success_at=NOW(),last_error=NULL WHERE id=?')->execute([$config['id']]);
        } catch (Throwable $e) {
            $message=mb_substr($e->getMessage(),0,1000);
            $pdo->prepare("UPDATE fiscal_connector_runs SET last_error=? WHERE id=? AND status NOT IN ('completed','rejected')")->execute([$message,$runId]);
            $this->markFailure((int)$config['id'],$message);
            throw $e;
        }
    }

    public function enqueueEligibleStoreOrders(int $storeId, int $sellerId): void
    {
        $stmt=$this->pdo()->prepare("SELECT id FROM seller_orders WHERE store_id=? AND seller_id=? AND status IN ('paid','processing','shipped','delivered') ORDER BY id DESC LIMIT 100");
        $stmt->execute([$storeId,$sellerId]);
        $orchestrator=new FiscalOrchestratorService($this->pdo());
        foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $sellerOrderId){
            try{$orchestrator->syncSellerOrder((int)$sellerOrderId);$this->enqueue((int)$sellerOrderId);}catch(Throwable){}
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function buildOrder(array $payload): array
    {
        $recipient=$payload['recipient'];$address=$recipient['address'];$amounts=$payload['amounts'];$items=[];
        foreach($payload['items'] as $item){
            $unit=mb_substr((string)($item['fiscal']['commercial_unit'] ?? 'UN'),0,3) ?: 'UN';
            $items[]=['item'=>[
                'codigo'=>mb_substr((string)($item['sku'] ?? ''),0,60),
                'descricao'=>mb_substr((string)$item['name'],0,120),
                'unidade'=>$unit,
                'quantidade'=>(string)$item['quantity'],
                'valor_unitario'=>(string)$item['unit_price'],
            ]];
        }
        $document=trim((string)($recipient['document'] ?? ''));
        $digits=preg_replace('/\D+/', '', $document) ?? '';
        $order=[
            'data_pedido'=>date('d/m/Y'),
            'cliente'=>[
                'nome'=>mb_substr((string)$recipient['name'],0,30),
                'tipo_pessoa'=>strlen($digits)===14?'J':'F',
                'cpf_cnpj'=>$document,
                'endereco'=>mb_substr((string)($address['street'] ?? ''),0,50),
                'numero'=>mb_substr((string)($address['number'] ?? ''),0,10),
                'complemento'=>mb_substr((string)($address['complement'] ?? ''),0,50),
                'bairro'=>mb_substr((string)($address['neighborhood'] ?? ''),0,30),
                'cep'=>mb_substr((string)($address['postal_code'] ?? ''),0,10),
                'cidade'=>mb_substr((string)($address['city'] ?? ''),0,30),
                'uf'=>mb_substr((string)($address['state'] ?? ''),0,2),
                'pais'=>'Brasil',
                'fone'=>mb_substr((string)($recipient['phone'] ?? ''),0,40),
                'email'=>mb_substr((string)($recipient['email'] ?? ''),0,50),
                'atualizar_cliente'=>'S',
            ],
            'itens'=>$items,
            'valor_frete'=>(string)$amounts['shipping_total'],
            'valor_desconto'=>(string)$amounts['discount_total'],
            'situacao'=>'aprovado',
            'numero_pedido_ecommerce'=>mb_substr((string)$payload['seller_order']['code'],0,50),
            'ecommerce'=>'Tuffer',
            'obs_internas'=>mb_substr('Pedido marketplace Tuffer '.$payload['seller_order']['order_code'],0,100),
        ];
        $intermediaryName=trim((string)($_ENV['FISCAL_INTERMEDIARY_NAME'] ?? ''));
        $intermediaryDocument=trim((string)($_ENV['FISCAL_INTERMEDIARY_DOCUMENT'] ?? ''));
        if($intermediaryName!=='' && $intermediaryDocument!==''){
            $order['intermediador']=['nome'=>mb_substr($intermediaryName,0,60),'cnpj'=>mb_substr($intermediaryDocument,0,18)];
            $paymentDocument=trim((string)($_ENV['FISCAL_PAYMENT_INTERMEDIARY_DOCUMENT'] ?? ''));
            if($paymentDocument!=='')$order['intermediador']['cnpjPagamento']=mb_substr($paymentDocument,0,18);
        }
        return $order;
    }

    /** @return array<string,mixed> */
    private function configurationWithSecret(int $storeId,int $sellerId): array
    {
        $stmt=$this->pdo()->prepare("SELECT * FROM store_fiscal_connector_configs WHERE store_id=? AND seller_id=? AND provider='tiny' AND enabled=1 LIMIT 1");$stmt->execute([$storeId,$sellerId]);$row=$stmt->fetch();
        if(!is_array($row))throw new RuntimeException('Conector Tiny não está ativo nesta loja.');
        return $row;
    }

    /** @param array<string,mixed> $config */
    private function client(array $config): TinyApiClient
    {
        $credentials=$this->credentialVault()->decrypt((string)$config['credentials_ciphertext']);
        return new TinyApiClient((string)($credentials['token'] ?? ''));
    }

    /** @return array<string,mixed> */
    private function run(int $runId): array
    {
        $stmt=$this->pdo()->prepare('SELECT * FROM fiscal_connector_runs WHERE id=? AND provider=? LIMIT 1');$stmt->execute([$runId,self::PROVIDER]);$row=$stmt->fetch();if(!is_array($row))throw new RuntimeException('Execução Tiny não encontrada.');return $row;
    }

    private function assertStoreProfile(int $storeId,int $sellerId): void
    {
        $stmt=$this->pdo()->prepare('SELECT id FROM store_fiscal_profiles WHERE store_id=? AND seller_id=? LIMIT 1');$stmt->execute([$storeId,$sellerId]);if(!(int)$stmt->fetchColumn())throw new RuntimeException('Salve primeiro os dados fiscais desta loja antes de conectar o Tiny.');
    }

    private function markFailure(int $configId,string $message): void
    {
        $this->pdo()->prepare('UPDATE store_fiscal_connector_configs SET last_failure_at=NOW(),last_error=? WHERE id=?')->execute([mb_substr($message,0,1000),$configId]);
    }

    private function pdo(): PDO{return $this->database ?? Database::connection();}
    private function credentialVault(): FiscalConnectorCredentialService{return $this->vault ?? new FiscalConnectorCredentialService();}
}
