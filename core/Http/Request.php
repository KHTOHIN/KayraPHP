<?php

declare(strict_types=1);

namespace Kayra\Http;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-7 server request, plus the small set of conveniences an application
 * actually reaches for (input(), json(), isJson(), bearerToken(), ...).
 *
 * The conveniences are all read-only, so they cannot break PSR-7 immutability.
 */
final class Request implements ServerRequestInterface
{
    use MessageTrait {
        MessageTrait::withBody as private traitWithBody;
    }

    private string $method = 'GET';

    private UriInterface $uri;

    private ?string $requestTarget = null;

    /** @var array<string, mixed> */
    private array $serverParams = [];

    /** @var array<string, mixed> */
    private array $queryParams = [];

    /** @var array<string, mixed> */
    private array $cookieParams = [];

    /** @var array<array-key, UploadedFileInterface|array<array-key, mixed>> */
    private array $uploadedFiles = [];

    /** @var array<string, mixed>|object|null */
    private array|object|null $parsedBody = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** Memoised decoded JSON body. */
    private mixed $decodedJson = null;

    private bool $jsonDecoded = false;

    /**
     * Replacing the body must invalidate the decoded-JSON memo.
     *
     * Without this a clone keeps the parent's decoded value, so middleware that
     * rewrites the body would be read back as the original payload.
     */
    #[\NoDiscard('withBody() returns a new request; the receiver is unchanged')]
    public function withBody(StreamInterface $body): static
    {
        $clone = $this->traitWithBody($body);

        if ($clone !== $this) {
            $clone->jsonDecoded = false;
            $clone->decodedJson = null;
        }

        return $clone;
    }

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $serverParams
     */
    public function __construct(
        string $method = 'GET',
        UriInterface|string $uri = '/',
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
        array $serverParams = [],
    ) {
        $this->method = $this->normaliseMethod($method);
        $this->uri = is_string($uri) ? new Uri($uri) : $uri;
        $this->serverParams = $serverParams;
        $this->protocolVersion = $protocolVersion;
        $this->stream = $body;

        $this->setHeaders($headers);

        // PSR-7: a request must carry a Host header derived from the URI.
        if (!$this->hasHeader('Host') && $this->uri->getHost() !== '') {
            $this->headerNames['host'] = 'Host';
            $this->headers['Host'] = [$this->hostFromUri()];
        }
    }

    /* --------------------------------------------------------------------
     | Factories
     * -------------------------------------------------------------------- */

    /**
     * Build a request from PHP superglobals (php-fpm, mod_php, CLI server).
     */
    public static function fromGlobals(): self
    {
        $server = $_SERVER;

        $request = new self(
            method: is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET',
            uri: self::uriFromServer($server),
            headers: self::headersFromServer($server),
            body: Stream::fromFile('php://input', 'r'),
            protocolVersion: self::protocolFromServer($server),
            serverParams: $server,
        );

        return $request
            ->withQueryParams($_GET)
            ->withCookieParams($_COOKIE)
            ->withUploadedFiles(UploadedFile::normalize($_FILES))
            ->withParsedBody($_POST === [] ? null : $_POST);
    }

    /**
     * Build a request from a Swoole request object.
     *
     * @param object $swoole \Swoole\Http\Request
     */
    public static function fromSwoole(object $swoole): self
    {
        /** @var array<string, mixed> $server */
        $server = array_change_key_case((array) ($swoole->server ?? []), CASE_UPPER);
        /** @var array<string, string> $headers */
        $headers = (array) ($swoole->header ?? []);

        $host = $headers['host'] ?? ($server['SERVER_NAME'] ?? 'localhost');
        $scheme = ($server['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
        $target = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';

        $content = method_exists($swoole, 'rawContent') ? $swoole->rawContent() : '';

        $request = new self(
            method: is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET',
            uri: $scheme . '://' . $host . $target,
            headers: $headers,
            body: Stream::of(is_string($content) ? $content : ''),
            serverParams: $server,
        );

        return $request
            ->withQueryParams((array) ($swoole->get ?? []))
            ->withCookieParams((array) ($swoole->cookie ?? []))
            ->withUploadedFiles(UploadedFile::normalize((array) ($swoole->files ?? [])))
            ->withParsedBody(($swoole->post ?? null) ?: null);
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function uriFromServer(array $server): string
    {
        $scheme = 'http';

        if (($server['HTTPS'] ?? '') !== '' && strtolower((string) $server['HTTPS']) !== 'off') {
            $scheme = 'https';
        } elseif ((int) ($server['SERVER_PORT'] ?? 0) === 443) {
            $scheme = 'https';
        }

        $host = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost';
        $host = is_string($host) ? $host : 'localhost';

        // Strip a port already present in Host so it is not duplicated.
        if (!str_contains($host, ':') && isset($server['SERVER_PORT'])) {
            $port = (int) $server['SERVER_PORT'];

            if ($port !== 80 && $port !== 443) {
                $host .= ':' . $port;
            }
        }

        $target = $server['REQUEST_URI'] ?? '/';

        return $scheme . '://' . $host . (is_string($target) ? $target : '/');
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = self::headerName(substr($key, 5));
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = self::headerName($key);
            } else {
                continue;
            }

            $headers[$name] = (string) $value;
        }

        // Authorization is stripped by some SAPI configurations.
        if (!isset($headers['Authorization'])) {
            $fallback = $server['REDIRECT_HTTP_AUTHORIZATION'] ?? $server['HTTP_AUTHORIZATION'] ?? null;

            if (is_string($fallback)) {
                $headers['Authorization'] = $fallback;
            }
        }

        return $headers;
    }

    private static function headerName(string $key): string
    {
        return str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function protocolFromServer(array $server): string
    {
        $protocol = $server['SERVER_PROTOCOL'] ?? 'HTTP/1.1';

        return is_string($protocol) && str_contains($protocol, '/')
            ? explode('/', $protocol, 2)[1]
            : '1.1';
    }

    /* --------------------------------------------------------------------
     | PSR-7: request line
     * -------------------------------------------------------------------- */

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();

        if ($target === '') {
            $target = '/';
        }

        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    #[\NoDiscard('withRequestTarget() returns a new request; the receiver is unchanged')]
    public function withRequestTarget(string $requestTarget): static
    {
        if (preg_match('/\s/', $requestTarget) === 1) {
            throw new InvalidArgumentException('Request target cannot contain whitespace.');
        }

        $clone = clone $this;
        $clone->requestTarget = $requestTarget;

        return $clone;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    #[\NoDiscard('withMethod() returns a new request; the receiver is unchanged')]
    public function withMethod(string $method): static
    {
        $clone = clone $this;
        $clone->method = $this->normaliseMethod($method);

        return $clone;
    }

    /**
     * Validate a method without changing it.
     *
     * PSR-7 requires the method to be preserved exactly as supplied — it is
     * case-sensitive, and custom methods may legitimately be lower-case. The
     * router upper-cases for matching instead, so routing stays convenient
     * without the message lying about what the client sent.
     */
    private function normaliseMethod(string $method): string
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $method) !== 1) {
            throw new InvalidArgumentException("[{$method}] is not a valid HTTP method.");
        }

        return $method;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    #[\NoDiscard('withUri() returns a new request; the receiver is unchanged')]
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        if ($uri === $this->uri) {
            return $this;
        }

        $clone = clone $this;
        $clone->uri = $uri;

        if ($preserveHost && $this->hasHeader('Host')) {
            return $clone;
        }

        if ($uri->getHost() === '') {
            return $clone;
        }

        // Replace the Host header, preserving its position at the front.
        unset($clone->headers[$clone->headerNames['host'] ?? 'Host'], $clone->headerNames['host']);

        $clone->headerNames = ['host' => 'Host'] + $clone->headerNames;
        $clone->headers = ['Host' => [$clone->hostFromUri()]] + $clone->headers;

        return $clone;
    }

    private function hostFromUri(): string
    {
        $host = $this->uri->getHost();
        $port = $this->uri->getPort();

        return $port === null ? $host : $host . ':' . $port;
    }

    /* --------------------------------------------------------------------
     | PSR-7: server request
     * -------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    #[\NoDiscard('withCookieParams() returns a new request; the receiver is unchanged')]
    public function withCookieParams(array $cookies): static
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    #[\NoDiscard('withQueryParams() returns a new request; the receiver is unchanged')]
    public function withQueryParams(array $query): static
    {
        $clone = clone $this;
        $clone->queryParams = $query;

        return $clone;
    }

    /**
     * @return array<array-key, UploadedFileInterface|array<array-key, mixed>>
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    #[\NoDiscard('withUploadedFiles() returns a new request; the receiver is unchanged')]
    public function withUploadedFiles(array $uploadedFiles): static
    {
        $clone = clone $this;
        $clone->uploadedFiles = $uploadedFiles;

        return $clone;
    }

    /**
     * @return array<string, mixed>|object|null
     */
    public function getParsedBody(): array|object|null
    {
        return $this->parsedBody;
    }

    #[\NoDiscard('withParsedBody() returns a new request; the receiver is unchanged')]
    public function withParsedBody(mixed $data): static
    {
        if ($data !== null && !is_array($data) && !is_object($data)) {
            throw new InvalidArgumentException('Parsed body must be an array, object or null.');
        }

        $clone = clone $this;
        $clone->parsedBody = $data;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    #[\NoDiscard('withAttribute() returns a new request; the receiver is unchanged')]
    public function withAttribute(string $name, mixed $value): static
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    #[\NoDiscard('withoutAttribute() returns a new request; the receiver is unchanged')]
    public function withoutAttribute(string $name): static
    {
        if (!array_key_exists($name, $this->attributes)) {
            return $this;
        }

        $clone = clone $this;
        unset($clone->attributes[$name]);

        return $clone;
    }

    /* --------------------------------------------------------------------
     | Conveniences (read-only)
     * -------------------------------------------------------------------- */

    /**
     * The URL path, always starting with a slash.
     */
    public function path(): string
    {
        $path = $this->uri->getPath();

        return $path === '' ? '/' : $path;
    }

    /**
     * A route parameter captured by the router.
     */
    public function route(string $name, mixed $default = null): mixed
    {
        $parameters = $this->getAttribute('route.parameters', []);

        return is_array($parameters) ? ($parameters[$name] ?? $default) : $default;
    }

    /**
     * Read from the query string.
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->queryParams;
        }

        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Read a value from the request body (form or JSON).
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        $body = $this->isJson() ? $this->json() : $this->parsedBody;

        if (is_object($body)) {
            $body = get_object_vars($body);
        }

        if (!is_array($body)) {
            return $key === null ? [] : $default;
        }

        return $key === null ? $body : ($body[$key] ?? $default);
    }

    /**
     * Read from body first, then query string.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $value = $this->post($key);

        return $value ?? ($this->queryParams[$key] ?? $default);
    }

    /**
     * Every input value: query string merged with body (body wins).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $body = $this->post();

        return array_merge($this->queryParams, is_array($body) ? $body : []);
    }

    public function has(string $key): bool
    {
        return $this->input($key) !== null;
    }

    /**
     * Only the given keys, skipping any that are absent.
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $value = $this->input($key);

            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function cookie(string $name, mixed $default = null): mixed
    {
        return $this->cookieParams[$name] ?? $default;
    }

    public function file(string $name): ?UploadedFileInterface
    {
        $file = $this->uploadedFiles[$name] ?? null;

        return $file instanceof UploadedFileInterface ? $file : null;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->getHeaderLine('Content-Type')), 'json');
    }

    /**
     * Whether the client prefers a JSON response.
     */
    public function wantsJson(): bool
    {
        $accept = strtolower($this->getHeaderLine('Accept'));

        return $this->isJson()
            || str_contains($accept, 'application/json')
            || str_contains($accept, '+json')
            || strtolower($this->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    /**
     * The decoded JSON body, or null when the body is absent or malformed.
     *
     * Decoding happens once and is memoised; the body stream is rewound so later
     * readers still see it.
     */
    public function json(): mixed
    {
        if ($this->jsonDecoded) {
            return $this->decodedJson;
        }

        $this->jsonDecoded = true;

        $body = $this->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $contents = $body->getContents();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if (trim($contents) === '') {
            return $this->decodedJson = null;
        }

        try {
            return $this->decodedJson = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->decodedJson = null;
        }
    }

    /**
     * The bearer token from the Authorization header, if present.
     */
    public function bearerToken(): ?string
    {
        $header = $this->getHeaderLine('Authorization');

        if (stripos($header, 'bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    public function isMethod(string $method): bool
    {
        return strcasecmp($this->method, $method) === 0;
    }

    public function isSecure(): bool
    {
        return $this->uri->getScheme() === 'https';
    }

    /**
     * The client IP address as reported by the SAPI.
     *
     * Deliberately ignores X-Forwarded-For: that header is trivially spoofed and
     * must only be honoured behind a proxy you control. Use the TrustProxies
     * middleware to opt in.
     */
    public function ip(): ?string
    {
        $ip = $this->serverParams['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : null;
    }
}
