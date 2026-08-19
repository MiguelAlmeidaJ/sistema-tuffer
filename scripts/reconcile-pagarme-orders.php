<?php

declare(strict_types=1);

use App\Core\Logger;
use App\Services\Payments\Pagarme\PagarmeOrderReconciliationService;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
date_default_timezone_set('America/Sao_Paulo');
Logger::register();

$limit = max(1, min(500, (int) ($argv[1] ?? 100)));
$result = (new PagarmeOrderReconciliationService())->reconcilePending($limit);
echo 'PAGARME_RECONCILIATION='
    . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    . PHP_EOL;
exit($result['errors'] > 0 ? 1 : 0);
