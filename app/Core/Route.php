<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\PermissionMiddleware;

/**
 * One registered route. Every route must declare its access rule explicitly
 * (public, authOnly or can) - the router refuses to run a route that does not.
 */
final class Route
{
    /** @var array<int,MiddlewareInterface|string> */
    public array $middleware = [];
    public ?string $permission = null;
    public bool $isPublic = false;
    public bool $isAuthOnly = false;
    public ?string $name = null;

    /**
     * @param array{0:class-string,1:string} $handler
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly array $handler
    ) {
    }

    /** Reachable without logging in (login page, health checks). */
    public function public(): self
    {
        $this->isPublic = true;
        return $this;
    }

    /** Any signed-in user; no specific permission needed (own account pages, logout). */
    public function authOnly(): self
    {
        $this->isAuthOnly = true;
        return $this;
    }

    /** Signed-in user holding this permission key. */
    public function can(string $permission): self
    {
        $this->permission = $permission;
        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function middleware(MiddlewareInterface|string ...$middleware): self
    {
        foreach ($middleware as $item) {
            $this->middleware[] = $item;
        }
        return $this;
    }

    public function hasAccessRule(): bool
    {
        return $this->isPublic || $this->isAuthOnly || $this->permission !== null;
    }

    /** Compiled regex for the path pattern. {id:int} matches digits, {slug} matches one segment. */
    public function regex(): string
    {
        $regex = preg_replace_callback(
            '/\{(\w+)(?::(int))?\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . (($m[2] ?? '') === 'int' ? '\d+' : '[^/]+') . ')',
            $this->pattern
        );
        return '#^' . $regex . '$#';
    }

    /**
     * Middleware that must run for this route, after global middleware.
     *
     * @return array<int,MiddlewareInterface|string>
     */
    public function pipeline(): array
    {
        $stack = $this->middleware;
        if (!$this->isPublic) {
            $stack[] = \App\Middleware\AuthMiddleware::class;
            if ($this->permission !== null) {
                $stack[] = new PermissionMiddleware($this->permission);
            }
        }
        return $stack;
    }
}
