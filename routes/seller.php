<?php

declare(strict_types=1);

use App\Http\Controllers\Seller\CouponController;
use App\Http\Controllers\Seller\ChatController;
use App\Http\Controllers\Seller\DashboardController;
use App\Http\Controllers\Seller\FinanceController;
use App\Http\Controllers\Seller\OnboardingController;
use App\Http\Controllers\Seller\OrderController;
use App\Http\Controllers\Seller\ProductController;
use App\Http\Controllers\Seller\ProductExportController;
use App\Http\Controllers\Seller\ReportController;
use App\Http\Controllers\Seller\SettingsController;
use App\Http\Controllers\Seller\PaymentSettingsController;
use App\Http\Controllers\Seller\StoreContextController;

$router->group(['prefix' => '/vendedor', 'middleware' => ['auth', 'role:seller,operator']], static function ($router): void {
    $router->get('/onboarding', [OnboardingController::class, 'index']);
    $router->group(['middleware' => ['seller.approved']], static function ($router): void {
        $router->get('/', [DashboardController::class, 'index']);
        $router->post('/selecionar-loja', [StoreContextController::class, 'select'], ['csrf']);
        $router->get('/relatorios', [ReportController::class, 'index']);
        $router->get('/financeiro', [FinanceController::class, 'index']);
        $router->get('/pedidos', [OrderController::class, 'index']);
        $router->get('/pedidos/{code}', [OrderController::class, 'show']);
        $router->post('/pedidos/{code}/preparar', [OrderController::class, 'process'], ['csrf']);
        $router->post('/pedidos/{code}/comprar-etiqueta', [OrderController::class, 'purchaseLabel'], ['csrf']);
        $router->post('/pedidos/{code}/rastreio', [OrderController::class, 'shipping'], ['csrf']);
        $router->post('/pedidos/{code}/sincronizar-rastreio', [OrderController::class, 'sync'], ['csrf']);
        $router->get('/mensagens', [ChatController::class, 'index']);
        $router->get('/mensagens/{id}', [ChatController::class, 'show']);
        $router->post('/mensagens/{id}', [ChatController::class, 'send'], ['csrf']);
        $router->post('/mensagens/{id}/encerrar', [ChatController::class, 'close'], ['csrf']);

        $router->get('/produtos', [ProductController::class, 'index']);
        $router->get('/produtos/novo', [ProductController::class, 'create'], ['seller.payment-enabled']);
        $router->post('/produtos', [ProductController::class, 'store'], ['seller.payment-enabled', 'csrf']);
        $router->get('/produtos/exportar', [ProductExportController::class, 'index']);
        $router->post('/produtos/exportar/preparar', [ProductExportController::class, 'prepare'], ['csrf']);
        $router->post('/produtos/exportar/baixar', [ProductExportController::class, 'download'], ['csrf']);
        $router->post('/produtos/importar', [ProductExportController::class, 'import'], ['seller.payment-enabled', 'csrf']);
        $router->post('/produtos/status-em-lote', [ProductController::class, 'bulkStatus'], ['csrf']);
        $router->post('/produtos/transferir-em-lote', [ProductController::class, 'bulkTransfer'], ['seller.payment-enabled', 'csrf']);
        $router->post('/produtos/excluir-em-lote', [ProductController::class, 'bulkDestroy'], ['csrf']);
        $router->get('/produtos/{id}/editar', [ProductController::class, 'edit']);
        $router->put('/produtos/{id}', [ProductController::class, 'update'], ['csrf']);
        $router->post('/produtos/{id}/estoque', [ProductController::class, 'adjustStock'], ['csrf']);
        $router->delete('/produtos/{id}', [ProductController::class, 'destroy'], ['csrf']);

        $router->get('/cupons', [CouponController::class, 'index']);
        $router->get('/cupons/novo', [CouponController::class, 'create']);
        $router->post('/cupons', [CouponController::class, 'store'], ['csrf']);
        $router->get('/cupons/{id}/editar', [CouponController::class, 'edit']);
        $router->put('/cupons/{id}', [CouponController::class, 'update'], ['csrf']);
        $router->delete('/cupons/{id}', [CouponController::class, 'destroy'], ['csrf']);

        $router->get('/configuracoes/loja', [SettingsController::class, 'storeEdit']);
        $router->put('/configuracoes/loja', [SettingsController::class, 'storeUpdate'], ['csrf']);
        $router->get('/configuracoes/vendedor', [SettingsController::class, 'sellerEdit'], ['role:seller']);
        $router->put('/configuracoes/vendedor', [SettingsController::class, 'sellerUpdate'], ['role:seller', 'csrf']);
        $router->get('/configuracoes/recebimentos', [PaymentSettingsController::class, 'index'], ['role:seller']);
        $router->post('/configuracoes/recebimentos', [PaymentSettingsController::class, 'create'], ['role:seller', 'csrf']);
        $router->post('/configuracoes/recebimentos/status', [PaymentSettingsController::class, 'refresh'], ['role:seller', 'csrf']);
        $router->post('/configuracoes/recebimentos/kyc', [PaymentSettingsController::class, 'kyc'], ['role:seller', 'csrf']);
    });
});
