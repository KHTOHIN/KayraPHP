<?php

declare(strict_types=1);

namespace Kayra\Http;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Stringable;

/**
 * PSR-7 response.
 *
 * All the `with*()` methods carry `#[\NoDiscard]` (PHP 8.5), so discarding the
 * return value is reported by the engine instead of silently doing nothing —
 * the single most common PSR-7 mistake.
 */
final class Response implements ResponseInterface
{
    use MessageTrait;

    /** @var array<int, string> */
    public const PHRASES = [
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing', 103 => 'Early Hints',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information',
        204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content', 207 => 'Multi-Status',
        208 => 'Already Reported', 226 => 'IM Used',
        300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
        304 => 'Not Modified', 305 => 'Use Proxy', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required', 408 => 'Request Timeout', 409 => 'Conflict',
        410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Content Too Large',
        414 => 'URI Too Long', 415 => 'Unsupported Media Type', 416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed', 418 => "I'm a teapot", 421 => 'Misdirected Request',
        422 => 'Unprocessable Content', 423 => 'Locked', 424 => 'Failed Dependency',
        425 => 'Too Early', 426 => 'Upgrade Required', 428 => 'Precondition Required',
        429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates', 507 => 'Insufficient Storage', 508 => 'Loop Detected',
        510 => 'Not Extended', 511 => 'Network Authentication Required',
    ];

    private int $statusCode = 200;

    private string $reasonPhrase = '';

    /** @var list<string> Raw Set-Cookie lines, kept out of the header bag until send time. */
    private array $cookies = [];

    /**
     * @param array<string, mixed> $headers
     */
    public function __construct(
        int $status = 200,
        array $headers = [],
        StreamInterface|string|null $body = null,
        string $protocolVersion = '1.1',
        string $reason = '',
    ) {
        $this->statusCode = $this->assertStatusCode($status);
        $this->reasonPhrase = $reason !== '' ? $reason : (self::PHRASES[$this->statusCode] ?? '');
        $this->protocolVersion = $protocolVersion;
        $this->stream = is_string($body) ? Stream::of($body) : $body;

        $this->setHeaders($headers);
    }

    /* --------------------------------------------------------------------
     | Named constructors
     |
     | These are constructors, never mutators: each one returns a brand new
     | response and never reads the state of an existing one.
     * -------------------------------------------------------------------- */

    public static function html(string|Stringable $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], (string) $html);
    }

    public static function text(string|Stringable $text, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], (string) $text);
    }

    /**
     * @param int $flags JSON_* flags; JSON_THROW_ON_ERROR is always added.
     */
    public static function json(mixed $data, int $status = 200, int $flags = 0): self
    {
        try {
            $encoded = json_encode($data, $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("Unable to encode response as JSON: {$e->getMessage()}", 0, $e);
        }

        return new self($status, ['Content-Type' => 'application/json; charset=utf-8'], $encoded);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    /**
     * @param int $status 302 by default; use 301 for permanent moves.
     */
    public static function redirect(string $location, int $status = 302): self
    {
        if (preg_match('/[\r\n\0]/', $location) === 1) {
            throw new InvalidArgumentException('Redirect location contains a CR, LF or NUL byte.');
        }

        return new self($status, ['Location' => $location]);
    }

    /**
     * Stream a file inline (rendered by the browser where possible).
     */
    public static function file(string $path, ?string $contentType = null): self
    {
        return self::fileResponse($path, $contentType, null);
    }

    /**
     * Stream a file as an attachment (offered as a download).
     */
    public static function download(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        return self::fileResponse($path, $contentType, $filename ?? basename($path));
    }

    private static function fileResponse(string $path, ?string $contentType, ?string $downloadName): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("File [{$path}] does not exist or is not readable.");
        }

        $headers = [
            'Content-Type'   => $contentType ?? self::guessContentType($path),
            'Content-Length' => (string) (filesize($path) ?: 0),
        ];

        if ($downloadName !== null) {
            // Strip anything that could break out of the quoted filename.
            $safe = preg_replace('/[^\w.\- ]/', '_', basename($downloadName)) ?? 'download';

            $headers['Content-Disposition'] = sprintf(
                'attachment; filename="%s"; filename*=UTF-8\'\'%s',
                $safe,
                rawurlencode(basename($downloadName)),
            );
        }

        return new self(200, $headers, Stream::fromFile($path, 'r'));
    }

    private static function guessContentType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'html', 'htm' => 'text/html; charset=utf-8',
            'css'         => 'text/css; charset=utf-8',
            'js', 'mjs'   => 'text/javascript; charset=utf-8',
            'json'        => 'application/json',
            'svg'         => 'image/svg+xml',
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'avif'        => 'image/avif',
            'ico'         => 'image/x-icon',
            'woff2'       => 'font/woff2',
            'woff'        => 'font/woff',
            'pdf'         => 'application/pdf',
            'txt', 'md'   => 'text/plain; charset=utf-8',
            'xml'         => 'application/xml',
            'zip'         => 'application/zip',
            'mp4'         => 'video/mp4',
            'webm'        => 'video/webm',
            default       => 'application/octet-stream',
        };
    }

    /* --------------------------------------------------------------------
     | PSR-7
     * -------------------------------------------------------------------- */

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    #[\NoDiscard('withStatus() returns a new response; the receiver is unchanged')]
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $code = $this->assertStatusCode($code);

        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : (self::PHRASES[$code] ?? '');

        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    private function assertStatusCode(int $code): int
    {
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException("[{$code}] is not a valid HTTP status code.");
        }

        return $code;
    }

    /* --------------------------------------------------------------------
     | Cookies
     * -------------------------------------------------------------------- */

    /**
     * Queue a cookie on the response.
     *
     * Defaults are the secure ones: HttpOnly on, SameSite=Lax, and Secure
     * whenever a path is served over TLS.
     */
    #[\NoDiscard('withCookie() returns a new response; the receiver is unchanged')]
    public function withCookie(
        string $name,
        string $value,
        int $expiresAt = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): static {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
            throw new InvalidArgumentException("[{$name}] is not a valid cookie name.");
        }

        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException("Invalid SameSite value [{$sameSite}].");
        }

        $parts = [$name . '=' . rawurlencode($value)];

        if ($expiresAt > 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $expiresAt);
            $parts[] = 'Max-Age=' . max(0, $expiresAt - time());
        }

        if ($path !== '') {
            $parts[] = 'Path=' . $path;
        }

        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }

        if ($secure) {
            $parts[] = 'Secure';
        }

        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $sameSite;

        $clone = clone $this;
        $clone->cookies[] = implode('; ', $parts);

        return $clone;
    }

    #[\NoDiscard('withoutCookie() returns a new response; the receiver is unchanged')]
    public function withoutCookie(string $name, string $path = '/', string $domain = ''): static
    {
        return $this->withCookie($name, '', 1, $path, $domain);
    }

    /**
     * @return list<string>
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Every header line to emit, including queued cookies.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function headerLines(): array
    {
        $lines = [];

        foreach ($this->headers as $name => $values) {
            foreach ($values as $value) {
                $lines[] = [$name, $value];
            }
        }

        foreach ($this->cookies as $cookie) {
            $lines[] = ['Set-Cookie', $cookie];
        }

        return $lines;
    }
}
