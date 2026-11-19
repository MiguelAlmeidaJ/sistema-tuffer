<?php
declare(strict_types=1);
namespace App\Services\Sellers;
use App\Core\Database;
use PDO;
final class SellerSalesEligibility {
    public function __construct(private readonly ?PDO $database=null) {}
    public function canSell(array $seller): bool {
        return ($seller['seller_status'] ?? $seller['status'] ?? null)==='active' && ($seller['store_status'] ?? 'active')==='active';
    }
    private function pdo(): PDO {return $this->database ?? Database::connection();}
    private function exists(string $where,int $id): bool {
        if($id<1)return false;
        $stmt=$this->pdo()->prepare("SELECT 1 FROM stores st JOIN sellers s ON s.id=st.seller_id WHERE {$where} AND st.status='active' AND s.status='active' LIMIT 1");
        $stmt->execute([$id]);return (bool)$stmt->fetchColumn();
    }
    public function storeCanSell(int $id): bool {return $this->exists('st.id=?',$id);}
    public function sellerCanSell(int $id): bool {return $this->exists('s.id=?',$id);}
    public function userCanSell(int $id): bool {return $this->exists('s.user_id=?',$id);}
    public function assertAllStoresCanSell(array $storeIds): void {
        $ids=array_values(array_unique(array_filter(array_map('intval',$storeIds))));
        if(!$ids)throw new \RuntimeException('Nenhuma loja válida foi localizada.');
        $qs=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$this->pdo()->prepare("SELECT COUNT(DISTINCT st.id) FROM stores st JOIN sellers s ON s.id=st.seller_id WHERE st.id IN ({$qs}) AND st.status='active' AND s.status='active'");
        $stmt->execute($ids);
        if((int)$stmt->fetchColumn()!==count($ids))throw new \RuntimeException('Uma das lojas está inativa ou indisponível.');
    }
    public function assertAllCanSell(array $sellerIds): void {
        $ids=array_values(array_unique(array_filter(array_map('intval',$sellerIds))));
        if(!$ids)throw new \RuntimeException('Nenhum responsável de loja válido.');
        foreach($ids as $id)if(!$this->sellerCanSell($id))throw new \RuntimeException('Uma das lojas está inativa ou indisponível.');
    }
}
