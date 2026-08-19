<?php

declare(strict_types=1);

use App\Http\Controllers\Webhook\PagarmeWebhookController;

$router->post('/webhooks/pagarme', [PagarmeWebhookController::class, 'handle']);
