<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class RedirectIfAuthenticated implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$parameters): string
    {
        if (!Auth::check()) {
            return $next();
        }

        return Response::redirect(match (Auth::user()['type']) {
            'admin' => '/admin',
            'seller', 'operator' => '/vendedor',
            default => '/minha-conta',
        });
    }
}
