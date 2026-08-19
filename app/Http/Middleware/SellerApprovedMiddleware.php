<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use Closure;

final class SellerApprovedMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$parameters): string
    {
        if ((Auth::user()['type'] ?? null) === 'operator') {
            $statement = Database::connection()->prepare("SELECT COUNT(*) FROM store_users su JOIN stores st ON st.id=su.store_id JOIN sellers s ON s.id=st.seller_id WHERE su.user_id=? AND st.status='active' AND s.status='active'");
        } else {
            $statement = Database::connection()->prepare("SELECT COUNT(*) FROM sellers WHERE user_id=? AND status='active'");
        }
        $statement->execute([Auth::id()]);
        if ((int) $statement->fetchColumn() === 0) {
            return Response::redirect('/vendedor/onboarding');
        }
        return $next();
    }
}
