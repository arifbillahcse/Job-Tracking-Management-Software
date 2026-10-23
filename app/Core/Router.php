<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;

final class Router
{
    /** @var array<int,Route> */
    private array $routes = [];

    /** @var array<int,MiddlewareInterface|string> */
    private array $globalMiddleware = [];

    /** @var array<int,array{prefix:string,middleware:array<int,MiddlewareInterface|string>}> */
    private array $groups = [];

    public function middleware(MiddlewareInterface|string ...$middleware): void
    {
        foreach ($middleware as $item) {
            $this->globalMiddleware[] = $item;
        }
    }

    /**
     * @param array{prefix?:string,middleware?:array<int,MiddlewareInterface|string>} $attributes
     */
    public function group(array $attributes, callable $callback): void
    {
        $this->groups[] = [
            'prefix' => (string) ($attributes['prefix'] ?? ''),
            'middleware' => $attributes['middleware'] ?? [],
        ];
        $callback($this);
        array_pop($this->groups);
    }

    /**
     * @param array{0:class-string,1:string} $handler
     */
    public function get(string $pattern, array $handler): Route
    {
        return $this->add('GET', $pattern, $handler);
    }

    /**
     * @param array{0:class-string,1:string} $handler
     */
    public function post(string $pattern, array $handler): Route
    {
        return $this->add('POST', $pattern, $handler);
    }

    /**
     * @param array{0:class-string,1:string} $handler
     */
    public function add(string $method, string $pattern, array $handler): Route
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groups as $group) {
            $prefix .= $group['prefix'];
            array_push($middleware, ...$group['middleware']);
        }

        $full = '/' . trim($prefix . '/' . trim($pattern, '/'), '/');
        $route = new Route($method, $full === '' ? '/' : $full, $handler);
        $route->middleware(...$middleware);
        return $this->routes[] = $route;
    }

    /**
     * @return array<int,Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $allowed = [];
        $matched = null;
        $params = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route->regex(), $request->path(), $found)) {
                continue;
            }
            if ($route->method === $method) {
                $matched = $route;
                $params = array_filter($found, 'is_string', ARRAY_FILTER_USE_KEY);
                break;
            }
            $allowed[] = $route->method;
        }

        if ($matched === null) {
            $core = $allowed !== []
                ? static fn (Request $r): Response => ErrorPages::response(405)->header('Allow', implode(', ', array_unique($allowed)))
                : static fn (Request $r): Response => ErrorPages::response(404);
            return $this->run($core, $this->globalMiddleware, $request);
        }

        if (!$matched->hasAccessRule()) {
            throw new LogicException(sprintf(
                'Route %s %s has no access rule. Declare ->public(), ->authOnly() or ->can(permission).',
                $matched->method,
                $matched->pattern
            ));
        }

        $request->setAttribute('route', $matched);

        $core = function (Request $r) use ($matched, $params): Response {
            return $this->invoke($matched, $r, $params);
        };

        return $this->run($core, [...$this->globalMiddleware, ...$matched->pipeline()], $request);
    }

    /**
     * @param callable(Request):Response $core
     * @param array<int,MiddlewareInterface|string> $middleware
     */
    private function run(callable $core, array $middleware, Request $request): Response
    {
        $next = $core;
        foreach (array_reverse($middleware) as $item) {
            $instance = is_string($item) ? new $item() : $item;
            $inner = $next;
            $next = static fn (Request $r): Response => $instance->handle($r, $inner);
        }
        return $next($request);
    }

    /**
     * @param array<string,string> $params
     */
    private function invoke(Route $route, Request $request, array $params): Response
    {
        [$class, $method] = $route->handler;
        $controller = new $class();

        $args = [];
        foreach ($params as $name => $value) {
            $args[] = ctype_digit($value) && str_contains($route->pattern, '{' . $name . ':int}') ? (int) $value : $value;
        }

        $result = $controller->$method($request, ...$args);

        return match (true) {
            $result instanceof Response => $result,
            is_array($result) => Response::json($result),
            default => new Response((string) $result),
        };
    }
}
