<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @param array<string, string> $routeParameters */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $routeParameters,
    ) {
    }

    /** @param array<string, string> $routeParameters */
    public static function capture(string $method, string $path, array $routeParameters = []): self
    {
        return new self($method, $path, $routeParameters);
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function input(string $key, mixed $default = null): mixed { return $_POST[$key] ?? $_GET[$key] ?? $default; }
    public function route(string $key, mixed $default = null): mixed { return $this->routeParameters[$key] ?? $default; }
    public function isMethod(string $method): bool { return $this->method === strtoupper($method); }
}
