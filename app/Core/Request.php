<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?self $current = null;

    /** @var array<string,mixed> */
    private array $attributes = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $post,
        private array $server,
        private array $files = []
    ) {
    }

    public static function capture(): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));

        $base = (string) Config::get('app.base_path', '');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return self::$current = new self($method, self::normalizePath($path), $_GET, $_POST, $server, $_FILES);
    }

    /** Build a request by hand (tests). */
    public function hasFiles(): bool
    {
        return $this->files !== [];
    }

    public static function make(string $method, string $path, array $post = [], array $query = [], array $server = [], array $files = []): self
    {
        return self::$current = new self(strtoupper($method), self::normalizePath($path), $query, $post, $server, $files);
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return preg_replace('#/{2,}#', '/', $path) ?? '/';
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Path plus query string, used to remember where a visitor was heading. */
    public function pathWithQuery(): string
    {
        return $this->query === [] ? $this->path : $this->path . '?' . http_build_query($this->query);
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** A query-string value as text; an array (?x[]=1) or a missing key gives $default. */
    public function queryStr(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? null;
        return is_scalar($value) ? (string) $value : $default;
    }

    /** A query-string value as a whole number; arrays and non-numeric text give $default. */
    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->query[$key] ?? null;
        return is_scalar($value) && filter_var((string) $value, FILTER_VALIDATE_INT) !== false ? (int) $value : $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function allPost(): array
    {
        return $this->post;
    }

    /** Trimmed string input; arrays and missing keys become ''. */
    public function str(string $key): string
    {
        $value = $this->input($key, '');
        return is_string($value) ? trim($value) : '';
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    public function host(): string
    {
        return strtolower((string) ($this->server['HTTP_HOST'] ?? ''));
    }

    public function ip(): string
    {
        // X-Forwarded-For is only honoured when the app is explicitly told it sits behind a trusted proxy.
        if (Config::get('app.trust_proxy', false)) {
            $forwarded = $this->header('X-Forwarded-For');
            if ($forwarded !== null) {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }
        if (Config::get('app.trust_proxy', false)) {
            return strtolower((string) $this->header('X-Forwarded-Proto')) === 'https';
        }
        return false;
    }

    public function wantsJson(): bool
    {
        return str_contains((string) $this->header('Accept'), 'application/json')
            || strtolower((string) $this->header('X-Requested-With')) === 'xmlhttprequest';
    }

    /**
     * One uploaded file as PHP reported it (name, tmp_name, size, error, type), or null if the field is absent
     * or holds several files.
     *
     * @return array{name:string,tmp_name:string,size:int,error:int,type:string}|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            return null;
        }
        return [
            'name' => (string) ($file['name'] ?? ''),
            'tmp_name' => (string) ($file['tmp_name'] ?? ''),
            'size' => (int) ($file['size'] ?? 0),
            'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
            'type' => (string) ($file['type'] ?? ''),
        ];
    }

    public function contentLength(): int
    {
        return (int) ($this->server['CONTENT_LENGTH'] ?? 0);
    }

    public function isMultipart(): bool
    {
        return str_starts_with(strtolower((string) ($this->server['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
