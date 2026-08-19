<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use Closure;

final class RoleMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$parameters): string
    {
        $type = Auth::user()['type'] ?? null;
        if (!in_array($type, $parameters, true)) {
            http_response_code(403);
            return View::page('errors/403', 'layouts/public', ['pageTitle' => 'Acesso negado']);
        }
        return $next();
    }
}
