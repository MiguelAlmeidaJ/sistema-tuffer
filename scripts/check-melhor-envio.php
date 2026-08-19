<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$token = trim((string) ($_ENV['MELHOR_ENVIO_ACCESS_TOKEN'] ?? $_ENV['MELHOR_ENVIO_TOKEN'] ?? ''));
$configuredBaseUrl = rtrim(trim((string) ($_ENV['MELHOR_ENVIO_BASE_URL'] ?? 'https://sandbox.melhorenvio.com.br')), '/');
$configuredBaseUrl = str_ends_with($configuredBaseUrl, '/api/v2') ? $configuredBaseUrl : $configuredBaseUrl . '/api/v2';

if ($token === '') {
    fwrite(STDERR, "MELHOR_ENVIO=NOT_CONFIGURED\n");
    exit(2);
}

$candidates = ['configured' => $configuredBaseUrl];
if (str_contains($configuredBaseUrl, 'sandbox.melhorenvio.com.br')) {
    $candidates['production'] = 'https://melhorenvio.com.br/api/v2';
} else {
    $candidates['sandbox'] = 'https://sandbox.melhorenvio.com.br/api/v2';
}

foreach ($candidates as $environment => $baseUrl) {
    $curl = curl_init($baseUrl . '/me');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: ' . (string) ($_ENV['MELHOR_ENVIO_USER_AGENT'] ?? 'Tuffer Marketplace (contato@tuffer.com.br)'),
        ],
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (is_string($response) && $status >= 200 && $status < 300) {
        fwrite(STDOUT, 'MELHOR_ENVIO=AUTHENTICATED ENV=' . $environment . ' HTTP=' . $status . PHP_EOL);
        exit(0);
    }
    $message = '';
    $decoded = is_string($response) ? json_decode($response, true) : null;
    if (is_array($decoded)) $message = (string) ($decoded['message'] ?? $decoded['error'] ?? '');
    fwrite(STDERR, 'MELHOR_ENVIO_ATTEMPT=FAILED ENV=' . $environment . ' HTTP=' . $status . ($message !== '' ? ' ERROR=' . mb_substr(strip_tags($message), 0, 160) : ($error !== '' ? ' ERROR=' . $error : '')) . PHP_EOL);

    $curl = curl_init($baseUrl . '/me/shipment/tracking');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: ' . (string) ($_ENV['MELHOR_ENVIO_USER_AGENT'] ?? 'Tuffer Marketplace (contato@tuffer.com.br)'),
        ],
        CURLOPT_POSTFIELDS => json_encode(['orders' => ['00000000-0000-0000-0000-000000000000']], JSON_THROW_ON_ERROR),
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (is_string($response) && !in_array($status, [0, 401, 403], true)) {
        fwrite(STDOUT, 'MELHOR_ENVIO_TRACKING=REACHABLE ENV=' . $environment . ' HTTP=' . $status . PHP_EOL);
        exit(0);
    }
}

exit(1);
