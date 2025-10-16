<?php

namespace Kayra\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class Response implements ResponseInterface
{
    protected int $statusCode = 200;
    protected array $headers = [];
    protected StreamInterface $body;
    protected string $reasonPhrase = 'OK';
    protected string $protocolVersion = '1.1';

    public function __construct(
        int $statusCode = 200,
        array $headers = [],
        ?StreamInterface $body = null
    ) {
        $this->statusCode = $statusCode;
        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $body ?? new Stream(fopen('php://temp', 'r+'));
    }

    /* ---------- Factory Methods ---------- */

    public static function create(int $status = 200, array $headers = [], string $body = ''): self
    {
        $stream = new Stream(fopen('php://temp', 'r+'));
        if ($body !== '') {
            $stream->write($body);
            $stream->rewind();
        }
        return new self($status, $headers ?: ['Content-Type' => ['text/html']], $stream);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return self::create($status, ['Content-Type' => ['application/json']], $body);
    }

    public function view(string $view, array $data = []): self
    {
        // Convert dot notation like "home.index" → "home/index"
        $viewPath = str_replace('.', DIRECTORY_SEPARATOR, $view);

        // Build absolute path using base_path() helper
        $file = base_path("app/Views/{$viewPath}.php");

        // Check if view file exists
        if (!file_exists($file)) {
            $bodyContent = is_dev()
                ? "⚠️ View not found: {$file}"
                : "500 Internal Server Error";

            return self::create(404, ['Content-Type' => ['text/plain']], $bodyContent);
        }

        // Safely include view
        ob_start();
        extract($data, EXTR_SKIP);
        include $file;
        $bodyContent = ob_get_clean();

        return self::create(200, ['Content-Type' => ['text/html']], $bodyContent);
    }

    /* ---------- PSR-7 Interface Methods ---------- */

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): self
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;
        return $clone;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): self
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = (array) $value;
        return $clone;
    }

    public function withAddedHeader(string $name, $value): self
    {
        $clone = clone $this;
        $lname = strtolower($name);
        $clone->headers[$lname] = array_merge(
            $clone->headers[$lname] ?? [],
            (array) $value
        );
        return $clone;
    }

    public function withoutHeader(string $name): self
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): self
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = $reasonPhrase ?: $clone->reasonPhrase;
        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /* ---------- Sending Methods ---------- */

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $values) {
                header($this->formatHeaderName($name) . ': ' . implode(', ', $values));
            }
        }
        echo (string) $this->body;
    }

    public function sendToSwoole(\Swoole\Http\Response $swoole): void
    {
        $swoole->status($this->statusCode);
        foreach ($this->headers as $name => $values) {
            $swoole->header($this->formatHeaderName($name), implode(', ', $values));
        }
        $swoole->end((string) $this->body);
    }

    /* ---------- Helpers ---------- */

    protected function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = (array) $value;
        }
        return $normalized;
    }

    protected function formatHeaderName(string $name): string
    {
        return ucwords(str_replace('-', ' ', $name));
    }
}