<?php

declare(strict_types=1);

use App\Core\Database;use App\Core\Logger;use App\Services\Fiscal\FiscalOrchestratorService;use App\Services\Fiscal\FiscalWebhookService;use App\Services\Fiscal\TinyFiscalConnectorService;use Dotenv\Dotenv;
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';Dotenv::createImmutable($root)->safeLoad();date_default_timezone_set('America/Sao_Paulo');Logger::register();$limit=max(1,min(1000,(int)($argv[1]??200)));$pdo=Database::connection();$s=$pdo->prepare("SELECT id FROM seller_orders WHERE status IN ('paid','processing','shipped','delivered') ORDER BY id DESC LIMIT {$limit}");$s->execute();$service=new FiscalOrchestratorService($pdo);$webhooks=new FiscalWebhookService($pdo);$tiny=new TinyFiscalConnectorService($pdo);$processed=0;$failed=0;foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id){try{$sellerOrderId=(int)$id;$service->syncSellerOrder($sellerOrderId);$webhooks->enqueueReady($sellerOrderId);$tiny->enqueue($sellerOrderId);$processed++;}catch(Throwable $e){$failed++;Logger::exception($e,['seller_order_id'=>(int)$id],'fiscal');}}echo "FISCAL_SYNC processed={$processed} failed={$failed}\n";exit($failed>0?1:0);
