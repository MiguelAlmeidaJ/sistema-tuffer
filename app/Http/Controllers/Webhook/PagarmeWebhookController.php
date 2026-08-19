<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Core\Logger;
use App\Services\Payments\PagarmeWebhookException;
use App\Services\Payments\PagarmeWebhookProcessor;
use App\Services\Payments\PagarmeWebhookSignature;
use App\Services\Queue\JobProcessor;
use App\Services\Queue\JobQueue;
use Throwable;

final class PagarmeWebhookController
{
    public function handle(): string
    {
        header('Content-Type: application/json; charset=UTF-8');
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody)) {
            return $this->respond(400, ['received' => false, 'error' => 'Corpo da requisição indisponível.']);
        }

        try {
            $algorithm = (new PagarmeWebhookSignature())->verify($rawBody, $this->signatureHeader());
            $result = (new PagarmeWebhookProcessor())->receive($rawBody, $algorithm);
            if ($result['status'] === 'queued') {
                $uniqueKey = 'pagarme-webhook:' . (int) $result['webhook_id'] . ':' . hash('sha256', $rawBody);
                $queue = new JobQueue();
                $job = $queue->reserveByUniqueKey($uniqueKey, 'webhook:' . (string) $result['event_id']);
                if ($job !== null) {
                    try {
                        (new JobProcessor())->process($job);
                        $queue->complete((int) $job['id']);
                        $result['status'] = 'processed';
                        Logger::info('Webhook Pagar.me processado imediatamente.', [
                            'webhook_id' => (int) $result['webhook_id'],
                            'job_id' => (int) $job['id'],
                        ], 'pagarme_webhook');
                    } catch (Throwable $exception) {
                        $queue->fail($job, $exception);
                        Logger::exception($exception, [
                            'webhook_id' => (int) $result['webhook_id'],
                            'job_id' => (int) $job['id'],
                        ], 'pagarme_webhook');
                    }
                }
            }
            unset($result['webhook_id']);
            return $this->respond($result['status'] === 'queued' ? 202 : 200, ['received' => true] + $result);
        } catch (PagarmeWebhookException $exception) {
            Logger::warning($exception->getMessage(), ['status' => $exception->httpStatus()], 'pagarme_webhook');
            return $this->respond($exception->httpStatus(), ['received' => false, 'error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Logger::exception($exception, [], 'pagarme_webhook');
            return $this->respond(500, ['received' => false, 'error' => 'Falha temporária ao processar o webhook.']);
        }
    }

    private function signatureHeader(): ?string
    {
        $configured = trim((string) ($_ENV['PAGARME_WEBHOOK_SIGNATURE_HEADER'] ?? ''));
        $names = array_values(array_unique(array_filter([
            $configured,
            'X-Hub-Signature-256',
            'X-Hub-Signature',
            'X-Pagarme-Signature',
            'X-Webhook-Signature',
        ])));
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($names as $name) {
            foreach ($headers as $headerName => $value) {
                if (strcasecmp((string) $headerName, $name) === 0 && is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $value = $_SERVER[$serverKey] ?? null;
            if (is_string($value) && trim($value) !== '') return trim($value);
        }
        return null;
    }

    /** @param array<string,mixed> $payload */
    private function respond(int $status, array $payload): string
    {
        http_response_code($status);
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"received":false}';
    }
}
