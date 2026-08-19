<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Core\Auth;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SellerApprovedMiddleware;
use App\Http\Middleware\EnsureSellerPaymentEnabled;
use App\Http\Middleware\WholesaleApprovedMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\VerifyCsrfToken;
use App\Services\Settings\PlatformSettings;
use App\Services\Cart\CartService;
use App\Core\Logger;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(self)');
    $secureRequest = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (filter_var($_ENV['TRUST_PROXY_HEADERS'] ?? false, FILTER_VALIDATE_BOOL)
            && strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https');
    if ($secureRequest) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    $contentSecurityPolicy = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self' https://*.pagar.me; img-src 'self' data: blob: https://res.cloudinary.com; media-src 'self' blob: https://res.cloudinary.com; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self' https://api.cloudinary.com https://*.melhorenvio.com.br";
    if ($secureRequest) $contentSecurityPolicy .= '; upgrade-insecure-requests';
    header('Content-Security-Policy: ' . $contentSecurityPolicy);
}
Logger::register();
Session::start();

View::share('authUser', Auth::user());
View::share('flashSuccess', Session::pullFlash('success'));
View::share('flashError', Session::pullFlash('error'));
View::share('validationErrors', Session::pullFlash('errors', []));
View::share('oldInput', Session::pullFlash('old', []));
View::share('platformSettings', PlatformSettings::all());
View::share('cartCount', (new CartService())->count());
$menuCategories = [];
try {
    $menuCategories = \App\Core\Database::connection()->query("SELECT name,slug FROM categories WHERE parent_id IS NULL AND status='active' AND customer_visible=1 AND show_in_menu=1 ORDER BY sort_order,name LIMIT 5")->fetchAll();
} catch (\Throwable) {
    // Mantém o cabeçalho disponível durante uma implantação antes da migração.
}
View::share('menuCategories', $menuCategories);
$wholesaleStatus = null;
$unreadNotifications = 0;
if ((Auth::user()['type'] ?? null) === 'customer') {
    try {
        $statement = \App\Core\Database::connection()->prepare('SELECT status FROM wholesale_accounts WHERE user_id=? LIMIT 1');
        $statement->execute([Auth::id()]);
        $wholesaleStatus = $statement->fetchColumn() ?: null;
        $statement = \App\Core\Database::connection()->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id=? AND read_at IS NULL');
        $statement->execute([Auth::id()]);
        $unreadNotifications = (int) $statement->fetchColumn();
    } catch (\Throwable) {
        // Permite executar a aplicação antes da nova migração.
    }
}
View::share('wholesaleStatus', $wholesaleStatus);
View::share('unreadNotifications', $unreadNotifications);

$router = new Router();
$router->aliasMiddleware('auth', Authenticate::class);
$router->aliasMiddleware('guest', RedirectIfAuthenticated::class);
$router->aliasMiddleware('role', RoleMiddleware::class);
$router->aliasMiddleware('seller.approved', SellerApprovedMiddleware::class);
$router->aliasMiddleware('seller.payment-enabled', EnsureSellerPaymentEnabled::class);
$router->aliasMiddleware('wholesale.approved', WholesaleApprovedMiddleware::class);
$router->aliasMiddleware('permission', PermissionMiddleware::class);
$router->aliasMiddleware('csrf', VerifyCsrfToken::class);

foreach (['web', 'auth', 'customer', 'seller', 'admin', 'webhooks'] as $routeFile) {
    require $root . "/routes/{$routeFile}.php";
}

return $router;
