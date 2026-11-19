<?php

declare(strict_types=1);

use App\Http\Controllers\Webhook\PagarmeWebhookController;
use App\Http\Controllers\Webhook\AsaasWebhookController;

$router->post('/webhooks/pagarme', [PagarmeWebhookController::class, 'handle']);

$router->post('/webhooks/asaas', [AsaasWebhookController::class, 'handle']);
