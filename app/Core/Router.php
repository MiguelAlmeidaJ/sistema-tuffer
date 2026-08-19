<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\Middleware\MiddlewareInterface;
use Closure;
use RuntimeException;

final class Router
{
    /** @var array<int, array{method: string, path: string, handler: mixed, middleware: array<int, string>}> */
    private array $routes = [];
    /** @var array<string, class-string<MiddlewareInterface>> */
    private array $middlewareAliases = [];
    private string $prefix = '';
    /** @var array<int, string> */
    private array $groupMiddleware = [];

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /** @param class-string<MiddlewareInterface> $middleware */
    public function aliasMiddleware(string $alias, string $middleware): void
    {
        $this->middlewareAliases[$alias] = $middleware;
    }

    /** @param array{prefix?: string, middleware?: array<int, string>} $attributes */
    public function group(array $attributes, Closure $callback): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->groupMiddleware;
        $this->prefix .= $attributes['prefix'] ?? '';
        $this->groupMiddleware = array_merge($this->groupMiddleware, $attributes['middleware'] ?? []);

        $callback($this);

        $this->prefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = $this->normalize((string) parse_url($uri, PHP_URL_PATH));
        $originalMethod = strtoupper($method);
        $requestMethod = $originalMethod === 'POST' ? strtoupper((string) ($_POST['_method'] ?? 'POST')) : $originalMethod;
        $matchMethod = $requestMethod === 'HEAD' ? 'GET' : $requestMethod;

        foreach ($this->routes as $route) {
            if ($route['method'] !== $matchMethod) {
                continue;
            }

            $pattern = $this->compilePattern($route['path']);
            if (!preg_match($pattern, $path, $matches)) {
                continue;
            }

            $parameters = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $request = Request::capture($requestMethod, $path, $parameters);
            $destination = fn (): string => $this->runHandler($route['handler'], $parameters);
            $pipeline = array_reduce(
                array_reverse($route['middleware']),
                fn (Closure $next, string $middleware): Closure => fn (): string => $this->runMiddleware($middleware, $request, $next),
                $destination
            );

            echo $pipeline();
            return;
        }

        http_response_code(404);
        echo View::page('errors/404', 'layouts/public', ['path' => $path, 'pageTitle' => 'Página não encontrada']);
    }

    /** @param array<int, string> $middleware */
    private function add(string $method, string $path, mixed $handler, array $middleware): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $this->normalize($this->prefix . '/' . ltrim($path, '/')),
            'handler' => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    /** @param array<string, string> $parameters */
    private function runHandler(mixed $handler, array $parameters): string
    {
        if (is_array($handler) && is_string($handler[0] ?? null)) {
            $handler = [new $handler[0](), $handler[1]];
        }

        if (!is_callable($handler)) {
            throw new RuntimeException('Handler de rota inválido.');
        }

        return (string) call_user_func_array($handler, array_values($parameters));
    }

    private function runMiddleware(string $definition, Request $request, Closure $next): string
    {
        [$alias, $rawParameters] = array_pad(explode(':', $definition, 2), 2, '');
        $class = $this->middlewareAliases[$alias] ?? null;
        if ($class === null) {
            throw new RuntimeException("Middleware não registrado: {$alias}");
        }

        $parameters = $rawParameters === '' ? [] : explode(',', $rawParameters);

        return (new $class())->handle($request, $next, ...$parameters);
    }

    private function compilePattern(string $path): string
    {
        $quoted = preg_quote($path, '#');
        $pattern = preg_replace('#\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}#', '(?P<$1>[^/]+)', $quoted);

        return '#^' . $pattern . '$#';
    }

    private function normalize(string $path): string
    {
        $normalized = '/' . trim($path, '/');

        return $normalized === '//' ? '/' : (rtrim($normalized, '/') ?: '/');
    }
}
