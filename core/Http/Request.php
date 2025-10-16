<?php

namespace Kayra\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

class Request implements ServerRequestInterface
{
    protected string $method;
    protected UriInterface $uri;
    protected array $headers = [];
    protected StreamInterface $body;
    protected array $serverParams = [];
    protected array $queryParams = [];
    protected $parsedBody = null;
    protected array $uploadedFiles = [];
    protected array $cookies = [];
    protected array $attributes = [];
    protected ?string $requestTarget = null;
    protected string $protocolVersion = '1.1';

    /**
     * Factory for normal PHP (FPM/CLI)
     */
    public static function createFromGlobals(): self
    {
        $instance = new self();

        $instance->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $instance->uri = new Uri($_SERVER['REQUEST_URI'] ?? '/');
        $instance->serverParams = $_SERVER;
        $instance->queryParams = $_GET ?? [];
        $instance->parsedBody = $_POST ?? null;
        $instance->cookies = $_COOKIE ?? [];
        $instance->uploadedFiles = $_FILES ?? [];
        $instance->headers = self::normalizeHeaders(self::getAllHeadersSafe());
        $instance->body = new Stream(fopen('php://input', 'r'));

        return $instance;
    }

    /**
     * Factory for Swoole
     */
    public static function createFromSwoole(\Swoole\Http\Request $req): self
    {
        $instance = new self();

        $instance->method = $req->server['request_method'] ?? 'GET';
        $instance->uri = new Uri($req->server['request_uri'] ?? '/');
        $instance->serverParams = $req->server ?? [];
        $instance->queryParams = $req->get ?? [];
        $instance->parsedBody = $req->post ?? null;
        $instance->cookies = $req->cookie ?? [];
        $instance->uploadedFiles = $req->files ?? [];
        $instance->headers = self::normalizeHeaders($req->header ?? []);
        $instance->body = new Stream($req->rawContent());

        return $instance;
    }

    /* -------------------- PSR-7 Methods -------------------- */

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion($version): self
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
        $clone->headers[strtolower($name)] = (array)$value;
        return $clone;
    }

    public function withAddedHeader(string $name, $value): self
    {
        $clone = clone $this;
        $lname = strtolower($name);
        $clone->headers[$lname] = array_merge(
            $clone->headers[$lname] ?? [],
            (array)$value
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

    public function getRequestTarget(): string
    {
        return $this->requestTarget ?? $this->uri->getPath() ?: '/';
    }

    public function withRequestTarget($requestTarget): self
    {
        $clone = clone $this;
        $clone->requestTarget = $requestTarget;
        return $clone;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod($method): self
    {
        $clone = clone $this;
        $clone->method = strtoupper($method);
        return $clone;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, $preserveHost = false): self
    {
        $clone = clone $this;
        $clone->uri = $uri;
        return $clone;
    }

    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookies;
    }

    public function withCookieParams(array $cookies): self
    {
        $clone = clone $this;
        $clone->cookies = $cookies;
        return $clone;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $query): self
    {
        $clone = clone $this;
        $clone->queryParams = $query;
        return $clone;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles(array $uploadedFiles): self
    {
        $clone = clone $this;
        $clone->uploadedFiles = $uploadedFiles;
        return $clone;
    }

    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): self
    {
        $clone = clone $this;
        $clone->parsedBody = $data;
        return $clone;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute($name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute($name, $value): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    public function withoutAttribute($name): self
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);
        return $clone;
    }

    /* -------------------- Helpers -------------------- */

    protected static function getAllHeadersSafe(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $key = strtolower(str_replace('_', '-', substr($name, 5)));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    protected static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = (array)$value;
        }
        return $normalized;
    }
}