<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\View;
use Closure;

final class VerifyCsrfToken implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$parameters): string
    {
        if (!$request->isMethod('GET') && !Csrf::valid((string) $request->input('_token'))) {
            // 419 não é um status HTTP padrão e o Apache do Laragon o converte em 500.
            http_response_code(403);
            return View::page('errors/419', 'layouts/public', ['pageTitle' => 'Sessão expirada']);
        }
        return $next();
    }
}
