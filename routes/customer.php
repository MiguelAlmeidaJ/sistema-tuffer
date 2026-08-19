<?php

declare(strict_types=1);

use App\Http\Controllers\Customer\AddressController;
use App\Http\Controllers\Customer\ChatController;
use App\Http\Controllers\Customer\DashboardController;
use App\Http\Controllers\Customer\FavoriteController;
use App\Http\Controllers\Customer\OrderController;
use App\Http\Controllers\Customer\ProfileController;
use App\Http\Controllers\Customer\WholesaleController;
use App\Http\Controllers\Customer\NotificationController;
use App\Http\Controllers\Customer\EmailVerificationController;

$router->group(['prefix' => '/minha-conta', 'middleware' => ['auth', 'role:customer']], static function ($router): void {
    $router->get('/', [DashboardController::class, 'index']);
    $router->get('/pedidos', [OrderController::class, 'index']);
    $router->get('/pedidos/{code}', [OrderController::class, 'show']);
    $router->get('/favoritos', [FavoriteController::class, 'index']);
    $router->get('/perfil', [ProfileController::class, 'edit']);
    $router->put('/perfil', [ProfileController::class, 'update'], ['csrf']);

    $router->get('/enderecos', [AddressController::class, 'index']);
    $router->get('/enderecos/novo', [AddressController::class, 'create']);
    $router->post('/enderecos', [AddressController::class, 'store'], ['csrf']);
    $router->get('/enderecos/{id}/editar', [AddressController::class, 'edit']);
    $router->put('/enderecos/{id}', [AddressController::class, 'update'], ['csrf']);
    $router->delete('/enderecos/{id}', [AddressController::class, 'destroy'], ['csrf']);

    $router->get('/mensagens', [ChatController::class, 'index']);
    $router->get('/mensagens/nova/{storeId}', [ChatController::class, 'create']);
    $router->post('/mensagens', [ChatController::class, 'store'], ['csrf']);
    $router->get('/mensagens/{id}', [ChatController::class, 'show']);
    $router->post('/mensagens/{id}', [ChatController::class, 'send'], ['csrf']);
    $router->get('/notificacoes', [NotificationController::class, 'index']);
    $router->get('/verificar-email', [EmailVerificationController::class, 'show']);
    $router->post('/verificar-email/enviar', [EmailVerificationController::class, 'send'], ['csrf']);
    $router->post('/verificar-email', [EmailVerificationController::class, 'verify'], ['csrf']);

    $router->get('/atacado', [WholesaleController::class, 'index']);
    $router->get('/atacado/solicitar', [WholesaleController::class, 'create']);
    $router->post('/atacado/empresa', [WholesaleController::class, 'saveCompany'], ['csrf']);
    $router->post('/atacado/responsavel', [WholesaleController::class, 'saveResponsible'], ['csrf']);
    $router->post('/atacado/endereco', [WholesaleController::class, 'saveAddress'], ['csrf']);
    $router->post('/atacado/documentos', [WholesaleController::class, 'uploadDocuments'], ['csrf']);
    $router->post('/atacado/documentos/{id}/excluir', [WholesaleController::class, 'deleteDocument'], ['csrf']);
    $router->get('/atacado/revisao', [WholesaleController::class, 'review']);
    $router->post('/atacado/enviar', [WholesaleController::class, 'submit'], ['csrf']);
    $router->get('/atacado/status', [WholesaleController::class, 'status']);
});
