<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Settings\PlatformSettings;
use Closure;

final class Authenticate implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$parameters): string
    {
        if (!Auth::check()) {
            Session::flash('error', 'Entre para acessar essa área.');
            return Response::redirect('/entrar');
        }
        if ((Auth::user()['type'] ?? null) === 'admin') {
            $timeout = max(5, min(1440, (int) (PlatformSettings::all()['admin_session_timeout'] ?? 60)));
            $lastActivity = (int) Session::get('admin_last_activity', 0);
            if ($lastActivity > 0 && $lastActivity < time() - ($timeout * 60)) {
                Auth::logout();
                Session::flash('error', 'Sua sessão administrativa expirou por segurança. Entre novamente.');
                return Response::redirect('/entrar');
            }
            Session::put('admin_last_activity', time());
        }
        return $next();
    }
}
