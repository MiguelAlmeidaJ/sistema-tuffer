<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SellerRegisterController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SocialAuthController;

$router->get('/entrar', [LoginController::class, 'create'], ['guest']);
$router->post('/entrar', [LoginController::class, 'store'], ['guest', 'csrf']);
$router->post('/sair', [LoginController::class, 'destroy'], ['auth', 'csrf']);
$router->get('/auth/google', [SocialAuthController::class, 'redirect'], ['guest']);
$router->get('/auth/google/callback', [SocialAuthController::class, 'callback'], ['guest']);
$router->get('/cadastro', [RegisterController::class, 'create'], ['guest']);
$router->post('/cadastro', [RegisterController::class, 'store'], ['guest', 'csrf']);
$router->get('/quero-vender', [SellerRegisterController::class, 'create'], ['guest']);
$router->post('/quero-vender', [SellerRegisterController::class, 'store'], ['guest', 'csrf']);
$router->get('/esqueci-minha-senha', [ForgotPasswordController::class, 'create'], ['guest']);
$router->post('/esqueci-minha-senha', [ForgotPasswordController::class, 'store'], ['guest', 'csrf']);
$router->get('/redefinir-senha/codigo', [ResetPasswordController::class, 'code'], ['guest']);
$router->post('/redefinir-senha/codigo', [ResetPasswordController::class, 'verify'], ['guest', 'csrf']);
$router->get('/redefinir-senha', [ResetPasswordController::class, 'edit'], ['guest']);
$router->post('/redefinir-senha', [ResetPasswordController::class, 'update'], ['guest', 'csrf']);
$router->get('/senha-alterada', [ResetPasswordController::class, 'success'], ['guest']);
