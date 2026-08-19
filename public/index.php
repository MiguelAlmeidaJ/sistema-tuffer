<?php

declare(strict_types=1);

try {
    $requestStarted=microtime(true);
    /** @var App\Core\Router $router */
    $router = require dirname(__DIR__) . '/bootstrap/app.php';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $uriPath = (string) parse_url($uri, PHP_URL_PATH);
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (str_ends_with($basePath, '/public') && !str_starts_with($uriPath, $basePath)) $basePath = substr($basePath, 0, -7);
    if ($basePath !== '' && str_starts_with($uriPath, $basePath)) $uri = substr($uri, strlen($basePath)) ?: '/';
    $requestPath = '/' . ltrim((string) parse_url($uri, PHP_URL_PATH), '/');
    $maintenance = App\Services\Settings\PlatformSettings::enabled('maintenance_mode', false);
    $maintenanceBypass = $requestPath === '/health'
        || $requestPath === '/entrar'
        || $requestPath === '/sair'
        || str_starts_with($requestPath, '/admin')
        || (App\Core\Auth::user()['type'] ?? null) === 'admin';
    if ($maintenance && !$maintenanceBypass) {
        http_response_code(503);
        header('Retry-After: 300');
        echo App\Core\View::page('errors/503', 'layouts/public', ['pageTitle' => 'Manutenção programada']);
        return;
    }
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $uri);
    App\Core\Logger::info('HTTP request completed.',['status'=>http_response_code()?:200,'duration_ms'=>(int)round((microtime(true)-$requestStarted)*1000)],'http');
} catch (Throwable $exception) {
    if (class_exists(App\Core\Logger::class)) App\Core\Logger::exception($exception, [], 'http');
    http_response_code(500);
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    $debug=filter_var($_ENV['APP_DEBUG']??false,FILTER_VALIDATE_BOOL);
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Erro interno</title><body><main style="max-width:680px;margin:12vh auto;font-family:sans-serif"><h1>Não foi possível concluir a solicitação.</h1><p>Nossa equipe foi notificada. Tente novamente em alguns minutos.</p>'.($debug?'<pre>'.htmlspecialchars($exception->getMessage(),ENT_QUOTES,'UTF-8').'</pre>':'').'</main></body></html>';
}
