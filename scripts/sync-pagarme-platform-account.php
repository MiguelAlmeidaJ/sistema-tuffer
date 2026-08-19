<?php

declare(strict_types=1);

use App\Core\Logger;
use App\Services\Payments\Pagarme\PagarmePlatformAccountService;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
date_default_timezone_set('America/Sao_Paulo');
Logger::register();

try {
    // Consulta somente GET na Pagar.me; a única escrita é o status sanitizado no banco local.
    $account = (new PagarmePlatformAccountService())->synchronize();
    $recipient = (string) ($account['recipient_id'] ?? '');
    $masked = strlen($recipient) > 8
        ? substr($recipient, 0, 3) . str_repeat('*', strlen($recipient) - 7) . substr($recipient, -4)
        : str_repeat('*', strlen($recipient));
    echo json_encode([
        'ok' => true,
        'operation' => 'remote_read_local_status_sync',
        'environment' => $account['environment'] ?? null,
        'recipient_id' => $masked,
        'recipient_status' => $account['recipient_status'] ?? null,
        'kyc_status' => $account['kyc_status'] ?? null,
        'payment_enabled' => (bool) ($account['payment_enabled'] ?? false),
        'last_synced_at' => $account['last_synced_at'] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    Logger::warning('Falha na sincronização da conta global Pagar.me.', [
        'exception' => $exception::class,
    ], 'pagarme_platform');
    fwrite(STDERR, json_encode([
        'ok' => false,
        'operation' => 'remote_read_local_status_sync',
        'error' => mb_substr(strip_tags($exception->getMessage()), 0, 240),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
