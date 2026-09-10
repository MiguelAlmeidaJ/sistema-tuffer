<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Core\Database;
use PDO;
use RuntimeException;

final class FiscalConnectorManager
{
    public function __construct(private readonly ?PDO $database = null) {}

    public function enqueueSellerOrder(int $sellerOrderId): ?int
    {
        if($sellerOrderId<1)return null;
        $stmt=$this->pdo()->prepare("SELECT sfp.issuance_mode,sfp.provider FROM seller_orders so JOIN store_fiscal_profiles sfp ON sfp.store_id=so.store_id AND sfp.seller_id=so.seller_id WHERE so.id=? LIMIT 1");$stmt->execute([$sellerOrderId]);$profile=$stmt->fetch();
        if(!is_array($profile)||(string)$profile['issuance_mode']!==FiscalIssuanceMode::CONNECTOR)return null;
        return match((string)$profile['provider']){
            TinyFiscalConnectorService::PROVIDER=>(new TinyFiscalConnectorService($this->pdo()))->enqueue($sellerOrderId),
            default=>throw new RuntimeException('Conector fiscal ainda não suportado: '.(string)$profile['provider']),
        };
    }

    public function processRun(int $runId): void
    {
        $stmt=$this->pdo()->prepare('SELECT provider FROM fiscal_connector_runs WHERE id=? LIMIT 1');$stmt->execute([$runId]);$provider=(string)$stmt->fetchColumn();
        if($provider==='')throw new RuntimeException('Execução de conector fiscal não encontrada.');
        match($provider){
            TinyFiscalConnectorService::PROVIDER=>(new TinyFiscalConnectorService($this->pdo()))->process($runId),
            default=>throw new RuntimeException('Conector fiscal não suportado: '.$provider),
        };
    }

    private function pdo():PDO{return $this->database??Database::connection();}
}
