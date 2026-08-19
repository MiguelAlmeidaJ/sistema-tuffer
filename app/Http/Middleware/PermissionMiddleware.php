<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use Closure;

final class PermissionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$parameters): string
    {
        if (($parameters[0] ?? '') === '') return $next();
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=? AND p.slug=?');
        $statement->execute([Auth::id(), $parameters[0]]);
        if ((int) $statement->fetchColumn() > 0) return $next();
        http_response_code(403);
        return View::page('errors/403', 'layouts/public', ['pageTitle' => 'Acesso negado']);
    }
}
