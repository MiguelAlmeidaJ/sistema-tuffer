<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class WholesaleApprovedMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$parameters): string
    {
        $statement = Database::connection()->prepare("SELECT COUNT(*) FROM wholesale_accounts WHERE user_id=? AND status='approved'");
        $statement->execute([Auth::id()]);
        return (int) $statement->fetchColumn() === 1 ? $next() : Response::redirect('/minha-conta/atacado/status');
    }
}
