<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FiscalIntegrationController;
use App\Http\Controllers\Api\FiscalSellerOrderPayloadController;

$router->group(['prefix'=>'/api/v1/fiscal'], static function ($router): void {
    $router->get('/seller-orders/{code}', [FiscalIntegrationController::class, 'show']);
    $router->get('/seller-orders/{code}/payload', [FiscalSellerOrderPayloadController::class, 'show']);
    $router->post('/seller-orders/{code}/authorize', [FiscalIntegrationController::class, 'authorize']);
    $router->post('/seller-orders/{code}/cancel', [FiscalIntegrationController::class, 'cancel']);
});
