<?php

declare(strict_types=1);

use App\Core\Logger;
use App\Services\Finance\MarketplaceReconciliationService;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
date_default_timezone_set('America/Sao_Paulo');
Logger::register();

$limit = max(1, min(500, (int) ($argv[1] ?? 100)));
$consultProvider = in_array('--provider', $argv, true);

try {
    // A consulta remota é opt-in. O comando nunca cria pedidos, cobranças, estornos ou transferências.
    $result = (new MarketplaceReconciliationService())->reconcile($limit, $consultProvider);
    echo json_encode([
        'ok' => true,
        'provider_consulted' => $consultProvider,
        'result' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($result['issues'] ?? 0) > 0 ? 2 : 0);
} catch (Throwable $exception) {
    Logger::warning('Falha na conciliação financeira do marketplace.', [
        'exception' => $exception::class,
    ], 'marketplace_reconciliation');
    fwrite(STDERR, json_encode([
        'ok' => false,
        'provider_consulted' => $consultProvider,
        'error' => mb_substr(strip_tags($exception->getMessage()), 0, 240),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
