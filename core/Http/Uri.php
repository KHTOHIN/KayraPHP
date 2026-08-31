<?php

declare(strict_types=1);

namespace Kayra\Http;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/**
 * PSR-7 URI.
 *
 * Parsing is a strict pass followed by a lenient one:
 *
 *  1. ext/uri (PHP 8.5) parses to the letter of RFC 3986.
 *  2. parse_url() handles what it rejects.
 *
 * The second pass is not legacy support — it is required. ext/uri correctly
 * refuses request targets that real clients nonetheless send: raw spaces,
 * un-encoded UTF-8 paths, `{}`, `|`, `^`, `\` and malformed `%zz` sequences.
 * Without the fallback those requests would raise an exception instead of
 * reaching the router and returning an honest 404.
 */
final class Uri implements UriInterface
{
    /** Default ports that PSR-7 requires to be omitted from the authority. */
    private const STANDARD_PORTS = [
        'http'  => 80,
        'https' => 443,
        'ftp'   => 21,
        'ws'    => 80,
        'wss'   => 443,
    ];

    private const UNRESERVED = 'a-zA-Z0-9_\-\.~';
    private const SUB_DELIMS = '!\$&\'\(\)\*\+,;=';

    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    /** Memoised __toString result; cleared by every wither. */
    private ?string $cache = null;

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }

        $parts = self::parse($uri);

        $this->scheme   = isset($parts['scheme']) ? $this->normaliseScheme($parts['scheme']) : '';
        $this->host     = isset($parts['host']) ? $this->normaliseHost($parts['host']) : '';
        $this->port     = isset($parts['port']) ? $this->normalisePort((int) $parts['port']) : null;
        $this->path     = isset($parts['path']) ? $this->encodePath($parts['path']) : '';
        $this->query    = isset($parts['query']) ? $this->encodeQueryOrFragment($parts['query']) : '';
        $this->fragment = isset($parts['fragment']) ? $this->encodeQueryOrFragment($parts['fragment']) : '';

        $userInfo = $parts['user'] ?? '';

        if ($userInfo !== '' && isset($parts['pass'])) {
            $userInfo .= ':' . $parts['pass'];
        }

        $this->userInfo = $userInfo;
    }

    /**
     * Whether the native URI extension is being used.
     *
     * Surfaced by `kayra doctor` so the active fast paths are visible.
     */
    public static function usingNativeParser(): bool
    {
        return class_exists(\Uri\Rfc3986\Uri::class);
    }

    /**
     * @return array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}
     */
    private static function parse(string $uri): array
    {
        if (self::usingNativeParser()) {
            $native = \Uri\Rfc3986\Uri::parse($uri);

            if ($native !== null) {
                $parts = [];

                foreach (
                    [
                        'scheme'   => $native->getScheme(),
                        'host'     => $native->getHost(),
                        'path'     => $native->getRawPath(),
                        'query'    => $native->getRawQuery(),
                        'fragment' => $native->getRawFragment(),
                        'user'     => $native->getRawUsername(),
                        'pass'     => $native->getRawPassword(),
                    ] as $key => $value
                ) {
                    if ($value !== null && $value !== '') {
                        $parts[$key] = $value;
                    }
                }

                $port = $native->getPort();

                if ($port !== null) {
                    $parts['port'] = $port;
                }

                return $parts;
            }

            // Rejected by the strict parser — fall through to the lenient one
            // rather than failing the request outright.
        }

        $parts = parse_url($uri);

        if ($parts === false) {
            throw new InvalidArgumentException("Unable to parse URI [{$uri}].");
        }

        return $parts;
    }

    /* --------------------------------------------------------------------
     | Accessors
     * -------------------------------------------------------------------- */

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;

        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }

        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * The path, with multiple leading slashes collapsed to one.
     *
     * PSR-7 requires this as a security control: a consumer that puts the path
     * straight into a Location header would otherwise emit `//evil.test/...`,
     * which a browser reads as a protocol-relative URL and follows off-origin.
     *
     * The raw form is preserved internally so that {@see __toString()} still
     * round-trips the URI exactly — when an authority is present, leading
     * slashes in the path are unambiguous and must not be lost.
     */
    public function getPath(): string
    {
        return str_starts_with($this->path, '//')
            ? '/' . ltrim($this->path, '/')
            : $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    /* --------------------------------------------------------------------
     | Withers
     * -------------------------------------------------------------------- */

    #[\NoDiscard('withScheme() returns a new Uri; the receiver is unchanged')]
    public function withScheme(string $scheme): UriInterface
    {
        $scheme = $this->normaliseScheme($scheme);

        if ($scheme === $this->scheme) {
            return $this;
        }

        $clone = clone $this;
        $clone->scheme = $scheme;
        $clone->port = $clone->normalisePort($clone->port);
        $clone->cache = null;

        return $clone;
    }

    #[\NoDiscard('withUserInfo() returns a new Uri; the receiver is unchanged')]
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $info = $this->encodeUserInfo($user);

        if ($info !== '' && $password !== null && $password !== '') {
            $info .= ':' . $this->encodeUserInfo($password);
        }

        if ($info === $this->userInfo) {
            return $this;
        }

        $clone = clone $this;
        $clone->userInfo = $info;
        $clone->cache = null;

        return $clone;
    }

    #[\NoDiscard('withHost() returns a new Uri; the receiver is unchanged')]
    public function withHost(string $host): UriInterface
    {
        $host = $this->normaliseHost($host);

        if ($host === $this->host) {
            return $this;
        }

        $clone = clone $this;
        $clone->host = $host;
        $clone->cache = null;

        return $clone;
    }

    #[\NoDiscard('withPort() returns a new Uri; the receiver is unchanged')]
    public function withPort(?int $port): UriInterface
    {
        $port = $this->normalisePort($port);

        if ($port === $this->port) {
            return $this;
        }

        $clone = clone $this;
        $clone->port = $port;
        $clone->cache = null;

        return $clone;
    }

    #[\NoDiscard('withPath() returns a new Uri; the receiver is unchanged')]
    public function withPath(string $path): UriInterface
    {
        $path = $this->encodePath($path);

        if ($path === $this->path) {
            return $this;
        }

        $clone = clone $this;
        $clone->path = $path;
        $clone->cache = null;

        return $clone;
    }

    #[\NoDiscard('withQuery() returns a new Uri; the receiver is unchanged')]
    public function withQuery(string $query): UriInterface
    {
        $query = $this->encodeQueryOrFragment(ltrim($query, '?'));

        if ($query === $this->query) {
            return $this;
        }

        $clone = clone $this;
        $clone->query = $query;
        $clone->cache = null;

        return $clone;
    }

    #[\NoDiscard('withFragment() returns a new Uri; the receiver is unchanged')]
    public function withFragment(string $fragment): UriInterface
    {
        $fragment = $this->encodeQueryOrFragment(ltrim($fragment, '#'));

        if ($fragment === $this->fragment) {
            return $this;
        }

        $clone = clone $this;
        $clone->fragment = $fragment;
        $clone->cache = null;

        return $clone;
    }

    /* --------------------------------------------------------------------
     | Normalisation
     * -------------------------------------------------------------------- */

    private function normaliseScheme(string $scheme): string
    {
        $scheme = strtolower(rtrim($scheme, ':'));

        if ($scheme !== '' && preg_match('/^[a-z][a-z0-9+\-.]*$/', $scheme) !== 1) {
            throw new InvalidArgumentException("Invalid URI scheme [{$scheme}].");
        }

        return $scheme;
    }

    private function normaliseHost(string $host): string
    {
        return strtolower($host);
    }

    /**
     * Drop the port when it is the default for the current scheme, as PSR-7 requires.
     */
    private function normalisePort(?int $port): ?int
    {
        if ($port === null) {
            return null;
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Invalid URI port [{$port}]; must be between 1 and 65535.");
        }

        return (self::STANDARD_PORTS[$this->scheme] ?? null) === $port ? null : $port;
    }

    /**
     * Percent-encode a path while leaving already-encoded triplets intact.
     *
     * The path is stored as given; leading-slash collapsing happens on read in
     * {@see getPath()} so the string representation stays lossless.
     */
    private function encodePath(string $path): string
    {
        return $this->encode($path, self::UNRESERVED . self::SUB_DELIMS . '%:@\/');
    }

    private function encodeQueryOrFragment(string $value): string
    {
        return $this->encode($value, self::UNRESERVED . self::SUB_DELIMS . '%:@\/\?');
    }

    private function encodeUserInfo(string $value): string
    {
        return $this->encode($value, self::UNRESERVED . self::SUB_DELIMS . '%');
    }

    private function encode(string $value, string $allowed): string
    {
        if ($value === '') {
            return '';
        }

        return preg_replace_callback(
            '/(?:[^' . $allowed . ']++|%(?![A-Fa-f0-9]{2}))/',
            static fn (array $m): string => rawurlencode($m[0]),
            $value,
        ) ?? $value;
    }

    public function __toString(): string
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        $authority = $this->getAuthority();

        if ($authority !== '') {
            $uri .= '//' . $authority;
        }

        $path = $this->path;

        if ($path !== '') {
            if ($authority !== '' && !str_starts_with($path, '/')) {
                // A rootless path cannot follow an authority.
                $path = '/' . $path;
            } elseif ($authority === '' && str_starts_with($path, '//')) {
                // "//path" would be read as an authority.
                $path = '/' . ltrim($path, '/');
            }
        }

        $uri .= $path;

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $this->cache = $uri;
    }
}
