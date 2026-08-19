<?php

declare(strict_types=1);

use App\Http\Controllers\Public\CartController;
use App\Http\Controllers\Public\CatalogController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\ProductController;
use App\Http\Controllers\Public\CheckoutController;
use App\Http\Controllers\Public\StoreController;
use App\Http\Controllers\Public\TrackingController;
use App\Http\Controllers\Public\LegalController;
use App\Http\Controllers\Public\NewsletterController;
use App\Http\Controllers\Public\SeoController;

$router->get('/', [HomeController::class, 'index']);
$router->get('/sitemap.xml', [SeoController::class, 'sitemap']);
$router->get('/robots.txt', [SeoController::class, 'robots']);
$router->get('/health', [HomeController::class, 'health']);
$router->get('/produtos', [CatalogController::class, 'index']);
$router->get('/produto/{slug}', [ProductController::class, 'show']);
$router->post('/produto/{slug}/frete', [ProductController::class, 'shipping'], ['csrf']);
$router->get('/categoria/{slug}', [CatalogController::class, 'category']);
$router->get('/buscar', [CatalogController::class, 'search']);
$router->get('/ofertas', [CatalogController::class, 'offers']);
$router->get('/lojas', [StoreController::class, 'index']);
$router->get('/loja/{slug}', [StoreController::class, 'show']);
$router->get('/carrinho', [CartController::class, 'index']);
$router->post('/carrinho/adicionar', [CartController::class, 'store'], ['csrf']);
$router->post('/carrinho/modo', [CartController::class, 'mode'], ['auth', 'role:customer', 'csrf']);
$router->post('/carrinho/frete', [CartController::class, 'shipping'], ['csrf']);
$router->post('/carrinho/cupom', [CartController::class, 'coupon'], ['csrf']);
$router->delete('/carrinho/cupom/{storeId}', [CartController::class, 'removeCoupon'], ['csrf']);
$router->post('/carrinho/item/{id}/salvar', [CartController::class, 'saveForLater'], ['auth', 'role:customer', 'csrf']);
$router->put('/carrinho/item/{id}', [CartController::class, 'update'], ['csrf']);
$router->delete('/carrinho/item/{id}', [CartController::class, 'destroy'], ['csrf']);
$router->get('/checkout', [CheckoutController::class, 'index']);
$router->post('/checkout/cotacoes', [CheckoutController::class, 'quotes'], ['auth', 'role:customer', 'csrf']);
$router->post('/checkout/finalizar', [CheckoutController::class, 'store'], ['auth', 'role:customer', 'csrf']);
$router->get('/rastrear-pedido', [TrackingController::class, 'index']);
$router->post('/rastrear-pedido', [TrackingController::class, 'track'], ['csrf']);
$router->get('/termos-de-compra', [LegalController::class, 'terms']);
$router->get('/politica-de-privacidade', [LegalController::class, 'privacy']);
$router->get('/politicas', [LegalController::class, 'index']);
$router->get('/politicas/{slug}', [LegalController::class, 'show']);
$router->post('/newsletter/assinar', [NewsletterController::class, 'subscribe'], ['csrf']);
$router->get('/newsletter/confirmar', [NewsletterController::class, 'confirm']);
$router->get('/newsletter/cancelar', [NewsletterController::class, 'unsubscribe']);
