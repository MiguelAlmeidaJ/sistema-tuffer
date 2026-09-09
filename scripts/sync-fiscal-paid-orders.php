<?php

declare(strict_types=1);

use App\Core\Database;use App\Core\Logger;use App\Services\Fiscal\FiscalDocumentService;use Dotenv\Dotenv;
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';Dotenv::createImmutable($root)->safeLoad();date_default_timezone_set('America/Sao_Paulo');Logger::register();$limit=max(1,min(1000,(int)($argv[1]??200)));$s=Database::connection()->prepare("SELECT id FROM seller_orders WHERE status IN ('paid','processing','shipped','delivered') ORDER BY id DESC LIMIT {$limit}");$s->execute();$service=new FiscalDocumentService();$processed=0;$failed=0;foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id){try{$service->syncSellerOrder((int)$id);$processed++;}catch(Throwable $e){$failed++;Logger::exception($e,['seller_order_id'=>(int)$id],'fiscal');}}echo "FISCAL_SYNC processed={$processed} failed={$failed}\n";exit($failed>0?1:0);
