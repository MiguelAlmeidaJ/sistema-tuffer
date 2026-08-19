<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Logger;
use App\Services\Payments\Pagarme\PagarmeRecipientService;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
date_default_timezone_set('America/Sao_Paulo');
Logger::register();

$limit = max(1, min(500, (int) ($argv[1] ?? 100)));
$service = new PagarmeRecipientService();
$statement = Database::connection()->prepare(
    "SELECT seller_id FROM seller_payment_accounts
     WHERE provider='pagarme' AND environment=? AND recipient_id IS NOT NULL
     ORDER BY COALESCE(last_synced_at,'1970-01-01') ASC LIMIT {$limit}"
);
$statement->execute([$service->environment()]);
$result = ['processed' => 0, 'failed' => 0];

foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $sellerId) {
    try {
        $service->synchronizeStatus((int) $sellerId);
        $result['processed']++;
    } catch (Throwable $exception) {
        $result['failed']++;
        Logger::exception($exception, ['seller_id' => (int) $sellerId], 'pagarme_recipient_sync');
    }
}

Logger::info('Sincronização periódica de recebedores concluída.', $result, 'pagarme_recipient_sync');
echo 'PAGARME_RECIPIENT_SYNC=' . json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['failed'] > 0 ? 1 : 0);
