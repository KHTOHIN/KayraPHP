<?php

declare(strict_types=1);

namespace Kayra\Routing;

use InvalidArgumentException;
use Kayra\Encryption\Encrypter;

/**
 * Builds URLs from named routes.
 *
 * Generating URLs by name rather than by literal string is what lets paths
 * change without a project-wide find-and-replace.
 */
final class UrlGenerator
{
    public function __construct(
        private readonly RouteCollection $routes,
        private string $baseUrl = '',
        private readonly ?Encrypter $encrypter = null,
    ) {
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Build the path for a named route.
     *
     * Parameters not consumed by the path are appended as a query string.
     *
     * @param array<string, mixed> $parameters
     */
    public function route(string $name, array $parameters = [], bool $absolute = false): string
    {
        $route = $this->routes->getByName($name)
            ?? throw new InvalidArgumentException("Route [{$name}] is not defined.");

        $used = [];

        $path = preg_replace_callback(
            '/\{(\w+)(\??)(?::([^{}]*(?:\{(?-1)\}[^{}]*)*))?\}(\?)?/',
            function (array $m) use ($name, $parameters, &$used): string {
                $key = $m[1];
                $optional = ($m[2] ?? '') === '?' || ($m[4] ?? '') === '?';

                if (!array_key_exists($key, $parameters)) {
                    if ($optional) {
                        return '';
                    }

                    throw new InvalidArgumentException(
                        "Missing parameter [{$key}] for route [{$name}].",
                    );
                }

                $used[$key] = true;
                $value = $parameters[$key];

                if (!is_scalar($value) && !$value instanceof \Stringable) {
                    throw new InvalidArgumentException(
                        "Parameter [{$key}] for route [{$name}] must be scalar, "
                        . get_debug_type($value) . ' given.',
                    );
                }

                return rawurlencode((string) $value);
            },
            $route->uri,
        ) ?? $route->uri;

        // Optional trailing segments leave behind empty path pieces.
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        $path = $path === '' ? '/' : $path;

        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        $query = array_diff_key($parameters, $used);

        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return $absolute ? $this->baseUrl . $path : $path;
    }

    /**
     * Build a URL from a path, resolving it against the base URL.
     */
    public function to(string $path, bool $absolute = false): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            return $path;
        }

        $path = '/' . ltrim($path, '/');

        return $absolute ? $this->baseUrl . $path : $path;
    }

    public function has(string $name): bool
    {
        return $this->routes->getByName($name) !== null;
    }

    /* --------------------------------------------------------------------
     | Signed URLs
     * -------------------------------------------------------------------- */

    /**
     * Build a URL carrying a signature over its own contents.
     *
     * The point is to let a link be trusted without a session — password reset,
     * email verification, unsubscribe. The signature covers the full URL
     * including the expiry, so neither the target nor the deadline can be
     * edited without invalidating it.
     *
     * @param array<string, mixed> $parameters
     * @param int|null $expiresAt Unix timestamp; null for a link that never expires.
     */
    public function signedRoute(
        string $name,
        array $parameters = [],
        ?int $expiresAt = null,
        bool $absolute = true,
    ): string {
        $encrypter = $this->encrypter
            ?? throw new InvalidArgumentException(
                'Signed URLs require an encrypter. Set APP_KEY and run `kayra key:generate`.',
            );

        if ($expiresAt !== null) {
            $parameters['expires'] = $expiresAt;
        }

        // Sort so the signature does not depend on parameter order.
        ksort($parameters);

        $url = $this->route($name, $parameters, $absolute);
        $signature = $encrypter->sign($this->canonical($url));

        return $url . (str_contains($url, '?') ? '&' : '?') . 'signature=' . $signature;
    }

    /**
     * Verify a signed URL.
     *
     * Returns false for a bad signature and for an expired link; the caller
     * does not need to distinguish them, and telling them apart would leak
     * whether a given link ever existed.
     */
    public function hasValidSignature(string $url): bool
    {
        if ($this->encrypter === null) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        parse_str($parts['query'] ?? '', $query);

        $signature = $query['signature'] ?? null;

        if (!is_string($signature) || $signature === '') {
            return false;
        }

        unset($query['signature']);
        ksort($query);

        $expires = $query['expires'] ?? null;

        if ($expires !== null && (int) $expires < time()) {
            return false;
        }

        $rebuilt = ($parts['scheme'] ?? '') !== ''
            ? $parts['scheme'] . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '')
            : ($parts['path'] ?? '');

        if ($query !== []) {
            $rebuilt .= '?' . http_build_query($query);
        }

        return $this->encrypter->verify($this->canonical($rebuilt), $signature);
    }

    /**
     * Normalise a URL before signing so that trivially different encodings of
     * the same link do not produce different signatures.
     */
    private function canonical(string $url): string
    {
        return rtrim($url, '/');
    }
}
