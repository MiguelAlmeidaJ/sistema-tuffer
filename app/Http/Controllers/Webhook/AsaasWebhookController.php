<?php
declare(strict_types=1);
namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Payments\Asaas\AsaasWebhookService;
use App\Core\Logger;
use Throwable;

final class AsaasWebhookController extends Controller
{
    public function handle(): string
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $body = file_get_contents('php://input');
        if (!is_string($body) || strlen($body) > 262144) {
            http_response_code(413);
            return '{"error":"payload"}';
        }
        $payload = json_decode($body, true, 32);
        if (!is_array($payload)) {
            http_response_code(400);
            return '{"error":"json"}';
        }
        $token = (string) ($_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '');
        try {
            (new AsaasWebhookService())->handle($payload, $token);
            return '{"received":true}';
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            http_response_code(in_array($code, [401,409,422], true) ? $code : 503);
            Logger::warning('Webhook Asaas não processado.', [
                'error_class' => get_class($e), 'code' => $code,
            ], 'asaas_webhook');
            return '{"received":false}';
        }
    }
}
