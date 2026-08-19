<?php

declare(strict_types=1);

use App\Core\Logger;
use App\Services\Orders\ExpiredOrderService;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
date_default_timezone_set('America/Sao_Paulo');
Logger::register();

$limit = max(1, min(500, (int) ($argv[1] ?? 100)));
$result = (new ExpiredOrderService())->expire($limit);
Logger::info('Expiração de pedidos pendentes concluída.', $result, 'orders');
echo 'EXPIRED_ORDERS=' . json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['failed'] > 0 ? 1 : 0);
