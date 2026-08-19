<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WholesaleController;
use App\Http\Controllers\Admin\MonitoringController;
use App\Http\Controllers\Admin\PagarmeDiagnosticController;
use App\Http\Controllers\Admin\FinancialSettlementController;
use App\Http\Controllers\Admin\ShippingWalletController;

$router->group(['prefix' => '/admin', 'middleware' => ['auth', 'role:admin']], static function ($router): void {
    $router->get('/', [DashboardController::class, 'index']);
    $router->get('/relatorios', [ReportController::class, 'index']);
    $router->get('/financeiro', [FinanceController::class, 'index']);
    $router->get('/financeiro/carteira-fretes', [ShippingWalletController::class, 'index']);
    $router->post('/financeiro/carteira-fretes', [ShippingWalletController::class, 'create'], ['csrf']);
    $router->get('/financeiro/fechamentos', [FinancialSettlementController::class, 'index']);
    $router->post('/financeiro/fechamentos', [FinancialSettlementController::class, 'generate'], ['csrf']);
    $router->get('/financeiro/fechamentos/{id}', [FinancialSettlementController::class, 'show']);
    $router->post('/financeiro/fechamentos/{id}/revisar', [FinancialSettlementController::class, 'review'], ['csrf']);
    $router->post('/financeiro/fechamentos/{id}/aprovar', [FinancialSettlementController::class, 'approve'], ['csrf']);
    $router->post('/financeiro/fechamentos/{id}/cancelar', [FinancialSettlementController::class, 'cancel'], ['csrf']);
    $router->post('/financeiro/fechamentos/{id}/transferencias', [FinancialSettlementController::class, 'transfer'], ['csrf']);
    $router->get('/financeiro/fechamentos/{id}/transferencias/{transferId}/comprovante', [FinancialSettlementController::class, 'proof']);
    $router->post('/financeiro/fechamentos/{id}/divergencias/{issueId}/resolver', [FinancialSettlementController::class, 'resolveIssue'], ['csrf']);
    $router->get('/pedidos', [OrderController::class, 'index']);
    $router->get('/monitoramento', [MonitoringController::class, 'index']);
    $router->get('/diagnostico/pagarme', [PagarmeDiagnosticController::class, 'index']);
    $router->post('/monitoramento/alertas/{id}/resolver', [MonitoringController::class, 'resolve'], ['csrf']);
    $router->post('/monitoramento/jobs/{id}/tentar-novamente', [MonitoringController::class, 'retryJob'], ['csrf']);
    $router->get('/pedidos/{code}', [OrderController::class, 'show']);
    $router->post('/pedidos/{code}/remessas/{shipmentId}/sincronizar', [OrderController::class, 'sync'], ['csrf']);
    $router->post('/pedidos/{code}/pagamentos/{paymentId}/estornar-pix', [OrderController::class, 'refundPix'], ['csrf']);
    $router->get('/produtos', [ProductController::class, 'index'], ['permission:catalog.view']);
    $router->get('/produtos/exportar', [ProductController::class, 'export'], ['permission:catalog.export']);
    $router->get('/produtos/{id}', [ProductController::class, 'show'], ['permission:catalog.view']);
    $router->post('/produtos/{id}/iniciar-analise', [ProductController::class, 'startReview'], ['permission:catalog.review', 'csrf']);
    $router->post('/produtos/{id}/aprovar', [ProductController::class, 'approve'], ['permission:catalog.approve', 'csrf']);
    $router->post('/produtos/{id}/solicitar-correcoes', [ProductController::class, 'requestChanges'], ['permission:catalog.request_changes', 'csrf']);
    $router->post('/produtos/{id}/rejeitar', [ProductController::class, 'reject'], ['permission:catalog.reject', 'csrf']);
    $router->post('/produtos/{id}/pausar', [ProductController::class, 'pause'], ['permission:catalog.pause', 'csrf']);
    $router->post('/produtos/{id}/retomar', [ProductController::class, 'resume'], ['permission:catalog.pause', 'csrf']);
    $router->post('/produtos/{id}/destaque', [ProductController::class, 'feature'], ['permission:catalog.feature', 'csrf']);

    $router->get('/lojas', [StoreController::class, 'index']);
    $router->get('/lojas/nova', [StoreController::class, 'create']);
    $router->post('/lojas', [StoreController::class, 'store'], ['csrf']);
    $router->get('/lojas/{id}/editar', [StoreController::class, 'edit']);
    $router->put('/lojas/{id}', [StoreController::class, 'update'], ['csrf']);
    $router->post('/lojas/{id}/inativar', [StoreController::class, 'deactivate'], ['csrf']);
    $router->delete('/lojas/{id}', [StoreController::class, 'destroy'], ['csrf']);

    $router->get('/categorias', [CategoryController::class, 'index']);
    $router->get('/categorias/nova', [CategoryController::class, 'create']);
    $router->post('/categorias', [CategoryController::class, 'store'], ['csrf']);
    $router->get('/categorias/{id}/editar', [CategoryController::class, 'edit']);
    $router->put('/categorias/{id}', [CategoryController::class, 'update'], ['csrf']);
    $router->post('/categorias/{id}/ordenar', [CategoryController::class, 'reorder'], ['csrf']);
    $router->delete('/categorias/{id}', [CategoryController::class, 'destroy'], ['csrf']);

    $router->get('/tags', [TagController::class, 'index']);
    $router->get('/tags/nova', [TagController::class, 'create']);
    $router->post('/tags', [TagController::class, 'store'], ['csrf']);
    $router->get('/tags/{id}/editar', [TagController::class, 'edit']);
    $router->put('/tags/{id}', [TagController::class, 'update'], ['csrf']);

    $router->get('/usuarios', [UserController::class, 'index']);
    $router->get('/usuarios/novo', [UserController::class, 'create']);
    $router->post('/usuarios', [UserController::class, 'store'], ['csrf']);
    $router->get('/usuarios/{id}/editar', [UserController::class, 'edit']);
    $router->put('/usuarios/{id}', [UserController::class, 'update'], ['csrf']);

    $router->get('/configuracoes', [SettingsController::class, 'index']);
    $router->put('/configuracoes', [SettingsController::class, 'update'], ['csrf']);
});

$router->group(['prefix' => '/admin/atacadistas', 'middleware' => ['auth', 'role:admin', 'permission:wholesale.manage']], static function ($router): void {
    $router->get('/', [WholesaleController::class, 'index']);
    $router->get('/{id}', [WholesaleController::class, 'show']);
    $router->get('/{id}/documentos/{documentId}', [WholesaleController::class, 'downloadDocument']);
    $router->post('/{id}/documentos/{documentId}/aprovar', [WholesaleController::class, 'approveDocument'], ['csrf']);
    $router->post('/{id}/documentos/{documentId}/rejeitar', [WholesaleController::class, 'rejectDocument'], ['csrf']);
    $router->post('/{id}/analise', [WholesaleController::class, 'startReview'], ['csrf']);
    $router->post('/{id}/aprovar', [WholesaleController::class, 'approve'], ['csrf']);
    $router->post('/{id}/rejeitar', [WholesaleController::class, 'reject'], ['csrf']);
    $router->post('/{id}/suspender', [WholesaleController::class, 'suspend'], ['csrf']);
    $router->post('/{id}/reativar', [WholesaleController::class, 'reactivate'], ['csrf']);
});
