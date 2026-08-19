<?php

declare(strict_types=1);

use App\Services\Payments\Pagarme\PagarmePlatformDiagnosticService;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();
date_default_timezone_set('America/Sao_Paulo');

// Este comando não carrega o bootstrap web, não escreve no banco e só executa GET.
$result = (new PagarmePlatformDiagnosticService())->inspect();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($result['ok'] ?? false) ? 0 : 1);
