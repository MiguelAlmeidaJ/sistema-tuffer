<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use Closure;

interface MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$parameters): string;
}
