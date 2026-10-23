<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class Response
{
    /** @var array<string,string> */
    private array $headers;

    /** @var (callable():void)|null */
    private $stream = null;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(private string $body = '', private int $status = 200, array $headers = [])
    {
        $this->headers = $headers + ['Content-Type' => 'text/html; charset=UTF-8'];
    }

    /**
     * Redirect to an internal path. External URLs are refused to rule out open redirects.
     */
    public static function redirect(string $path, int $status = 302): self
    {
        if (!is_internal_path($path)) {
            throw new InvalidArgumentException('Redirects must target an internal path.');
        }
        return new self('', $status, ['Location' => url($path)]);
    }

    /**
     * A response whose body is produced while it is being sent (large exports), so memory stays flat.
     *
     * @param callable():void $producer echoes the body
     * @param array<string,string> $headers
     */
    public static function stream(callable $producer, array $headers = [], int $status = 200): self
    {
        $response = new self('', $status, $headers);
        $response->stream = $producer;
        return $response;
    }

    /**
     * @param array<mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** Full body as a string, running a streamed response's producer into a buffer (tests, small exports). */
    public function content(): string
    {
        if ($this->stream === null) {
            return $this->body;
        }
        ob_start();
        try {
            ($this->stream)();
        } finally {
            $captured = (string) ob_get_clean();
        }
        return $captured;
    }

    public function isStreamed(): bool
    {
        return $this->stream !== null;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function hasHeader(string $name): bool
    {
        foreach (array_keys($this->headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                return true;
            }
        }
        return false;
    }

    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function send(bool $headOnly = false): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . str_replace(["\r", "\n"], '', $value));
            }
        }
        if ($headOnly) {
            return;
        }
        if ($this->stream !== null) {
            ($this->stream)();
            return;
        }
        echo $this->body;
    }
}
